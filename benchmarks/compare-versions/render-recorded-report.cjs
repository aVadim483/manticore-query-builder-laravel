const fs=require('fs');
const zlib=require('zlib');
const crypto=require('crypto');
const path=require('path');
const input=process.argv[2];
const destination=process.argv[3]||__dirname;
const bytes=fs.readFileSync(input);
const raw=input.endsWith('.gz')?zlib.gunzipSync(bytes):bytes;
const report=JSON.parse(raw);
if(!report.ok || report.cleanup_errors?.length)throw new Error('Unsuccessful benchmark');
const sha=value=>crypto.createHash('sha256').update(value).digest('hex');
// This rebuilds the recorded report and its narrative, not reports from arbitrary future runs.
if(sha(raw)!=='4a93a72ff7b117ff17eba5cb213a94e2ec99e4a01e3e3cb267176f42f4abe605') {
  throw new Error('Expected the recorded 2026-10-26 measurements. New runs retain their own JSON/console summaries.');
}
const median=values=>{const a=[...values].sort((x,y)=>x-y);return (a[Math.floor((a.length-1)/2)]+a[Math.floor(a.length/2)])/2;};
const compact={run_id:report.run_id,created_at:report.created_at,environment:report.environment,server:report.server,
  dataset:{documents:report.config.documents,dimensions:report.config.vector.length},validation:report.validation,
  raw_sha256:sha(raw),api:{},http:{},queue:report.queue.summary};
for(const [name,item]of Object.entries(report.results)){
  compact.api[name]={...item.summary,matching_digest:item.matching_digest};
  const n=item.samples.v3?.length;
  if(n>=100){
    compact.api[name].series_20=[];
    for(let i=0;i<n;i+=20){
      const a=median(item.samples.v3.slice(i,i+20).map(s=>s.api_ms));
      const b=median(item.samples.v4.slice(i,i+20).map(s=>s.api_ms));
      compact.api[name].series_20.push({v3_ms:a,v4_ms:b,v4_over_v3:b/a});
    }
  }
}
for(const [name,item]of Object.entries(report.http))compact.http[name]=item.summary;
fs.mkdirSync(destination,{recursive:true});
fs.writeFileSync(path.join(destination,'results-2026-10-26.json'),JSON.stringify(compact,null,2)+'\n');
fs.writeFileSync(path.join(destination,'results-2026-10-26.raw.json.gz'),zlib.gzipSync(raw,{level:9}));
const labels={get20:'get(), 20 строк',rows_only20:'MATCH, 20 строк без META',match20:'MATCH, обычный get(), 20 строк',
  match_meta20:'MATCH + META, 20 строк',match_meta1000:'MATCH + META, 1000 строк',structured1000:'JSON/MVA/128d, 1000 строк',
  json_mva20:'JSON + MVA, 20 строк',ranking20:'Expression ranker, 20 строк',facets20:'MATCH + фасеты + META',
  highlight20:'MATCH + подсветка + META',knn20:'KNN, 20 соседей + META',paginate61:'paginate(), страница 61',
  chunk:'chunk(500), 20 000 ID',lazy:'lazy(500), 20 000 ID',paged_cursor:'cursor(500) 3.x → lazy(500) 4.x',
  native_cursor:'Нативный cursor() 4.x, 20 000 ID',write_insert500:'INSERT, 500 строк',write_replace500:'REPLACE, 500 строк',
  write_update:'UPDATE одного атрибута',write_delete:'DELETE одного документа'};
const f=value=>Number(value).toFixed(3);
const delta=(a,b)=>((b/a-1)*100>=0?'+':'')+((b/a-1)*100).toFixed(1)+'%';
let md='# A/B: библиотека 3.0.0 против 4.0.0\n\n';
md+='Замер выполнен 26 октября 2026 года на одном сервере и одинаковом наборе **20 000 документов**: stored TEXT, JSON с нагрузкой 128 байт, MVA и 128-мерный L2 HNSW. Индекс построен до измерений. Публичное приложение оставалось на 4.0.0.\n\n';
md+='PHP CLI **8.3.6**, Laravel **12.70.1**, Manticore **29.9.0**, Columnar/KNN **14.2.1**, локальный TCP. Все **77 общих Composer-пакетов** имеют одинаковые версии и source references. В 3.x дополнительно установлен PHP-билдер 2.6.0. Xdebug отсутствует, query log отключён, PDO emulated prepares и buffered queries одинаково включены.\n\n';
md+='Результат зависит от операции. Постраничный обход в 4.0 быстрее примерно на 23%, HTTP практически не изменился. При одинаковом получении метаданных большинство поисков близки; KNN медленнее примерно на 23%. Пакетные INSERT/REPLACE на этом наборе требуют примерно в 1,62 раза больше времени в 4.0.\n\n';
md+='## Время API\n\nМедиана / p95 в миллисекундах. Δ — изменение медианы 4.0 относительно 3.0: отрицательное значение означает сокращение времени. PDO — контрольное чтение/запись без слоёв билдера. Постобработка в проверочный DTO и SHA-256 в это время не входят.\n\n';
md+='| Сценарий | n на версию | 3.0: медиана / p95 | 4.0: медиана / p95 | Δ | PDO: медиана | PDO-вызовы 3 / 4 |\n| --- | --- | --- | --- | --- | --- | --- |\n';
for(const [name,item]of Object.entries(report.results)){
  const a=item.summary.v3,b=item.summary.v4,p=item.summary.pdo;
  md+=`| ${labels[name]} | ${b.samples} | ${a?f(a.median_ms)+' / '+f(a.p95_ms):'—'} | ${f(b.median_ms)} / ${f(b.p95_ms)} | ${a?delta(a.median_ms,b.median_ms):'—'} | ${f(p.median_ms)} | ${a?a.queries_per_op:'—'} / ${b.queries_per_op} |\n`;
}
md+='\nКоличество SQL означает вызовы PDO, включая SHOW META и получение последнего ID. У 3.x обычный get() получает META; поэтому отдельная строка rows-only сравнивает getRows() 3.x с get() 4.x. Для поиска с META обе версии выполняют два вызова. При INSERT/REPLACE 3.x дополнительно запрашивает LAST_INSERT_ID().\n\n';
md+='Пять полных обходов и десять операций записи дают ограниченную выборку; p95 для таких выборок совпадает с наблюдавшимся максимумом. Нативный cursor() 4.x выполняет один SELECT и не является старым постраничным cursor(500); коэффициент между ними намеренно не рассчитан.\n\n';
md+='## HTTP и задания очереди\n\nHTTP измерен через Laravel на двух закрытых PHP built-in серверах с одинаковым PHP и opcache.enable_cli=1: по 20 прогревочных и 50 измеряемых запросов, версии чередуются. Это время всего HTTP-запроса с bootstrap, соединением, DTO и JSON. PDO-инструментация отключена; число SQL и память HTTP не измерялись. Это не нагрузочный замер PHP-FPM.\n\n';
md+='| HTTP-сценарий | 3.0: медиана / p95, мс | 4.0: медиана / p95, мс | Δ |\n| --- | --- | --- | --- |\n';
for(const [name,item]of Object.entries(report.http)){
  const a=item.summary.v3,b=item.summary.v4;
  md+=`| ${labels[name]} | ${f(a.median_ms)} / ${f(a.p95_ms)} | ${f(b.median_ms)} / ${f(b.p95_ms)} | ${delta(a.median_ms,b.median_ms)} |\n`;
}
const q3=report.queue.summary.v3,q4=report.queue.summary.v4;
md+=`\nНастоящий database queue worker: четыре серии по 12 заданий на версию, первые два задания каждой серии прогревочные. В итог вошли 40 заданий на версию. Одно задание выполняет 15 поисков с проверкой DTO; время ожидания и запуск воркера исключены. Медиана / p95: **${f(q3.median_ms)} / ${f(q3.p95_ms)} мс** на 3.x и **${f(q4.median_ms)} / ${f(q4.p95_ms)} мс** на 4.x (${delta(q3.median_ms,q4.median_ms)}). PDO-вызовов на задание: ${q3.queries_per_op} / ${q4.queries_per_op}. Воркеры перезапускались между сериями; это не длительный soak-тест.\n\n`;
md+='## Память\n\nПолный максимум используемой PHP-памяти во время API-операции, включая загруженный Laravel, а не RSS ОС и не дополнительная память поверх базового процесса.\n\n';
md+='| Сценарий | 3.0, МиБ | 4.0, МиБ |\n| --- | --- | --- |\n';
for(const name of ['get20','structured1000','lazy','write_insert500']){
  const s=report.results[name].summary;md+=`| ${labels[name]} | ${s.v3.max_peak_mib.toFixed(2)} | ${s.v4.max_peak_mib.toFixed(2)} |\n`;
}
md+=`| Обработчик задания очереди | ${q3.max_peak_mib.toFixed(2)} | ${q4.max_peak_mib.toFixed(2)} |\n`;
md+='\nДля выдачи 1000 документов с JSON/MVA/128-мерными векторами пик 4.x ниже примерно на 19%. Это не означает, что каждый сценарий 4.x потребляет меньше памяти.\n\n';
md+='## Корректность и границы выводов\n\n';
md+='Все хеши нормализованных результатов совпали: строки и порядок, JSON/MVA/векторы, метаданные, фасеты, подсветка и состояние таблиц после записи. Float нормализованы до шести знаков после запятой. При обходах совпали количество, сумма и SHA-256 последовательности всех ID. HTTP-ответы совпали побайтово, результаты заданий очереди также совпали. Ошибки корректности останавливают сценарий.\n\n';
md+='Все проверочные таблицы удалены, HTTP-серверы и воркеры остановлены, отдельные очереди и failed_jobs пусты. Публичное приложение осталось на 4.0.0, все 2105 исходных документов и их отпечаток сохранены.\n\n';
md+='Разницу в 1–5% на коротких запросах следует трактовать как близкие результаты этого прогона. Общего ускорения всех операций нет. Пакетная запись и KNN — конкретные кандидаты для отдельного профилирования; данный замер не устанавливает, какая функция создаёт накладные расходы. Уменьшение числа SQL само по себе не гарантирует сокращения общего времени.\n\n';
md+='PDO не выполняет все преобразования моделей и типов библиотек. Его API-время даёт контрольную точку транспорта/сервера, а не полностью эквивалентную готовую прикладную выдачу. Нормализация результатов записана отдельно в исходных данных. Hybrid и upsert 4.x не сравниваются с несуществующими одноимёнными возможностями 3.x.\n\n';
md+='## Воспроизведение и данные\n\n';
md+='[Методика и команды](README.md) · [Сводный JSON](results-2026-10-26.json) · [Все измерения, JSON gzip](results-2026-10-26.raw.json.gz). Сводка содержит также пять последовательных серий по 20 измерений для каждого API-чтения.\n\n';
md+=`- Run ID: \`${report.run_id}\`.\n- Source 3.0.0: \`${report.validation.source_references.v3}\`.\n- Source 4.0.0: \`${report.validation.source_references.v4}\`.\n- SHA-256 несжатого исходного JSON: \`${compact.raw_sha256}\`.\n`;
fs.writeFileSync(path.join(destination,'results-2026-10-26.md'),md);
console.log(JSON.stringify({raw_sha256:compact.raw_sha256,compressed_bytes:fs.statSync(path.join(destination,'results-2026-10-26.raw.json.gz')).size,
  series_ranges:Object.fromEntries(Object.entries(compact.api).filter(([,v])=>v.series_20).map(([k,v])=>[k,[Math.min(...v.series_20.map(x=>x.v4_over_v3)),Math.max(...v.series_20.map(x=>x.v4_over_v3))]]))}));
