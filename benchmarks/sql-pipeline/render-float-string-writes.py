"""Save the focused A/B comparison and compatibility checks as reviewable artifacts."""
import argparse
import gzip
import hashlib
import json
import pathlib
import statistics
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--app', required=True)
parser.add_argument('--timing', required=True)
parser.add_argument('--profile', required=True)
parser.add_argument('--source', required=True)
parser.add_argument('--candidate', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
output = pathlib.Path(args.output)
output.mkdir(parents=True, exist_ok=True)
raw = pathlib.Path(args.timing).read_bytes()
profile_raw = pathlib.Path(args.profile).read_bytes()
timing = json.loads(raw)
profile = json.loads(profile_raw)
assert timing['ok'] and profile['ok'] and not timing['profile'] and profile['profile']
assert timing['source_manifests'] == profile['source_manifests']
checks = {}
for label, source in [('expression', args.source), ('float_string', args.candidate)]:
    value = json.loads(subprocess.check_output([str(pathlib.Path(args.app) / 'bin/php'),
        str(pathlib.Path(__file__).resolve().parent / 'check-variants.php'), args.app, source], text=True))
    assert value['ok'] and all(write['ok'] for write in value['writes'].values())
    checks[label] = value
assert checks['expression']['writes'] == checks['float_string']['writes']
assert checks['expression']['scalar_null'] == checks['float_string']['scalar_null']
assert checks['expression']['cold_schema_listener'] == checks['float_string']['cold_schema_listener']

def n(value): return f'{value:.3f}'.replace('.', ',')
rows = []
paired = {}
for name, case in timing['cases'].items():
    a = case['summary']['expression']['median_ms']
    b = case['summary']['float_string']['median_ms']
    differences = [bv['total_ms'] - av['total_ms'] for av, bv in zip(case['samples']['expression'], case['samples']['float_string'])]
    paired[name] = statistics.median(differences)
    rows.append(f'| {name.replace("_", " ")} | {n(a)} | {n(b)} | {n((b/a-1)*100)}% | {n(paired[name])} |')
stage_rows = []
for stage in ['builder.encodeWriteRows', 'grammar.compileInsert', 'builder.cleanBindings',
              'numeric.prepare', 'connection.prepareBindings', 'connection.bindValues', 'pdo.execute']:
    a = profile['cases']['insert_500']['summary']['expression']['stages'][stage]['exclusive_ms']
    b = profile['cases']['insert_500']['summary']['float_string']['stages'][stage]['exclusive_ms']
    stage_rows.append(f'| {stage} | {n(a)} | {n(b)} |')
summary = {k: v for k, v in timing.items() if k != 'cases'}
summary['cases'] = {name: {'summary': value['summary'], 'digest': value['digest'],
    'paired_median_difference_ms': paired[name]} for name, value in timing['cases'].items()}
summary['profile_cases'] = {name: {'summary': value['summary'], 'digest': value['digest']}
                           for name, value in profile['cases'].items()}
summary['raw_sha256'] = hashlib.sha256(raw).hexdigest()
summary['profile_raw_sha256'] = hashlib.sha256(profile_raw).hexdigest()
(output / 'float-string-writes.summary.json').write_text(json.dumps(summary, indent=2))
(output / 'float-string-writes.raw.json.gz').write_bytes(gzip.compress(raw, mtime=0))
(output / 'float-string-writes.profile.raw.json.gz').write_bytes(gzip.compress(profile_raw, mtime=0))
(output / 'float-string-writes.checks.json').write_text(json.dumps(checks, indent=2, ensure_ascii=False))
(output / 'float-string-writes.md').write_text(f'''# Строковые float против Expression в INSERT/REPLACE

26 октября 2026 года. Отдельное A/B-сравнение двух снимков текущей 4.x после оптимизации
float: Expression с числовым литералом и строковый PDO-параметр. Разница кода — одна строка
в encodeInsertRows: результат того же json_encode возвращается строкой без new Expression.
Проверка конечности числа, преобразование по схеме, JSON, MVA, векторы, UPDATE, WHERE
и raw SQL сохраняют исходную реализацию. PHP 8.3.6, Laravel 12.70.1, Manticore 29.9.0,
локальный TCP, emulated prepares; отдельные воркеры с проверкой пути и SHA-256 загруженного класса.

## Полное время записи

По {timing['samples']} измерений после пяти прогревов, порядок клиентов чередуется.
Таймеры отдельных этапов выключены. Те же семь полей, одна float-цена на документ,
JSON с payload 128 байт и MVA. Схема прогрета, TRUNCATE перед INSERT и проверка данных
исключены из времени. REPLACE перезаписывает существующие документы. Во всех запусках
хеши сохранённых полей совпали. Положительная разница означает, что строковый вариант дольше.

| Операция и число строк | Expression, мс | Строка, мс | Разница медиан | Медиана парной разницы, мс |
| --- | ---: | ---: | ---: | ---: |
{chr(10).join(rows)}

Парная разница рассчитывается между двумя измерениями одного раунда; она помогает
увидеть влияние колебаний времени, которое может менять отношение независимых медиан.
На коротких операциях и при разнице в несколько процентов одного прогона недостаточно
для обещания устойчивого выигрыша.

## Почему отсутствие Expression не гарантирует ускорения

На 500 документах Expression-вариант передаёт
{timing['environment']['expression']['bindings_for_500']} параметров PDO, строковый —
{timing['environment']['float_string']['bindings_for_500']}. Объекты float исчезают, но
числа теперь проходят cleanBindings, prepareBindings, bindValue, экранирование при эмуляции
и строковое преобразование на стороне Manticore. Превращение float в строку не устраняет
кодирование JSON/MVA и остальные проходы подготовки записи.

Отдельный профиль: {profile['samples']} измерений после пяти прогревов. Собственное время этапа
INSERT 500, без повторного сложения вложенных вызовов:

| Этап | Expression, мс | Строка, мс |
| --- | ---: | ---: |
{chr(10).join(stage_rows)}

## Корректность и границы вывода

Строковые float допустимы для обычных скалярных INSERT/REPLACE — прежние
[проверки протокола](../../docs/ru/float-string-bindings.md) это уже показывали.
Дополнительно на обоих снимках совпали результаты четырёх терминальных методов записи
с Unicode, JSON-скалярами, пустыми MVA, bool, большими ID и float в экспоненциальной форме;
совпали вызовы encodeWriteRows и холодные события загрузки схемы. Отклонение скалярного
SQL NULL сервером также совпало. Эти проверки не разрешают переносить строковый подход
на UPDATE, числовые фильтры, арифметику или компоненты векторов.

Данный эксперимент не даёт основания считать отказ от Expression самостоятельным
ускорением всей записи. Решение нужно принимать по полному времени конкретного пакета,
а не по числу созданных PHP-объектов. Рабочий драйвер не изменён.

## Воспроизведение и данные

float-string-writes.py использует ../profile-writes/worker.php и его fixtures/probes.
Основные параметры: --app, --source, --candidate (новый каталог), --worker, --output,
--samples 50; для отдельного профиля --samples 10 --profile.
render-float-string-writes.py получает --app, --source, --candidate, --timing, --profile,
--output и запускает дополнительные проверки через check-variants.php.

[Сводка и манифесты](float-string-writes.summary.json),
[все измерения](float-string-writes.raw.json.gz),
[профиль](float-string-writes.profile.raw.json.gz),
[проверки](float-string-writes.checks.json).
SHA-256 JSON до сжатия: {hashlib.sha256(raw).hexdigest()};
профиля: {hashlib.sha256(profile_raw).hexdigest()}.
''', encoding='utf-8')
print(json.dumps({'ok': True, 'paired_median_differences': paired}))
