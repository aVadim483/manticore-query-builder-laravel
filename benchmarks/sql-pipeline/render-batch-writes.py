"""Render the implemented bulk-write comparison, preserving complete measurements."""
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
data = json.loads(raw)
checks = json.loads(pathlib.Path(args.checks).read_text())
assert data['ok'] and checks['ok'] and len(data['cases']) == 8
core = 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
assert data['manifests']['after'][core] == checks['after']['native']['source_sha256']
output = pathlib.Path(args.output)
output.mkdir(parents=True, exist_ok=True)
def n(value): return f'{value:.2f}'.replace('.', ',')
rows = []
stages = []
for name, case in data['cases'].items():
    plain = case['phases']['plain']['summary']
    before = plain['before']['total_ms']
    after = plain['after']['total_ms']
    rows.append(f'| {name.replace("_", " ")} | {n(plain["v3"]["total_ms"])} | {n(before)} | {n(after)} | '
                f'{n(plain["fallback"]["total_ms"])} | {n((1-after/before)*100)}% |')
    if name.endswith('_500'):
        live = case['phases']['live']['summary']
        for label in ['v3', 'before', 'after', 'fallback']:
            s = live[label]
            stages.append(f'| {name.replace("_", " ")} | {label} | {n(s["before_pdo_ms"])} | {n(s["binding_ms"])} | '
                          f'{n(s["execute_ms"])} | {n(s["total_ms"])} |')
summary = {k: v for k, v in data.items() if k != 'cases'}
summary['raw_sha256'] = hashlib.sha256(raw).hexdigest()
summary['cases'] = {name: {'captures': case['captures'], 'matching_digest': case['matching_digest'],
    'phases': {phase: {'summary': value['summary']} for phase, value in case['phases'].items()}}
    for name, case in data['cases'].items()}
(output / 'batch-writes.summary.json').write_text(json.dumps(summary, indent=2))
(output / 'batch-writes.raw.json.gz').write_bytes(gzip.compress(raw, mtime=0))
(output / 'batch-writes.checks.json').write_text(json.dumps(checks, indent=2, ensure_ascii=False))
(output / 'batch-writes.md').write_text(f'''# Реализованная подготовка пакетного INSERT/REPLACE

26 октября 2026 года. Сравниваются опубликованная 3.0.0, исходники 4.x коммита 1f7da31
с уже оптимизированными float и текущая реализация пакетной подготовки. PHP 8.3.6,
Laravel 12.70.1, Manticore 29.9.0, локальный TCP; эмуляция prepared statements и
буферизация включены. Query log, Xdebug и диспетчер событий в замерах отключены.
Общие зависимости приложений те же, что в [анализе этапов SQL](results-2026-10-26.md).

## Изменение реализации

* cleanBindings использует один цикл вместо Collection::reject/map/values/all;
  вызов castBinding и плотные индексы результата сохранены.
* Для двух и более строк штатные ManticoreQueryBuilder и ManticoreGrammar сначала
  вызывают прежний encodeWriteRows, затем совместно собирают SQL и bindings. Порядок
  колонок сортируется один раз; имена одинаково расположенных колонок повторно не проверяются.
  После кодирования по схеме конечные scalar float сразу форматируются числовыми литералами,
  без промежуточных Expression. Строки продолжают передаваться параметрами PDO.
* Подклассы билдера/Grammar, пользовательские и вложенные Expression, смена Grammar и
  callbacks, зарегистрированные при загрузке схемы, сохраняют прежний путь. Ссылочные
  значения фиксируются в том же порядке относительно выполнения пользовательского кода.
* Одиночные строки и GetId-терминалы сохраняют существующую подготовку. Транзакции,
  события, выполнение соединением, кодирование JSON/MVA/векторов и публичные сигнатуры сохранены.
  Алгоритмы SELECT не изменены.

## Полное время записи

По {data['samples']} измерений после пяти прогревов, клиенты чередуются. Семь полей,
одна float-цена, JSON payload 128 байт и MVA из двух чисел. TRUNCATE перед INSERT,
предварительное заполнение REPLACE, создание fixture и проверка данных исключены из таймера.
Результат проверяется по всем записанным строкам, включая пакет из 1000 документов.

Таблица использует отдельный режим plain с отключённой трассировкой. Штатный путь измерен
со штатной Grammar, а не с её инструментированным подклассом. Путь и SHA-256 реально
загруженного билдера и класс Grammar проверяются при запуске. В fallback используется
подкласс Grammar: там работают новые cleanBindings и проверка колонок, но сохраняется
generic-компиляция. Совпадение SQL, значений bindings и их PDO-типов проверено до серий;
все нормализованные сохранённые документы совпали у всех четырёх клиентов.

| Операция и число строк | 3.0.0, мс | До изменения, мс | После, мс | Fallback, мс | Снижение времени к прежней 4.x |
| --- | ---: | ---: | ---: | ---: | ---: |
{chr(10).join(rows)}

Изменение нескольких процентов на короткой одиночной записи не является гарантией
устойчивого выигрыша. Это задержка одного клиента на данном наборе, не измерение
конкурентной пропускной способности или гарантия для всех схем.

## Этапы реальной записи 500 документов

Отдельная серия live с таймерами, все времена в миллисекундах. До PDO включает проверки,
кодирование, компиляцию и формирование bindings. Привязка — интервал от возврата prepare
до входа execute. Execute включает работу PDO при эмуляции, сеть, сервер и дополнительные
SQL вызовы библиотеки; это не чистое время CPU Manticore. Медианы этапов могут не складываться
в медиану полного времени. Для оценки общего ускорения используется таблица plain выше.

| Операция | Вариант | До PDO | Привязка | Execute/query | Полное время с трассировкой |
| --- | --- | ---: | ---: | ---: | ---: |
{chr(10).join(stages)}

Сухие серии подготовки build также сохранены в данных: по 100 подготовок на вариант
и сценарий, с остановкой до первого вызова PDO и проверкой полученного SQL. Их абсолютные
времена относятся к последовательному циклу без выполнения и не подставляются вместо
времени подготовки реального запроса.

## Проверки и воспроизведение

Отдельно сравнивались штатные builder и подклассы до/после: четыре терминала записи,
большие ID, Unicode, JSON-скаляры, пустые MVA, bool, float и обработчики холодной схемы.
Выполнены 4000 проверок cleanBindings и 4000 проверок представления KNN-векторов
без изменения алгоритмов SELECT. Состояние данных и вызовы защищённого кодирования совпали.
Unit/integration-тесты дополнительно проверяют смешанный порядок колонок, отключение casts,
нечисловые/неполные наборы колонок, INF/NAN, префиксы, custom/nested Expression,
пользовательские Grammar, поздние callbacks и ссылочные значения.

[Полная матрица PHP/Laravel/Manticore](batch-writes.validation.json),
[проверки реальных данных](batch-writes.checks.json),
[сохранность приложения и очистка](batch-writes.environment.json).

```sh
python3 batch-writes.py --v3 /path/to/v3-app --v4 /path/to/v4-app \\
  --before /path/to/source-before --after /path/to/source-after \\
  --output /path/to/batch-results.json --samples 40
python3 check-batch-writes.py --app /path/to/v4-app \\
  --before /path/to/source-before --after /path/to/source-after --output /path/to/checks.json
python3 render-batch-writes.py --raw /path/to/batch-results.json \\
  --checks /path/to/checks.json --output .
```

Нужны окружение и соседние fixtures/probes из [методики](README.md). Только случайные pipe_*
таблицы создаются и удаляются. Опубликованные vendor и публичное приложение не изменяются.

[Сводка и манифесты](batch-writes.summary.json), [все измерения gzip](batch-writes.raw.json.gz).
SHA-256 исходного JSON до сжатия: {hashlib.sha256(raw).hexdigest()}.
''', encoding='utf-8')
print(json.dumps({'ok': True, 'raw_sha256': hashlib.sha256(raw).hexdigest()}))
