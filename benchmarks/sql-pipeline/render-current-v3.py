"""Render 3.0.0 versus the tested current 4.x, retaining primary and traced series."""
import argparse
import gzip
import hashlib
import json
import pathlib

parser = argparse.ArgumentParser()
parser.add_argument('--raw', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
raw = pathlib.Path(args.raw).read_bytes()
report = json.loads(raw)
assert report['ok']
output = pathlib.Path(args.output)
compact = {k: v for k, v in report.items() if k != 'cases'}
compact['raw_sha256'] = hashlib.sha256(raw).hexdigest()
compact['cases'] = {}
tables = {'structured': [], 'scalar': []}
stages, builds = [], []
for name, case in report['cases'].items():
    compact['cases'][name] = {k: v for k, v in case.items() if k != 'phases'}
    compact['cases'][name]['phases'] = {
        mode: {k: v for k, v in phase.items() if k != 'samples'}
        for mode, phase in case['phases'].items()}
    fixture, verb, size = name.split('_')
    plain = case['phases']['plain']
    before, after = [plain['summary'][v]['total_ms'] for v in ['v3', 'v4']]
    tables[fixture].append(f'| {verb.upper()}, {size} | {before:.3f} | {after:.3f} | '
        f'{(after/before-1)*100:+.2f}% | {plain["paired_difference_ms"]:+.3f} |')
    if size == '500':
        for label in ['v3', 'v4']:
            p = case['phases']['live']['summary'][label]
            stages.append(f'| {fixture}, {verb.upper()} | {label} | {p["before_pdo_ms"]:.3f} | '
                f'{p["binding_ms"]:.3f} | {p["execute_ms"]:.3f} | {p["total_ms"]:.3f} | {p["sql_count"]:.0f} |')
        b = case['phases']['build']['summary']
        builds.append(f'| {fixture}, {verb.upper()} | {b["v3"]["before_pdo_ms"]:.3f} | {b["v4"]["before_pdo_ms"]:.3f} |')
(output / 'current-v3.raw.json.gz').write_bytes(gzip.compress(raw, mtime=0))
(output / 'current-v3.summary.json').write_text(json.dumps(compact, indent=2), encoding='utf-8')
header = '''| Операция, документов | 3.0.0, мс | Текущая 4.x, мс | Разница к 3.x | Парная разница, мс |
| --- | ---: | ---: | ---: | ---: |
'''
gaps = []
for fixture in ['structured', 'scalar']:
    for verb in ['insert', 'replace']:
        p = report['cases'][f'{fixture}_{verb}_500']['phases']['plain']['summary']
        gaps.append((p['v4']['total_ms'] / p['v3']['total_ms'] - 1) * 100)
(output / 'current-v3.md').write_text('''# Текущие INSERT/REPLACE в сравнении с 3.x

27 октября 2026 года, PHP 8.3.6 / Laravel 12.70.1 / Manticore 29.9.0, локальный TCP.
Опубликованные 3.0.0 + manticore-query-builder-php 2.6.0 сравниваются с текущими
исходниками 4.x после переноса плана кодирования колонок.
Снимок 4.x соответствует [проверенной реализации](encoding-plan-current.validation.json);
SHA-256 всех файлов сохранены в сводке.

''' + f'''На пакетах из 500 документов текущая 4.x требует на {min(gaps):.1f}–{max(gaps):.1f}% больше полного
времени, чем 3.x, для проверенных INSERT/REPLACE и обоих наборов полей.
''' + '''

В реальных запросах с JSON/MVA подготовка до PDO почти сравнялась; оставшаяся разница
лежит преимущественно в привязке параметров и выполнении. Для скалярных полей в 4.x
также остаётся небольшая дополнительная стоимость до PDO. Одиночные записи имеют
другое соотношение затрат, в том числе из-за получения insert IDs в 3.x.

## Полное время записи

Основные серии используют обычные PDO/PDOStatement, без обёрток и таймеров по этапам.
Положительный процент означает, что 4.x затратила больше времени, отрицательный — меньше.
Парная разница — медиана разниц 4.x минус 3.x в соседних измерениях, с чередованием порядка.

### Семь полей, включая JSON/MVA

Float-цена, JSON payload 128 байт, MVA из двух чисел. Данные одинаковы для обеих версий.

''' + header + '\n'.join(tables['structured']) + '''

### Пять скалярных полей

Те же документы без колонок details и tags в записываемом payload.

''' + header + '\n'.join(tables['scalar']) + '''

## Этапы записи 500 документов

Отдельная инструментированная серия. До PDO включает все проверки, кодирование
и построение SQL/bindings перед первым обращением к PDO; схема и соединение прогреты.
Bind — интервал между prepare и execute, Execute включает PDO, сеть и сервер,
а также дополнительные SQL-запросы библиотеки. Это не чистое время работы Manticore.
Медианы этапов могут не складываться в медиану полного времени.

| Поля и операция | Версия | До PDO, мс | Bind, мс | Execute, мс | Всего, мс | SQL |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
''' + '\n'.join(stages) + '''

### Подготовка без выполнения

Отдельная серия останавливает запрос исключением при первом входе в PDO, до выполнения.
Показано время до этой границы, без раскрутки исключения. Каждый раунд содержит
10 повторов, всего 100 измерений после прогрева. Плотный цикл без сетевых ожиданий
даёт другие абсолютные времена, чем подготовка внутри реального запроса.

| Поля и операция, 500 документов | 3.x, мс | 4.x, мс |
| --- | ---: | ---: |
''' + '\n'.join(builds) + f'''

## Методика и ограничения

- {report['samples']} измерений полной операции и {report['profile_samples']} измерений реальных запросов с таймерами
  по этапам; перед каждой серией пять прогревов. Порядок версий меняется в каждом раунде.
- Stock ManticoreConnection, ManticoreQueryBuilder и ManticoreGrammar в 4.x.
  Подклассы билдера/Grammar не используются: они отключили бы пакетную быструю компиляцию.
- PHP, Laravel и {len(report['shared_dependencies'])} общих production-зависимостей имеют одинаковые версии.
  Точные зависимости, снимки исходников и пути реально загруженных классов сохранены.
  [Проверка исходников после прогона](current-v3.validation.json) также сверяет source/dist
  references общих зависимостей и фактическое количество SQL-запросов и bindings.
- В обоих драйверах отключены query log/логгер и диспетчер событий. Xdebug выключен;
  prepared statements эмулируются, буферизация включена.
- TRUNCATE перед INSERT и заполнение исходных документов перед REPLACE вынесены за таймер.
  Создание данных и проверка результата тоже исключены из замера.
- Нормализованные значения всех записанных документов совпадают между версиями
  после каждой измеренной записи, включая пакеты из 1000 строк.
- 3.x выполняет INSERT/REPLACE и SELECT LAST_INSERT_ID(), 4.x для обычного insert/replace
  выполняет только запись. Это сравнение полного штатного поведения публичных методов.
- SQL и число bindings у версий различаются по устройству драйвера. Сохранённые хеши
  SQL, размеры и количество параметров помогают объяснить разницу; равенство SQL не требуется.
- Только INSERT/REPLACE, один клиент и данный набор данных. Это не оценка SELECT,
  максимальной конкурентной пропускной способности или гарантированный процент для всех схем.
- [Сохранность приложения и очистка таблиц](current-v3.environment.json).

## Воспроизведение

    python3 compare-current-v3.py --v3 /path/to/benchmark-v3 --v4 /path/to/benchmark-v4 \\
      --source /path/to/current-source --output /path/to/raw.json \\
      --samples 60 --profile-samples 20 --sizes 1 100 500 1000
    python3 render-current-v3.py --raw /path/to/raw.json --output .

Нужны worker.php, trace.php, probes-v4.php и соседний каталог compare-versions;
сервер и приложения описаны в [README](README.md).

[Сводка, зависимости и манифесты](current-v3.summary.json),
[исходные измерения](current-v3.raw.json.gz).
''', encoding='utf-8')
