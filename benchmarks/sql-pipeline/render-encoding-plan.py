"""Archive raw samples and render the column-plan experiment."""
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
assert report['ok'] and checks['ok']
assert len(report['cases']) == 12 and len(checks['servers']) == 2
output = pathlib.Path(args.output)
summary = {k: v for k, v in report.items() if k != 'cases'}
summary['raw_sha256'] = hashlib.sha256(raw).hexdigest()
summary['cases'] = {}
rows, stages = [], []
for name, case in report['cases'].items():
    compact = {'digest': case['digest'], 'phases': {}}
    for mode, phase in case['phases'].items():
        compact['phases'][mode] = {k: v for k, v in phase.items() if k != 'samples'}
    summary['cases'][name] = compact
    plain = case['phases']['plain']['summary']
    baseline = plain['baseline']['total_ms']
    a, b = plain['closures']['total_ms'], plain['kinds']['total_ms']
    rows.append(f'| {name.replace("_", " ")} | {baseline:.3f} | {a:.3f} | {(a/baseline-1)*100:+.2f}% | '
                f'{b:.3f} | {(b/baseline-1)*100:+.2f}% |')
    if name.endswith('_500'):
        profile = case['phases']['profile']['summary']
        before, after = profile['baseline']['encode_ms'], profile['closures']['encode_ms']
        stages.append(f'| {name.replace("_", " ")} | {before:.3f} | {after:.3f} | {(after/before-1)*100:+.2f}% |')
(output / 'encoding-plan.raw.json.gz').write_bytes(gzip.compress(raw, mtime=0))
(output / 'encoding-plan.summary.json').write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding='utf-8')
(output / 'encoding-plan.checks.json').write_text(json.dumps(checks, indent=2, ensure_ascii=False), encoding='utf-8')
(output / 'encoding-plan.md').write_text('''# План кодирования колонок для пакетных INSERT/REPLACE

База: **77e2f81**, после оптимизации пакетной компиляции и cleanBindings().
26 октября 2026 года; PHP 8.3.6, Laravel 12.70.1, Manticore 29.9.0, локальный TCP.
Сравниваются текущая 4.x и два изолированных прототипа.

Гипотеза подтвердилась на проверенном наборе: выбор обработчика один раз на колонку
сократил время кодирования и полное время INSERT/REPLACE. На пакетах из 500 документов
вариант с обработчиками дал больший выигрыш, чем вариант с числовыми кодами типов. Это небольшой дополнительный
выигрыш поверх уже реализованной пакетной оптимизации; результаты не означают
такого же ускорения всей библиотеки или любых данных.

## Что изменялось

Текущий код уже получает схему один раз перед обходом пакета. Эксперимент устраняет
повторное чтение типа колонки из схемы и выбор ветки кодека для каждой ячейки.

- **closures:** локальная карта колонка → обработчик скаляра, JSON, timestamp, MVA или вектора.
  Используются first-class callable приватных статических методов, выбранные при первом
  обращении к колонке. MVA и векторы имеют отдельные циклы валидации.
- **kinds:** локальная карта колонка → числовой код типа и целочисленный switch
  внутри encodeKind(), без дополнительного вызова отдельного обработчика.

План создаётся заново для каждого вызова, его создание входит в измерение. Между запросами
схема/обработчики дополнительно не кешируются. Прототипы сохраняют отдельный проход
кодирования и прежний компилятор SQL/bindings. План включается для пакетов от двух
строк со штатным классом ManticoreConnection; единичная запись и пользовательский
Connection используют прежний путь. Защищённый encodeWriteRows() и beforeQuery сохраняются.

## Полная операция

Медианы, миллисекунды. Отрицательный процент означает сокращение времени относительно базы.
Structured: 7 полей, включая float, JSON с payload 128 байт и MVA из двух элементов.
Scalar: 5 полей без JSON/MVA. Названия включают число документов в пакете.

| Операция | База | Обработчики | Изменение | Коды типов | Изменение |
| --- | ---: | ---: | ---: | ---: | ---: |
''' + '\n'.join(rows) + '''

## Отдельно кодирование пакета

Медианы encodeWriteRows(), миллисекунды, пакет из 500 документов.
Измеряются в отдельной серии с двумя hrtime и finally в исходном методе,
без подмены класса билдера: наследование отключило бы штатную быструю компиляцию.
Эта серия не используется как основной замер полной операции.

| Операция | База | Обработчики | Изменение |
| --- | ---: | ---: | ---: |
''' + '\n'.join(stages) + f'''

## Методика и проверки

- По {report['samples']} измерений каждого полного сценария и {report['profile_samples']} измерений профилирования,
  плюс 5 прогревов; порядок трёх вариантов циклически меняется в каждом раунде.
- Штатные ManticoreConnection, ManticoreQueryBuilder и ManticoreGrammar, обычные PDO/PDOStatement.
  Трассировка SQL работает только в отдельных процессах захвата.
- Подготовка/очистка таблиц и проверка записанных документов выполняются вне таймера.
  Схема и соединение прогреты. Таблицы и данные синтетические.
- Во всех 12 сценариях совпали SQL, значения и типы bindings, параметры execute и хеши данных.
  Хеш результата сверяется после каждой измеренной записи.
- Для каждого варианта выполнено 6000 сравнений типов/значений против неизменённого
  AttributeCodec::encode(), включая 3484 случая с совпадающими исключениями.
  Это полный перебор фиксированного набора типов и значений и повторяемая случайная выборка.
  В набор входят Expression, JSON, DateTime, MVA64 с точными строковыми integer,
  векторы, INF/NAN, повреждённый UTF-8 и недопустимые структурированные значения.
- На Manticore 28.6.6 и 29.9.0 сравниваются по 47 сценариев записи для базы и двух
  вариантов: данные, SQL, bindings, события, query log, ошибки, callbacks, наследование,
  транзакции, pretend и ленивое соединение. Различий не обнаружено.

Это исследование на PHP 8.3/Laravel 12. Перед переносом в основную библиотеку
потребуются оформление кодека без дублирования, регрессионные тесты пакетного плана
и проверка поддерживаемой матрицы PHP/Laravel. Исходники src/, установленные vendor
и публичное приложение этим экспериментом не меняются.

## Воспроизведение

    python3 make-encoding-plan.py --source /path/to/77e2f81-source --output /path/to/new-variants
    python3 encoding-plan.py --app /path/to/benchmark-v4 --variants /path/to/new-variants \\
      --output /path/to/raw.json --samples 60 --profile-samples 20 --sizes 100 500 1000
    python3 check-encoding-plan.py --app /path/to/benchmark-v4 --variants /path/to/new-variants \\
      --ports 19306 19506 --output /path/to/checks.json
    python3 render-encoding-plan.py --raw /path/to/raw.json --checks /path/to/checks.json --output .

Нужны также worker.php, trace.php, check-encoding-plan.php, check-literal-writes.php
и соседний каталог compare-versions. Для 19506 предварительно запускается совместимый
тестовый Manticore. Снимок источников содержит composer.json и src/ и не меняется во время прогона.

[Сводка и манифесты](encoding-plan.summary.json),
[исходные замеры](encoding-plan.raw.json.gz),
[проверки совместимости](encoding-plan.checks.json),
[проверка окружения после эксперимента](encoding-plan.environment.json).
''', encoding='utf-8')
