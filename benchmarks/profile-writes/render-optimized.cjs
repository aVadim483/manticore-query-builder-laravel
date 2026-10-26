// node render-optimized.cjs comparison.json profile.json output-directory
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const [comparisonPath, profilePath, output] = process.argv.slice(2);
const raw = fs.readFileSync(comparisonPath);
const profileRaw = fs.readFileSync(profilePath);
const comparison = JSON.parse(raw);
const profile = JSON.parse(profileRaw);
if (!comparison.ok || !profile.ok || comparison.profile || !profile.profile) {
    throw new Error('Expected complete timing and profiling runs');
}
if (JSON.stringify(comparison.source_manifest) !== JSON.stringify(profile.source_manifest)) {
    throw new Error('Source snapshots differ');
}
const number = n => n.toFixed(2).replace('.', ',');
const rows = Object.entries(comparison.cases).map(([name, value]) => {
    const {v3, v4, optimized} = value.summary;
    return `| ${name.replace('_', ' ')} | ${number(v3.median_ms)} | ${number(v4.median_ms)} | ${number(optimized.median_ms)} | ${number((1 - optimized.median_ms/v4.median_ms)*100)}% | ${number((optimized.median_ms/v3.median_ms - 1)*100)}% |`;
});
const stages = ['insert_500', 'replace_500'].map(name => {
    const s = profile.cases[name].summary;
    return `| ${name.replace('_', ' ')} | ${number(s.v4.stages['numeric.prepare'].exclusive_ms)} | ${number(s.optimized.stages['numeric.prepare'].exclusive_ms)} |`;
});
const sha = data => crypto.createHash('sha256').update(data).digest('hex');
const summary = {...comparison, cases: Object.fromEntries(Object.entries(comparison.cases).map(([name, value]) => [name, {summary:value.summary, digest:value.digest}]))};
summary.raw_sha256 = sha(raw);
summary.profile_raw_sha256 = sha(profileRaw);
summary.profile_cases = Object.fromEntries(Object.entries(profile.cases).map(([name, value]) => [name, {summary:value.summary, digest:value.digest}]));
fs.mkdirSync(output, {recursive:true});
fs.writeFileSync(path.join(output, 'optimized-summary.json'), JSON.stringify(summary, null, 2)+'\n');
fs.writeFileSync(path.join(output, 'optimized-raw.json.gz'), zlib.gzipSync(raw));
fs.writeFileSync(path.join(output, 'optimized-profile-raw.json.gz'), zlib.gzipSync(profileRaw));
fs.writeFileSync(path.join(output, 'optimized-results-2026-10-26.md'), `# Повторные замеры INSERT/REPLACE после оптимизации

26 октября 2026 года сравнены опубликованные 3.0.0 и 4.0.0 с рабочими исходниками 4.x.
PHP 8.3.6, Laravel 12.70.1, Manticore 29.9.0, локальный TCP, Xdebug и query log отключены.
Одинаковые Composer-зависимости 4.0.0 использованы для исходного и оптимизированного драйвера.
Снимок подключён отдельным автозагрузчиком до создания соединения; путь и SHA-256 реально
загруженного класса проверены. Установленные vendor и публичное приложение не изменялись.

Float после преобразования по схеме превращается в Laravel Expression с числовым литералом.
Grammar вставляет число в SQL, cleanBindings удаляет Expression, а NumericBindings не разбирает SQL:
его быстрая проверка оставшихся параметров продолжает работать. JSON, MVA, векторы,
UPDATE, WHERE и raw SQL сохраняют прежний путь обработки. В query log и событиях
INSERT/REPLACE float теперь находится в SQL, а не в bindings.

## Время полной записи

Медианы ${comparison.sample_count} измерений после ${comparison.warmup} прогревов на каждый вариант; порядок трёх клиентов
циклически меняется. Таймеры отдельных этапов отключены. Все строки содержат те же семь полей,
включая float price, JSON с payload 128 байт и MVA из двух чисел. Подключение и схема прогреты.
TRUNCATE перед INSERT и проверка сохранённых данных исключены из времени; REPLACE перезаписывает
существующие ID. После каждого запуска SHA-256 всех прочитанных полей совпал у трёх реализаций.
Время включает подготовку SQL, PDO и выполнение Manticore; это задержка одного клиента,
без измерения конкурентной пропускной способности. Числа относятся к этой машине и данным.

| Операция и число строк | 3.0.0, мс | 4.0.0, мс | Оптимизация, мс | Снижение времени к 4.0.0 | Больше времени, чем 3.0.0 |
| --- | ---: | ---: | ---: | ---: | ---: |
${rows.join('\n')}

## Обработка числовых параметров

Отдельный прогон с включёнными таймерами: ${profile.sample_count} измерений после ${profile.warmup} прогревов.
Его времена полной записи не смешиваются с таблицей выше.

| Операция | NumericBindings в 4.0.0, мс | После оптимизации, мс |
| --- | ---: | ---: |
${stages.join('\n')}

Оставшаяся разница с 3.x связана с преобразованием значений по схеме, созданием Expression,
построением SQL и обработкой bindings средствами Laravel. Оптимизация устраняет один этап,
поэтому равенства общей скорости с 3.x из неё не следует.

## Воспроизведение и исходные данные

~~~sh
python3 compare-optimized.py --v3 /path/to/v3-app --v4 /path/to/v4-app \\
  --source /path/to/source-snapshot --output comparison.json --samples 30
python3 compare-optimized.py --v3 /path/to/v3-app --v4 /path/to/v4-app \\
  --source /path/to/source-snapshot --output profile.json --samples 10 --profile
node render-optimized.cjs comparison.json profile.json .
~~~

Приложения должны иметь bin/php и локальный Manticore на 19306; worker использует
probes.php, probes-v4.php и ../compare-versions/fixtures.php. Нужны права на создание
случайных write_profile_* таблиц, которые удаляются при завершении.

[Сводка и манифест исходников](optimized-summary.json),
[все измерения](optimized-raw.json.gz), [все профили](optimized-profile-raw.json.gz).
Результаты проверок совместимости этого снимка сохранены отдельно в [журнале проверки](optimized-validation.json).
SHA-256 JSON до сжатия: ${sha(raw)};
профиля: ${sha(profileRaw)}.
`);
console.log('Saved optimized results:', output);
