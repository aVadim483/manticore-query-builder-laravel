# Числа и строки в SQL Manticore: примеры для float

Значения 55.3 и '55.3' выглядят похоже, но в SQL это число и строка.
В Manticore строка преобразуется в число не во всех контекстах: INSERT может её
принять, WHERE — отклонить, а UPDATE — выполниться без изменения значения.

Ниже приведён SQL именно для Manticore Search. Поведение проверено на
**Manticore 28.6.6 и 29.9.0** через MySQL-протокол с эмуляцией prepared statements.
Строковый PDO-параметр в таком режиме попадает в SQL как значение в кавычках.
Примеры показывают, что получает сервер; они не требуют Laravel.

Выполняйте разделы по порядку. Запросы с пометкой «Ошибка» запускайте отдельно,
чтобы ожидаемая ошибка не останавливала выполнение остальных примеров в SQL-клиенте.

| Контекст | Число | Строка с записью числа |
| --- | --- | --- |
| INSERT/REPLACE в обычный float-атрибут | Записывается | Записывается с тем же значением на проверенных примерах |
| UPDATE float-атрибута | Обновляет значение | В проверенном случае запрос успешен, но значение не меняется |
| WHERE / BETWEEN по float | Работает | Ошибка |
| Арифметика с float | Работает | Ошибка |
| DOUBLE со строковой константой | Числовой аргумент принимается | Не служит универсальным приведением строки к числу |
| Проверенный IF с числовым сравнением | Работает | В этом конкретном выражении результат совпадает |
| Фильтр по числу внутри JSON | Находит числовые значения | Не эквивалентен числовому условию |
| Компоненты вектора в INSERT или KNN | Принимаются | Ошибка синтаксиса |

## Подготовка учебной таблицы

Используйте свободное имя таблицы. Документы с ID 1–3 — исходные данные для чтения;
запись и обновление ниже используют отдельные ID.

```sql
CREATE TABLE float_sql_examples (
    title text,
    price float,
    details json
);

INSERT INTO float_sql_examples (id, title, price, details) VALUES
    (1, 'First', 1.25, '{"rating":1.25}'),
    (2, 'Second', 2.5, '{"rating":2.5}'),
    (3, 'Third', 55.3, '{"rating":1.25}');
```

Manticore хранит float с одинарной точностью. Поэтому 55.3 при чтении может
отображаться, например, как 55.299999. Это обычная погрешность float32, а не
разница между записью числа и строки. Значения 1.25, 2.5 и 12.75 в примерах
удобны тем, что точно представимы в двоичной системе.
См. [тип Float в руководстве Manticore](https://manual.manticoresearch.com/Creating_a_table/Data_types).

## 1. INSERT: строка принимается в обычный float-атрибут

```sql
INSERT INTO float_sql_examples (id, title, price)
VALUES (10, 'Numeric insert', 55.3);

INSERT INTO float_sql_examples (id, title, price)
VALUES (11, 'String insert', '55.3');

SELECT id, price FROM float_sql_examples
WHERE id IN (10, 11) ORDER BY id ASC;
```

Оба документа получают одинаковую цену: приблизительно 55.3.
В этих запросах строка находится непосредственно в позиции значения float-атрибута.

Это не означает, что кавычки допустимы в любом месте запроса INSERT:
ниже показан отдельный случай с компонентами вектора.

## 2. REPLACE: строка тоже принимается

```sql
REPLACE INTO float_sql_examples (id, title, price)
VALUES (10, 'Numeric replace', 12.75);

REPLACE INTO float_sql_examples (id, title, price)
VALUES (11, 'String replace', '12.75');

SELECT id, price FROM float_sql_examples
WHERE id IN (10, 11) ORDER BY id ASC;
```

У обоих документов price равен 12.75.

REPLACE заменяет документ целиком. Если не передать details, его прежнее содержимое
не сохранится. Это отдельное свойство REPLACE, не связанное с кавычками вокруг float.

## 3. UPDATE: успешный запрос может ничего не изменить

Каждому варианту предшествует восстановление исходной цены 1.25.
Поэтому второй UPDATE не использует результат первого.

```sql
REPLACE INTO float_sql_examples (id, title, price)
VALUES (100, 'Update test', 1.25);

UPDATE float_sql_examples SET price = 12.75 WHERE id = 100;

SELECT id, price FROM float_sql_examples WHERE id = 100;
-- Результат: id = 100, price = 12.75.

REPLACE INTO float_sql_examples (id, title, price)
VALUES (100, 'Update test', 1.25);

UPDATE float_sql_examples SET price = '12.75' WHERE id = 100;

SHOW WARNINGS;

SELECT id, price FROM float_sql_examples WHERE id = 100;
-- Результат: id = 100, price = 1.25.
```

На проверенных версиях строковый вариант не вызывает исключения, сообщает
0 изменённых строк и не выдаёт предупреждений. Цена остаётся прежней.
Успех выполнения запроса сам по себе не доказывает, что значение записано.

Это наблюдаемое поведение указанных версий, а не способ намеренно пропускать UPDATE.
Для обновления числового атрибута используйте число.

## 4. WHERE и BETWEEN: строка не заменяет число

```sql
SELECT id FROM float_sql_examples WHERE price = 2.5;
-- Результат: id = 2.

-- Ошибка: строковый фильтр не поддерживается для float-атрибута price.
SELECT id FROM float_sql_examples WHERE price = '2.5';

SELECT id FROM float_sql_examples
WHERE price BETWEEN 1.0 AND 3.0 ORDER BY id ASC;
-- Результат: id = 1, 2, 100.

-- Ошибка: в границах диапазона ожидаются числа.
SELECT id FROM float_sql_examples
WHERE price BETWEEN '1.0' AND '3.0';
```

ID 100 входит в числовой диапазон, потому что после строкового UPDATE его цена
осталась равной 1.25. Примеры предполагают выполнение предыдущих разделов по порядку.
Для сравнения с кавычками характерна ошибка
"unsupported filter type 'string' on attribute 'price'"; текст диагностики может
различаться между версиями.

## 5. Арифметика и DOUBLE: кавычки меняют допустимость выражения

```sql
SELECT price + 0.125 AS adjusted
FROM float_sql_examples WHERE id = 1;
-- Результат: adjusted = 1.375.

-- Ошибка: строковая константа не принимается как числовой операнд.
SELECT price + '0.125' AS adjusted
FROM float_sql_examples WHERE id = 1;

SELECT DOUBLE(55.3) AS numeric_value
FROM float_sql_examples WHERE id = 1;

-- Ошибка: DOUBLE не принимает эту строковую константу как число.
SELECT DOUBLE('55.3') AS numeric_value
FROM float_sql_examples WHERE id = 1;
```

Наличие функции DOUBLE не означает, что любое строковое значение можно безопасно
обернуть в DOUBLE(...). В приведённом случае она не решает проблему типа аргумента.

## 6. IF: частный случай, где результаты совпадают

```sql
SELECT id, IF(price > 1.25, 1, 0) AS above
FROM float_sql_examples WHERE id <= 3 ORDER BY id ASC;

SELECT id, IF(price > '1.25', 1, 0) AS above
FROM float_sql_examples WHERE id <= 3 ORDER BY id ASC;
```

В обоих вариантах получены пары (id, above): (1, 0), (2, 1), (3, 1).
Это проверка конкретного выражения IF. Она не переносится на WHERE:
строковый фильтр по тому же float-атрибуту выше вызывает ошибку.

## 7. JSON: число и строка — разные значения

JSON — отдельный тип атрибута. Пример показывает ещё один случай, когда запрос
успешен, но кавычки меняют результат поиска.

```sql
SELECT id FROM float_sql_examples
WHERE id <= 3 AND details.rating = 1.25 ORDER BY id ASC;
-- Результат: id = 1, 3.

SELECT id FROM float_sql_examples
WHERE id <= 3 AND details.rating = '1.25' ORDER BY id ASC;
-- Результат: пусто.

INSERT INTO float_sql_examples (id, title, price, details)
VALUES (200, 'JSON string', 1.25, '{"rating":"1.25"}');

SELECT id FROM float_sql_examples
WHERE id = 200 AND details.rating = '1.25';
-- Результат: id = 200.
```

Кавычки вокруг всего JSON-документа нужны SQL для передачи текста JSON.
Внутри '{"rating":1.25}' значение rating — число, а внутри '{"rating":"1.25"}' — строка.
Строковое условие уместно для строкового значения JSON, но не эквивалентно числовому.

## 8. Векторы: правило для скалярного INSERT здесь не действует

Для этого раздела нужна библиотека KNN. Используется отдельная таблица:

```sql
CREATE TABLE float_sql_vectors (
    title text,
    embedding float_vector KNN_TYPE='hnsw' KNN_DIMS='3' HNSW_SIMILARITY='l2'
);

INSERT INTO float_sql_vectors (id, title, embedding)
VALUES (1, 'Numeric vector', (0.1, 0.2, 0.3));

-- Ошибка: компоненты векторного кортежа должны быть числами.
INSERT INTO float_sql_vectors (id, title, embedding)
VALUES (2, 'String vector', ('0.1', '0.2', '0.3'));

FLUSH RAMCHUNK float_sql_vectors;

SELECT id, KNN_DIST() AS distance FROM float_sql_vectors
WHERE KNN(embedding, 1, (0.1, 0.2, 0.3))
ORDER BY distance ASC LIMIT 1;
-- Результат: id = 1, distance = 0.

-- Ошибка: строковые компоненты не принимаются и в запросе KNN.
SELECT id FROM float_sql_vectors
WHERE KNN(embedding, 1, ('0.1', '0.2', '0.3'));
```

Вставка '55.3' непосредственно в обычный float-атрибут и вставка '0.1'
внутрь векторного кортежа — разные контексты.

## Завершение примеров

```sql
DROP TABLE float_sql_vectors;
DROP TABLE float_sql_examples;
```

Если раздел про векторы пропущен, удалите только float_sql_examples.

Для обычного использования Laravel-драйвера передавайте числовые значения как числа:
драйвер сам подготовит их для Manticore. Замена всех float на строки не является
универсальным способом ни обеспечить совместимость, ни ускорить запросы.

Официальный синтаксис: [INSERT](https://manual.manticoresearch.com/Data_creation_and_modification/Adding_documents_to_a_table/Adding_documents_to_a_real-time_table),
[UPDATE](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE).
Нестандартные результаты строковых вариантов выше проверены отдельно на указанных версиях сервера.
