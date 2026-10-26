"""Report end-to-end literal SQL costs and compatibility, including negative results."""
import argparse
import gzip
import hashlib
import json
import pathlib
import statistics

parser = argparse.ArgumentParser()
parser.add_argument('--fused', required=True)
parser.add_argument('--offsets', required=True)
parser.add_argument('--checks', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
output = pathlib.Path(args.output)
output.mkdir(parents=True, exist_ok=True)
checks = json.loads(pathlib.Path(args.checks).read_text())
assert checks['ok'] and len(checks['servers']) == 2
for server in checks['servers'].values():
    assert server['differences']['offsets'] == []
    assert server['differences']['fused'] == [{'case': 'valid_unavailable', 'field': 'exception',
        'before': 'Illuminate\\Database\\QueryException', 'after': 'LogicException'}]

reports = {}
summary = {'checks': checks, 'experiments': {}}
tables = {}
stages = []
for label, filename in [('fused', args.fused), ('offsets', args.offsets)]:
    raw = pathlib.Path(filename).read_bytes()
    report = json.loads(raw)
    assert report['ok'] and report['samples'] == 50
    for ready in report['environment'].values():
        if ready['pdo_class'] == 'PDO':
            assert ready['pdo_statement_class'] == 'PDOStatement'
    reports[label] = report
    compact = {k: v for k, v in report.items() if k != 'cases'}
    compact['raw_sha256'] = hashlib.sha256(raw).hexdigest()
    compact['cases'] = {}
    rows = []
    for name, case in report['cases'].items():
        plain = case['phases']['plain']['summary']
        before, after = plain['bindings']['total_ms'], plain['literal']['total_ms']
        differences = [b['metrics']['total_ms']-a['metrics']['total_ms'] for a, b in
            zip(case['phases']['plain']['samples']['bindings'], case['phases']['plain']['samples']['literal'])]
        rows.append(f'| {name.replace("_", " ")} | {plain["v3"]["total_ms"]:.3f} | {before:.3f} | {after:.3f} | '
                    f'{(after/before-1)*100:+.2f}% | {statistics.median(differences):+.3f} |')
        compact['cases'][name] = {'captures': case['captures'], 'matching_digest': case['matching_digest'],
            'paired_difference_ms': statistics.median(differences),
            'phases': {phase: {'summary': value['summary']} for phase, value in case['phases'].items()}}
        if name.endswith('_500'):
            for client in ['bindings', 'literal']:
                live = case['phases']['live']['summary'][client]
                stages.append(f'| {label} | {name.replace("_", " ")} | {client} | {live["before_pdo_ms"]:.3f} | '
                    f'{live["binding_ms"]:.3f} | {live["execute_ms"]:.3f} | {live["total_ms"]:.3f} |')
    summary['experiments'][label] = compact
    tables[label] = '\n'.join(rows)
    (output / f'literal-writes.{label}.raw.json.gz').write_bytes(gzip.compress(raw, mtime=0))
(output / 'literal-writes.summary.json').write_text(json.dumps(summary, indent=2, ensure_ascii=False))
(output / 'literal-writes.checks.json').write_text(json.dumps(checks, indent=2, ensure_ascii=False))
(output / 'literal-writes.md').write_text(f'''# Проверка готового SQL без bindings для INSERT/REPLACE

Базовая 4.x — коммит **77e2f81**, после оптимизации cleanBindings и пакетной подготовки.
26 октября 2026 года, PHP 8.3.6, Laravel 12.70.1, Manticore 29.9.0, локальный TCP.
Опубликованная 3.0.0 измерена рядом как контроль. Оба прототипа созданы в отдельных снимках;
код рабочей библиотеки, установленные vendor и публичное приложение не менялись.

## Вывод

На данном наборе **отказ от bindings не ускорил полную запись**. Выполнение готового SQL
через PDO::exec дешевле prepare/bind/execute, но подготовка экранированного SQL добавляет
работу в PHP и компенсирует этот выигрыш. Оба проверенных варианта на пакетах из 500
документов требуют больше полного времени, чем текущая 4.x.

Для первого варианта также обнаружено отличие в типе ошибки недоступного соединения.
Второй сохранил проверенные контракты, но тоже не дал выигрыша скорости.
Результат относится к этим реализациям и fixture; он не доказывает невозможность
любого другого эффективного способа сборки SQL.

## Что именно проверялось

1. **fused:** при подготовке пакета совместно собираются SQL с плейсхолдерами для событий
   и готовый SQL для исполнения. Строки экранируются только PDO::quote, integer/bool/null
   форматируются как соответствующие литералы, float — прежним JSON-форматированием.
   Дополнительная проверка всего пакета предшествует разрешению ленивого PDO.
2. **offsets:** компилятор собирает исходный SQL/bindings и точные байтовые позиции созданных
   им плейсхолдеров. Внутри Laravel run() вызываются prepareBindings, PDO::quote и сборка
   окончательного SQL по позициям, без повторного разбора SQL и без bindValue.
   Соединение разрешается внутри стандартной обработки исключений.

Выполняемый DML использует тот же PDO и Connection::run. Исходные SQL/bindings
сохраняются для событий и query log; контекст ошибок проверен отдельно ниже. Подклассы, произвольные raw
Expression, ресурсы, beforeExecuting callbacks и pretend используют существующий путь.
Числовые tuple MVA/векторов допускаются после проверки формы. UPDATE, WHERE и SELECT
остаются вне экспериментального пути.

## Методика полного времени

По 50 измерений после пяти прогревов, версии чередуются; семь полей, float, JSON payload
128 байт и MVA. Схема прогрета. TRUNCATE, заполнение перед REPLACE, создание fixture,
проверка данных и запуск Laravel не входят в таймер. Внутри таймера находится весь вызов
insert/replace, включая кодирование, экранирование и сборку SQL.

**Основные числа plain получены строго с PDO/PDOStatement**, без классов перехвата и
таймеров на bindValue. Это проверяется по фактически созданным классам. Для трассировки
используются отдельные процессы. Прежний отчёт пакетной оптимизации отключал таймеры,
но оставлял классы перехвата; поэтому абсолютные времена двух отчётов не следует смешивать.

Фактически отправленный SQL bindings-варианта из PDO Sent SQL побайтово сверяется с SQL
прототипа после нормализации только имени случайной таблицы. Все сохранённые данные
совпали у трёх клиентов после каждой измеряемой записи, включая пакеты из 1000 строк.

Положительная разница означает, что готовый SQL медленнее. Времена в миллисекундах;
парная разница сравнивает два вызова в одном измерительном раунде.

### Совместная сборка двух представлений SQL

| Операция и строки | 3.x | Текущая 4.x | Готовый SQL | Изменение времени | Парная разница, мс |
| --- | ---: | ---: | ---: | ---: | ---: |
{tables['fused']}

### Отложенное экранирование внутри Laravel run()

| Операция и строки | 3.x | Текущая 4.x | Готовый SQL | Изменение времени | Парная разница, мс |
| --- | ---: | ---: | ---: | ---: | ---: |
{tables['offsets']}

Сравнивать варианты нужно с их собственным контролем в той же таблице: два эксперимента
выполнены последовательно. Нет гарантии аналогичной разницы на другом сервере, данных
или при параллельной нагрузке.

## Почему ускорение исполнения не стало ускорением операции

Отдельный live-прогон с перехватом границ PDO. Подготовка до первого prepare/exec включает
экранирование, независимо от того, выполняется оно в builder или внутри run(): первый
перехваченный SQL-вызов здесь — exec. PDO::quote — клиентская функция и не является сетевым
запросом. Execute/query включает клиент PDO, сеть и Manticore, а не только CPU сервера.
Медианы этапов нельзя механически суммировать или подставлять вместо plain.

| Вариант | Операция | Путь | До prepare/exec | Привязка | Execute/query | Полное время с перехватом |
| --- | --- | --- | ---: | ---: | ---: | ---: |
{chr(10).join(stages)}

Исчезают PHP-вызовы bindValue и подстановка параметров внутри PDO. Вместо них появляются
вызовы PDO::quote из PHP, дополнительное представление SQL либо карта позиций/массив
фрагментов и их сборка. Работа переносится между этапами, а не исчезает целиком.

## Проверки корректности

Для каждой реализации выполнены **47 сценариев на Manticore 29.9.0 и 28.6.6**:
сложные строки с Unicode, кавычками, слешами, NUL и управляющими символами; JSON, bool,
timestamp, MVA64, vector, дроби/экспоненты, большие ID и numeric strings; raw/custom/nested
Expression, потоки, неизвестная колонка и NULL; beforeExecuting и поздние schema callbacks;
подклассы; commit/rollback и дубликаты; INF, pretend и ленивое недоступное соединение.
Сравниваются значения и типы результата, данные, события, query log, модификация записей,
состояние транзакции, защищённые hooks и ошибки. Для успешного DML сверяется отправленный SQL.

* offsets: расхождений в этих проверках нет — **94 сценария совпали с контролем**.
* fused: обычные записи, события и транзакции совпали, но при недоступном ленивом соединении
  PDO разрешается во время подготовки, до Laravel run(). В результате вместо QueryException
  возникает исходный LogicException тестового PDO. Это дополнительная причина не переносить
  данный прототип в библиотеку.

Для дальнейшей оптимизации bindings оставлены в рабочем коде. Целесообразнее отдельно
измерять кодирование по схеме и промежуточные массивы, не обещая выигрыша заранее.

## Данные и воспроизведение

make-literal-write.py и make-literal-offsets.py получают --source и новый --output каталог.
literal-writes.py получает --v3, --app, --source, --candidate, --output, --samples 50;
--sizes задаёт список размеров. check-literal-writes.py получает --app, --source, --fused,
--offsets, --ports 19306 19506 и --output. Нужны окружение и fixtures из [методики](README.md).
Все прототипы остаются отдельными экспериментальными снимками, не публичным API библиотеки.

[Сводка, манифесты и проверки](literal-writes.summary.json),
[измерения fused](literal-writes.fused.raw.json.gz),
[измерения offsets](literal-writes.offsets.raw.json.gz),
[проверки](literal-writes.checks.json), [сохранность приложения](literal-writes.environment.json).
Сводка содержит SHA-256 обоих исходных JSON до сжатия, пути и SHA-256 реально загруженных
классов, а также реальные классы PDO/PDOStatement для каждой группы процессов.
''', encoding='utf-8')
print(json.dumps({'ok': True, 'experiments': list(reports), 'compatibility_cases_per_server': 47}))
