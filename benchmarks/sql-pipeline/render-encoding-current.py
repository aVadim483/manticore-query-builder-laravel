"""Archive final implementation measurements independently of the prototype results."""
import argparse
import gzip
import hashlib
import json
import pathlib

parser = argparse.ArgumentParser()
parser.add_argument('--raw', required=True)
parser.add_argument('--checks', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
raw = pathlib.Path(args.raw).read_bytes()
report = json.loads(raw)
checks = json.loads(pathlib.Path(args.checks).read_text())
assert report['ok'] and checks['ok'] and report['labels'] == ['baseline', 'current']
assert len(checks['servers']) == 2
output = pathlib.Path(args.output)
summary = {k: v for k, v in report.items() if k != 'cases'}
summary['raw_sha256'] = hashlib.sha256(raw).hexdigest()
summary['cases'] = {}
tables = {'structured': [], 'scalar': []}
stages = []
for name, case in report['cases'].items():
    assert len(set(case['sql_bindings_sha256'].values())) == 1
    summary['cases'][name] = {k: v for k, v in case.items() if k != 'phases'}
    summary['cases'][name]['phases'] = {
        mode: {k: v for k, v in phase.items() if k != 'samples'}
        for mode, phase in case['phases'].items()}
    plain = case['phases']['plain']['summary']
    before, after = plain['baseline']['total_ms'], plain['current']['total_ms']
    paired = case['phases']['plain']['paired_difference_ms']['current']
    fixture, verb, size = name.split('_')
    tables[fixture].append(f'| {verb.upper()}, {size} | {before:.3f} | {after:.3f} | '
        f'{(after/before-1)*100:+.2f}% | {paired:+.3f} |')
    if size == '500':
        profile = case['phases']['profile']['summary']
        before, after = profile['baseline']['encode_ms'], profile['current']['encode_ms']
        stages.append(f'| {fixture}, {verb.upper()} | {before:.3f} | {after:.3f} | {(after/before-1)*100:+.2f}% |')
(output / 'encoding-plan-current.raw.json.gz').write_bytes(gzip.compress(raw, mtime=0))
(output / 'encoding-plan-current.summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
(output / 'encoding-plan-current.checks.json').write_text(json.dumps(checks, indent=2), encoding='utf-8')
header = '''| Операция, документов | До | После | Изменение | Медиана парной разницы |
| --- | ---: | ---: | ---: | ---: |
'''
gains = []
for fixture in ['structured', 'scalar']:
    for verb in ['insert', 'replace']:
        plain = report['cases'][f'{fixture}_{verb}_500']['phases']['plain']['summary']
        gains.append((1 - plain['current']['total_ms'] / plain['baseline']['total_ms']) * 100)
(output / 'encoding-plan-current.md').write_text('''# Проверка перенесённого плана кодирования колонок

Оптимизация перенесена из [прототипа](encoding-plan.md) в основную библиотеку.
База — 77e2f81; итоговые исходники зафиксированы SHA-256 в сводке и проверке матрицы.
Дата: 26 октября 2026 года.

AttributeCodec использует общие обработчики для одиночной и пакетной записи.
Одиночная запись вызывает их напрямую, без создания Closure; пакет выбирает обработчик
один раз при первом обращении к колонке. Валидация и кодирование не дублируются.
План локален одному вызову, учитывает схему после beforeQuery и не сохраняется
между таблицами или запросами. Пользовательский Connection сохраняет прежний путь
с чтением метаданных для каждого значения, защищённый encodeWriteRows сохраняется.
SQL-компилятор и работа с bindings не изменены.

## Повторный замер итогового кода

''' + f'''Для пакетов по 500 документов полное время записи сократилось на {min(gains):.1f}–{max(gains):.1f}%.
''' + '''
PHP 8.3.6 / Laravel 12.70.1 / Manticore 29.9.0, локальный TCP.
Штатные классы драйвера и обычные PDO/PDOStatement, без инструментирующих подклассов.
Установленные vendor не менялись: старый и новый код загружаются из отдельных снимков.
Медианы в миллисекундах; отрицательная разница означает сокращение времени.

### Полная запись с JSON/MVA

Семь полей, включая float, JSON с payload из 128 байт и MVA из двух элементов.

''' + header + '\n'.join(tables['structured']) + '''

### Полная запись скалярных полей

Пять полей без JSON/MVA; остальные данные и код выполнения те же.

''' + header + '\n'.join(tables['scalar']) + '''

### Отдельно encodeWriteRows(), пакет из 500 документов

Отдельная серия с двумя hrtime и finally внутри метода. Её полное время не подменяет
основной замер без инструментирования.

| Поля и операция | До | После | Изменение |
| --- | ---: | ---: | ---: |
''' + '\n'.join(stages) + f'''

## Проверки

- По {report['samples']} полных измерений и {report['profile_samples']} измерений профилирования каждого варианта
  после пяти прогревов. Порядок вариантов чередуется в каждом раунде. Создание плана
  входит в таймер; очистка/заполнение таблиц и сверка данных находятся вне таймера.
- В каждом сценарии до замеров совпали SQL, типы и значения bindings, параметры execute
  и записанные данные. Хеш данных проверяется после каждой измеренной операции.
- Старая AttributeCodec::encode(), итоговая encode() и подготовленные обработчики проверены
  в отдельных процессах на одинаковых 6000 входах. Совпали хеши всех результатов и
  исключений, включая 3484 ошибочных входа: три набора по 6000 результатов.
- По 47 сценариев записи старого и нового драйвера на Manticore 28.6.6 и 29.9.0:
  данные, SQL/bindings, события, логи, ошибки, callbacks, наследование, транзакции,
  pretend и ленивое соединение. Различий нет.
- Полная матрица PHP/Laravel, production-only smoke, API-контракты и хеши проверенных
  файлов сохранены в [проверке реализации](encoding-plan-current.validation.json).
- [Окружение после прогона](encoding-plan-current.environment.json): vendor и данные
  приложения проверки миграции сверены с исходным манифестом/отпечатком.

Восемь новых регрессионных тестов проверяют план на штатном Connection, смену схемы
и таблицы, callbacks, cast_attributes=false/pretend, ссылки, наследуемый хук,
пользовательские метаданные, Expression и точные ошибки валидации.

Результаты относятся к этому стенду и данным. Особенно маленькие изменения одиночной
записи нужно оценивать в абсолютных миллисекундах с учётом разброса.
На двух документах кодирование стало дороже примерно на 0,005 мс: создание плана
может перевесить экономию повторного выбора типа. Это несущественно для полного
времени запроса на данном стенде, но ускорение не гарантируется для каждого размера пакета.

## Воспроизведение

    python3 prepare-encoding-current.py --baseline /path/to/77e2f81-source \\
      --current /path/to/current-source --output /path/to/new-variants
    python3 encoding-plan.py --app /path/to/benchmark-v4 --variants /path/to/new-variants \\
      --labels baseline current --samples 60 --profile-samples 20 --sizes 1 2 10 100 500 1000 \\
      --output /path/to/raw.json
    python3 check-encoding-plan.py --app /path/to/benchmark-v4 --variants /path/to/new-variants \\
      --labels baseline current --ports 19306 19506 --output /path/to/checks.json
    python3 render-encoding-current.py --raw /path/to/raw.json --checks /path/to/checks.json --output .

Дополнительно нужны соседние worker.php, trace.php, check-encoding-plan.php,
check-literal-writes.php и каталог compare-versions; схемы серверов описаны
в [общем README](README.md).

[Сводка, манифесты и парные разницы](encoding-plan-current.summary.json),
[исходные замеры](encoding-plan-current.raw.json.gz),
[проверки записи и кодека](encoding-plan-current.checks.json).
''', encoding='utf-8')
