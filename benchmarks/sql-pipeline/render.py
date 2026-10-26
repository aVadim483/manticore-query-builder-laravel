"""Render timing and compatibility evidence without discarding individual samples."""
import argparse
from collections import Counter
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
assert data['ok'] and checks['ok'] and not data.get('cleanup_errors')
for variant in ['single_compile', 'combined_safe', 'combined']:
    assert not checks['variants'][variant]['cold_schema_listener_matches_base']
for variant in ['fused_rows', 'combined_safe', 'combined']:
    assert not checks['variants'][variant]['encoding_hook_matches_base']
output = pathlib.Path(args.output)
output.mkdir(parents=True, exist_ok=True)
def n(value):
    return f'{value:.3f}'.replace('.', ',')
def summary(case, phase, client):
    return data['cases'][case]['phases'][phase]['summary'][client]
names = {'rows_only20': 'MATCH, 20 строк без META', 'match_meta20': 'MATCH + META, 20 строк',
         'structured1000': 'JSON/MVA/128d, 1000 строк', 'knn20': 'KNN 128d + META, 20 соседей',
         'write_insert500': 'INSERT 500', 'write_replace500': 'REPLACE 500',
         'write_update': 'UPDATE integer', 'write_update_float': 'UPDATE float'}
phase_rows = []
replay_rows = []
build_rows = []
for case in data['cases']:
    for client in ['v3', 'v4']:
        live = summary(case, 'live', client)
        plain = summary(case, 'plain', client)['total_ms']
        phase_rows.append(f"| {names[case]} | {client} | {n(live['before_pdo_ms'])} | {n(live['binding_ms'])} | {n(live['execute_ms'])} | {n(live['fetch_ms'])} | {n(plain)} |")
    replay_rows.append(f"| {names[case]} | " + ' | '.join(n(summary(case, phase, client)['total_ms']) for phase, client in [('replay', 'v3'), ('replay', 'v4'), ('literal', 'v3'), ('literal', 'v4')]) + ' |')
    build_rows.append(f"| {names[case]} | {n(summary(case,'build','v3')['median_ms'])} | {n(summary(case,'build','v4')['median_ms'])} | {n(summary(case,'build','v4')['compile_median_ms'])} |")
hypothesis_rows = []
for case in data['cases']:
    plain = data['cases'][case]['phases']['plain']['summary']
    base = plain['v4']['total_ms']
    for variant, stats in plain.items():
        if variant in ['v3', 'v4']:
            continue
        value = stats['total_ms']
        hypothesis_rows.append(f"| {names[case]} | {variant} | {n(base)} | {n(value)} | {n((1-value/base)*100)}% |")
summary_data = {k: v for k, v in data.items() if k != 'cases'}
summary_data['raw_sha256'] = hashlib.sha256(raw).hexdigest()
def compact_capture(captures):
    result = {}
    for client, capture in captures.items():
        slim = {k: v for k, v in capture.items() if k != 'trace'}
        slim['trace'] = []
        for record in capture['trace']:
            item = {k: v for k, v in record.items() if k not in ['sql', 'literal_sql', 'bindings', 'execute_params']}
            item['sql_prefix'] = record['sql'][:2000]
            item['sql_truncated'] = len(record['sql']) > 2000
            item['sql_sha256'] = hashlib.sha256(record['sql'].encode()).hexdigest()
            item['literal_sql_sha256'] = hashlib.sha256(record['literal_sql'].encode()).hexdigest()
            item['binding_type_counts'] = dict(Counter(str(binding[2]) for binding in record['bindings']))
            item['execute_array_count'] = len(record['execute_params'] or [])
            slim['trace'].append(item)
        result[client] = slim
    return result

summary_data['cases'] = {case: {'capture': compact_capture(value['capture']), 'phases': {
    phase: {'summary': content['summary']} for phase, content in value['phases'].items()}}
    for case, value in data['cases'].items()}
(output / 'results-2026-10-26.summary.json').write_text(json.dumps(summary_data, indent=2))
(output / 'results-2026-10-26.raw.json.gz').write_bytes(gzip.compress(raw, mtime=0))
(output / 'variant-checks.json').write_text(json.dumps(checks, indent=2, ensure_ascii=False))
text = f'''# Подготовка SQL, выполнение PDO и гипотезы оптимизации

26 октября 2026 года. Сравниваются опубликованная **3.0.0** и текущая **4.x после оптимизации
float INSERT/REPLACE**, исходники коммита 1f7da31. Сервер: {data['server']}.
PHP {data['environment']['v4']['php']}, Laravel {data['environment']['v4']['laravel']};
{data['common_dependencies']} общих зависимостей имеют одинаковые версии и source references.
Локальный TCP, emulated prepares и buffered queries включены; Xdebug, query log и
диспетчер событий в основных замерах отключены. Это замер одного клиента без искусственной
параллельной нагрузки.

Один общий набор из {data['config']['documents']} документов, JSON payload 128 байт, MVA,
128-мерные L2-векторы; HNSW построен до измерений. В пакетной записи семь полей без вектора.
Каждый клиент пишет в собственную случайную таблицу. Порядок клиентов циклически меняется.
По {data['samples']} измерений после {data['warmup']} прогревов на сценарий и режим.
Все нормализованные результаты и записанные данные совпали, включая прямое выполнение SQL.

## Границы измерений

* **До PDO**: создание билдера и условий, проверки, кодирование данных, построение SQL,
  подготовка массива bindings и NumericBindings до входа в PDO::prepare. Включает все
  компиляции запроса. Схема прогрета; DESCRIBE не входит в измерение.
* **Привязка**: интервал от возврата PDO::prepare до входа в execute, включая prepareBindings,
  bindValue и настройку statement. Это не только чистое время bindValue.
* **Execute/query**: вызовы PDO, включая клиентскую подстановку параметров при эмуляции,
  сетевой обмен и выполнение/буферизацию ответа. Это **не чистое время CPU Manticore**.
  В 3.x сюда также попадают SHOW META или LAST_INSERT_ID, если они вызваны библиотекой.
* **Fetch**: PHP fetch/fetchAll. Постобработка библиотеки, приведение типов и создание
  результатов остаются за пределами fetch и входят в полное время API.
* **API без трассировки**: отдельная серия с отключёнными таймерами и счётчиками.
  Проверка SHA-256, TRUNCATE, заполнение перед REPLACE/UPDATE и нормализация вынесены за таймер.

Медианы отдельных этапов не обязаны складываться в медиану полного запроса. Трассировка
добавляет накладные расходы, поэтому выигрыш гипотез оценивается по серии без трассировки.

## Реальная операция по этапам

Все времена в миллисекундах.

| Операция | Версия | До PDO | Привязка | Execute/query | Fetch | API без трассировки |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
{chr(10).join(phase_rows)}

## Выполнение уже готовых запросов

В replay повторяются **фактически перехваченные SQL, значения и PDO-типы bindings**,
включая вспомогательные запросы исходной библиотеки. Новые builder и Grammar не вызываются.
В literal повторяется готовый SQL из PDO::debugDumpParams (Sent SQL после эмуляции),
параметры повторно не привязываются. Если параметров нет, используется исходная queryString.
Это эксперимент для локализации расходов PDO, а не предложение подставлять все значения вручную.

| Операция | Replay 3.x | Replay 4.x | Literal 3.x | Literal 4.x |
| --- | ---: | ---: | ---: | ---: |
{chr(10).join(replay_rows)}

В перехваченном INSERT 500 у 3.x — {data['cases']['write_insert500']['capture']['v3']['trace'][0]['binding_count']}
параметров PDO: значения уже подставлены в SQL библиотекой. У 4.x —
{data['cases']['write_insert500']['capture']['v4']['trace'][0]['binding_count']} параметров PDO,
которые ещё нужно привязать и экранировать. Поэтому часть одинаковой по назначению работы
попадает у 3.x в подготовку, а у 4.x — в bind/execute. Сравнивать только execute без этой
оговорки неверно. Прямой literal-прогон помогает отделить эту работу, но не измеряет чистое
серверное время и сам по себе не обосновывает отказ от bindings.

## Изолированная подготовка без выполнения

Операция останавливается специальным исключением на входе в первый вызов PDO, **до**
его выполнения. Время фиксируется перед выбрасыванием исключения, его упаковка/обработка
в результат не входят. Каждый полученный SQL побайтово сверяется с SQL реального запроса
этого клиента. Это подготовка до PDO, а не только вызов Grammar.

{data['build_rounds']} измеряемых серий после пяти прогревочных: по 50 подготовок чтения или
10 подготовок записи в серии. Короткие серии подряд отличаются от режима отдельных реальных
запросов: не следует подставлять их абсолютные числа вместо колонки «До PDO» выше или вычитать
их из полного API-времени. CPU-время getrusage сохранено в исходных данных.

| Операция | Подготовка 3.x | Подготовка 4.x | Из неё Grammar 4.x |
| --- | ---: | ---: | ---: |
{chr(10).join(build_rows)}

## Гипотезы: измеренное полное время

Числа ниже относятся к **отдельным экспериментальным снимкам**, а не к внедрённым изменениям.
Положительный процент означает уменьшение времени относительно текущей 4.x в той же серии.
Все прототипы, кроме execute_array и включающего его combined, сохраняют исходные SQL и
PDO-типы параметров на прогретом стенде. Для execute_array сохраняется SQL с плейсхолдерами,
но тип привязки параметров меняется. Результаты основного набора совпадают у всех вариантов.

| Операция | Гипотеза | Текущая 4.x, мс | Прототип, мс | Снижение времени |
| --- | --- | ---: | ---: | ---: |
{chr(10).join(hypothesis_rows)}

Названия combined_safe/combined — технические имена стенда. Суффикс safe означает только
отсутствие execute_array; **совместимость этого объединённого варианта не подтверждена**.

## Совместимость и порядок дальнейшей работы

Проверены 1000 наборов cleanBindings с Expression, enum, DateTime, null, bool, большими
числами и разреженными ключами; 1000 KNN-векторов с отрицательным нулём, дробями, экспонентами
и опциями; четыре терминальных метода записи на Unicode, JSON-скалярах, пустых MVA,
bool/null и ID за пределом точности double. Данные совпали с базовой 4.x у всех прототипов.
Отдельно проверены холодная схема с QueryExecuted listener и вызов защищённого хука кодирования.

1. **loop_bindings — первый кандидат на внедрение.** Один цикл вместо Collection::reject/map/values
   даёт измеримый выигрыш на пакетах. Проверки чистки параметров совпали с Laravel. Перед переносом
   нужен прогон поддерживаемых Laravel/PHP и существующих тестов записи; castBinding следует сохранить.
2. **vector_json — второй кандидат.** Сериализовать проверенный числовой список целиком вместо
   отдельного json_encode для каждого элемента. SQL 1000 проверенных векторов совпал; прежняя
   проверка finite/list/числовых типов и float-токена oversampling должна остаться.
3. **single_compile — причина подтверждена, прототип переносить нельзя.** На прогретой схеме
   SELECT компилируется дважды; устранение повтора особенно заметно на KNN. Но при холодной схеме
   DESCRIBE может вызвать QueryExecuted listener, меняющий builder. SQL, сохранённый до DESCRIBE,
   тогда расходится с новыми bindings: дополнительная проверка обнаружила ошибку. Следующий шаг —
   отделить проверку допустимости запроса от финальной компиляции, сохранив проверку до обращения
   к серверу, а SQL формировать один раз после возможных событий загрузки схемы.
4. **update_validation — небольшой самостоятельный кандидат.** Предварительный compileUpdate
   сейчас строит SQL, который отбрасывается; позднее Laravel компилирует его повторно. Нужна
   отдельная проверка условий перед загрузкой схемы и одна финальная компиляция. Важно сохранить
   поведение пользовательских Grammar и отклонение неподдерживаемых запросов до сетевых вызовов.
5. **fused_rows — низкий приоритет.** Удаление прохода даёт небольшой выигрыш, но текущий прототип
   обходит protected encodeWriteRows: проверка наследования обнаружила пропуск хука. Переносить
   как есть нельзя; оптимизацию нужно делать с сохранением точки расширения.
6. **execute_array — ограниченный выигрыш и изменение PDO-типов.** Сокращает PHP-вызовы bindValue,
   но часть работы перемещается внутрь execute, поэтому полное ускорение существенно меньше
   сокращения колонки «Привязка». Основной и дополнительный наборы данных совпали, однако это
   не доказывает эквивалентность типов для всех схем/SQL. Не заменять bindValue глобально;
   возможный путь для bulk INSERT/REPLACE требует отдельного контракта типов и матрицы сервера.

Для structured1000 значительная часть времени остаётся в передаче/буферизации ответа и
постобработке JSON/MVA/векторов. Оптимизации построения короткого SQL здесь мало меняют полное время.
Разницы нескольких процентов на коротких операциях недостаточно для обещания устойчивого выигрыша;
повторный A/B после внедрения каждого изменения обязателен. Кэши готового SQL или результатов,
ослабление валидации и обход Laravel без проверки контрактов из этих измерений не следуют.

## Данные и воспроизведение

[Методика запуска](README.md), [сводка и манифесты](results-2026-10-26.summary.json),
[все измерения gzip](results-2026-10-26.raw.json.gz), [проверки прототипов](variant-checks.json),
[сохранность приложения и очистка таблиц](environment-validation.json).
SHA-256 исходного JSON до сжатия: {hashlib.sha256(raw).hexdigest()}.
Пути и SHA-256 реально загруженных классов и манифесты исходников сохранены в сводке.
Точные SQL/bindings и все отдельные измерения находятся в сжатом исходном JSON;
в сводке длинные SQL сокращены до 2000 символов с сохранением полного SHA-256.
Основное приложение и опубликованные vendor не изменены.
'''
(output / 'results-2026-10-26.md').write_text(text, encoding='utf-8')
print(json.dumps({'ok': True, 'output': str(output), 'raw_sha256': hashlib.sha256(raw).hexdigest()}))
