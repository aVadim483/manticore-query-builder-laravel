#!/usr/bin/env python3
"""Run locally on the Manticore host, using two private Laravel test applications."""
import argparse, pathlib, subprocess, json, statistics, hashlib, secrets, time, socket, urllib.request, urllib.error, os

parser=argparse.ArgumentParser()
parser.add_argument('--v3',required=True);parser.add_argument('--v4',required=True)
parser.add_argument('--output',required=True);parser.add_argument('--documents',type=int,default=20000)
parser.add_argument('--read-samples',type=int,default=100)
parser.add_argument('--skip-http-queue',action='store_true')
args=parser.parse_args()
assert args.documents>=2000 and args.documents%250==0 and args.read_samples>=5
source=pathlib.Path(__file__).resolve().parent
roots={'v3':pathlib.Path(args.v3).resolve(),'v4':pathlib.Path(args.v4).resolve()}
run_id=secrets.token_hex(5)
config={'run_id':run_id,'documents':args.documents,'read_table':'abcmp_'+run_id+'_read',
    'write_tables':{c:'abcmp_'+run_id+'_write_'+c for c in ['v3','v4','pdo']}}
# Generate the identical query vector through PHP's fixture function, not a second PRNG implementation.
vector_code="require $argv[1]; echo json_encode(comparisonDocument(1)['embedding']);"
config['vector']=json.loads(subprocess.check_output([str(roots['v4']/'bin/php'),'-r',vector_code,str(source/'fixtures.php')],text=True))
config_path=pathlib.Path(args.output).resolve().with_suffix('.config.json')
config_path.write_text(json.dumps(config))
clients={};servers=[];table_created=False
report={'run_id':run_id,'created_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'config':config,
    'method':'Persistent PHP workers; sequential rotating clients; warm-up excluded; identical PDO statement counters',
    'environment':{},'preflight':{},'results':{},'http':{},'queue':{}}
packages={client:{p['name']:p for p in json.loads((root/'composer.lock').read_text())['packages']} for client,root in roots.items()}
common=(set(packages['v3'])&set(packages['v4']))-{'avadim/manticore-query-builder-laravel'}
assert all(packages['v3'][name]['version']==packages['v4'][name]['version'] and packages['v3'][name].get('source')==packages['v4'][name].get('source') for name in common),'Common dependency versions/references differ'
assert set(packages['v3'])-set(packages['v4'])=={'avadim/manticore-query-builder-php'},'Unexpected dependency difference'
assert not set(packages['v4'])-set(packages['v3']),'Unexpected v4-only dependency'
report['dependency_check']={'common_packages_unchanged':len(common),'v3_php_builder':packages['v3']['avadim/manticore-query-builder-php']['version'],
    'source_references':{client:packages[client]['avadim/manticore-query-builder-laravel']['source']['reference'] for client in roots}}

class Worker:
    def __init__(self,client):
        root=roots['v3' if client=='v3' else 'v4']
        self.log=open(config_path.with_name(run_id+'-'+client+'.stderr.log'),'w')
        self.process=subprocess.Popen([str(root/'bin/php'),'-d','xdebug.mode=off','-d','memory_limit=768M',str(source/'worker.php'),str(root),client,str(config_path)],cwd=root,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=self.log,text=True,bufsize=1)
        ready=self.process.stdout.readline()
        if not ready:raise RuntimeError('Worker startup failed: '+client)
        self.environment=json.loads(ready)
        self.client=client
    def call(self,command):
        self.process.stdin.write(json.dumps(command)+'\n');self.process.stdin.flush()
        line=self.process.stdout.readline()
        if not line:raise RuntimeError('Worker exited; inspect stderr logs')
        value=json.loads(line)
        if not value.get('ok'):raise RuntimeError(self.client+' '+str(command)+': '+str(value))
        return value
    def close(self):
        if self.process.poll() is None:
            self.process.stdin.write('{"op":"quit"}\n');self.process.stdin.flush()
            self.process.wait(timeout=15)
        self.log.close()

def summary(samples,time_key='api_ms'):
    times=sorted(s[time_key] for s in samples)
    nearest95=times[max(0,(len(times)*95+99)//100-1)]
    query_counts=[s['queries'] for s in samples if s.get('queries') is not None]
    result={'samples':len(samples),'median_ms':statistics.median(times),'p95_ms':nearest95,
        'ops_per_s':len(times)*1000/sum(times),
        'queries_per_op':statistics.median(query_counts) if query_counts else None,
        'max_peak_mib':max(s.get('api_peak_bytes',s.get('peak_bytes',0)) for s in samples)/1048576,
        'last_retained_mib':samples[-1].get('retained_bytes',0)/1048576}
    if 'dto_ms' in samples[0]:result['median_dto_ms']=statistics.median(s['dto_ms'] for s in samples)
    if not any('api_peak_bytes' in sample or 'peak_bytes' in sample for sample in samples):
        result.pop('max_peak_mib');result.pop('last_retained_mib')
    return result

def rotate(index,available):
    offset=index%len(available)
    return available[offset:]+available[:offset]

def checkpoint():
    pathlib.Path(args.output).write_text(json.dumps(report,ensure_ascii=False,indent=2))

try:
    for client in ['pdo','v3','v4']:
        clients[client]=Worker(client);report['environment'][client]=clients[client].environment
    assert report['environment']['v3']['package']=='v3.0.0'
    assert report['environment']['v4']['package']=='v4.0.0'
    assert len({e['php'] for e in report['environment'].values()})==1
    assert len({e['laravel'] for e in report['environment'].values()})==1
    assert len({json.dumps(e['pdo'],sort_keys=True) for e in report['environment'].values()})==1
    assert all(not e['xdebug_loaded'] for e in report['environment'].values())
    report['server']=clients['pdo'].call({'op':'sql','sql':'SELECT VERSION() AS version'})['rows'][0]['version']
    table_created=True
    seeded=clients['pdo'].call({'op':'setup'})
    assert int(seeded['documents'])==args.documents
    print('Seeded',args.documents,'documents, HNSW 128 dimensions; run',run_id,flush=True)
    scenarios=['get20','rows_only20','match20','match_meta20','match_meta1000','structured1000','json_mva20',
        'ranking20','facets20','highlight20','knn20','paginate61','chunk','lazy','paged_cursor','native_cursor',
        'write_insert500','write_replace500','write_update','write_delete']
    for scenario in scenarios:
        available=['v3','v4','pdo'] if scenario!='native_cursor' else ['v4','pdo']
        samples={c:[] for c in available};digests=set();traces={}
        is_write=scenario.startswith('write_')
        repetitions=10 if is_write else (5 if scenario in ['chunk','lazy','paged_cursor','native_cursor'] else args.read_samples)
        for phase in ['warmup','measurement']:
            rounds=3 if phase=='warmup' else repetitions
            for i in range(rounds):
                for client in rotate(i,available):
                    if is_write:clients['pdo'].call({'op':'reset_write','client':client,'scenario':scenario})
                    value=clients[client].call({'op':'measure','scenario':scenario,'capture':phase=='warmup' and i==2})
                    digests.add(value['digest'])
                    if len(digests)!=1:raise RuntimeError('Result digest differs for '+scenario+': '+json.dumps({'client':client,'value':value,'traces':traces})[:12000])
                    if phase=='measurement':
                        value.pop('sample',None);value.pop('sql',None);samples[client].append(value)
                    elif i==2:traces[client]={'queries':value['queries'],'sql':[s[:2000] for s in value['sql']],'digest':value['digest']}
        totals={c:summary(values) for c,values in samples.items()}
        if 'v3' in totals:totals['v4_over_v3']=totals['v4']['median_ms']/totals['v3']['median_ms']
        report['preflight'][scenario]=traces
        report['results'][scenario]={'summary':totals,'samples':samples,'matching_digest':next(iter(digests))}
        checkpoint();print(scenario,json.dumps(totals),flush=True)

    if not args.skip_http_queue:
        urls={}
        for client,root in roots.items():
            dest=root/'verification/ab';dest.mkdir(exist_ok=True)
            for name in ['adapter.php','fixtures.php','http-route.php']:
                (dest/name).write_bytes((source/name).read_bytes())
            (dest/'config.json').write_text(json.dumps(dict(config,client=client,count_sql=False)))
            (root/'app/Jobs/DriverCompareJob.php').write_bytes((source/'DriverCompareJob.php').read_bytes())
            routes=root/'routes/web.php'
            route="\nrequire base_path('verification/ab/http-route.php');\n"
            if route not in routes.read_text():routes.write_text(routes.read_text()+route)
            listener=socket.socket();listener.bind(('127.0.0.1',0));port=listener.getsockname()[1];listener.close()
            log=open(config_path.with_name(run_id+'-'+client+'-http.log'),'w')
            process=subprocess.Popen([str(root/'bin/php'),'-d','xdebug.mode=off','-d','opcache.enable_cli=1','-d','memory_limit=768M','-S','127.0.0.1:'+str(port),'-t',str(root/'public')],cwd=root,stdin=subprocess.DEVNULL,stdout=log,stderr=log)
            servers.append((process,log));urls[client]='http://127.0.0.1:'+str(port)+'/__driver_benchmark'
        for scenario in ['match_meta20','structured1000']:
            samples={c:[] for c in roots};digests=set()
            for i in range(70):
                for client in rotate(i,list(roots)):
                    start=time.perf_counter()
                    for attempt in range(30):
                        try:
                            with urllib.request.urlopen(urls[client]+'?scenario='+scenario,timeout=30) as response:
                                body=response.read();queries=None
                                assert response.headers['X-AB-Run']==run_id
                            break
                        except ConnectionRefusedError:
                            if i>0:raise
                            time.sleep(.1)
                        except urllib.error.URLError:
                            if i>0:raise
                            time.sleep(.1)
                    else:raise RuntimeError('HTTP server unavailable')
                    elapsed=(time.perf_counter()-start)*1000
                    digest=hashlib.sha256(body).hexdigest();digests.add(digest)
                    if len(digests)!=1:raise RuntimeError('HTTP response mismatch: '+scenario)
                    if i>=20:samples[client].append({'api_ms':elapsed,'queries':queries})
            report['http'][scenario]={'summary':{c:summary(values) for c,values in samples.items()},'samples':samples,'matching_digest':next(iter(digests))}
            checkpoint();print('HTTP',scenario,report['http'][scenario]['summary'],flush=True)
        queue_samples={c:[] for c in roots};queue_digests=set()
        for round_number in range(4):
            for client in (['v3','v4'] if round_number%2==0 else ['v4','v3']):
                root=roots[client];token=run_id+'-'+client+'-'+str(round_number);queue='abcmp-'+token
                clients[client].call({'op':'enqueue','token':token,'queue':queue,'jobs':12})
                log_path=config_path.with_name(token+'-queue.log')
                with open(log_path,'w') as log:
                    subprocess.run([str(root/'bin/php'),'-d','xdebug.mode=off','-d','memory_limit=768M','artisan','queue:work','database','--queue='+queue,'--stop-when-empty','--sleep=0','--tries=1','--timeout=60','--memory=512'],cwd=root,stdout=log,stderr=log,check=True,timeout=180)
                rows=[json.loads(line) for line in (root/'storage/app'/('ab-queue-'+token+'.jsonl')).read_text().splitlines()]
                assert len(rows)==12 and len({r['pid'] for r in rows})==1
                for row in rows:queue_digests.add(row['digest'])
                assert len(queue_digests)==1,'Queue results differ'
                queue_samples[client].extend(rows[2:])
        report['queue']={'summary':{c:summary(values,'ms') for c,values in queue_samples.items()},'samples':queue_samples,
            'rounds':4,'jobs_per_round':12,'warmup_jobs_per_round':2,'operations_per_job':15,'matching_digest':next(iter(queue_digests))}
        checkpoint();print('QUEUE',report['queue']['summary'],flush=True)
    report['ok']=True
except BaseException as error:
    report['ok']=False;report['error']=str(error);raise
finally:
    for process,log in servers:
        if process.poll() is None:process.terminate();process.wait(timeout=10)
        log.close()
    if table_created and 'pdo' in clients:
        for name in [config['read_table'],*config['write_tables'].values()]:
            try:clients['pdo'].call({'op':'sql','sql':'DROP TABLE IF EXISTS '+name})
            except Exception as error:report.setdefault('cleanup_errors',[]).append(str(error))
    for worker in clients.values():worker.close()
    checkpoint()
