import pathlib,subprocess,json,statistics,argparse,time
parser=argparse.ArgumentParser()
parser.add_argument('--v3',required=True);parser.add_argument('--v4',required=True);parser.add_argument('--output',required=True)
parser.add_argument('--prototype',action='store_true')
parser.add_argument('--prototype-profile',action='store_true')
args=parser.parse_args();source=pathlib.Path(__file__).resolve().parent
workers={};report={'created_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'environment':{},'cases':{}}
def call(client,message):
    process=workers[client];process.stdin.write(json.dumps(message)+'\n');process.stdin.flush()
    line=process.stdout.readline()
    if not line:raise RuntimeError(client+' exited')
    result=json.loads(line)
    if not result.get('ok'):raise RuntimeError(client+' '+str(result))
    return result
try:
    for client,root in [('v3',args.v3),('v4',args.v4)]:
        process=subprocess.Popen([str(pathlib.Path(root)/'bin/php'),'-d','xdebug.mode=off','-d','memory_limit=512M',str(source/'worker.php'),root,client],stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True,bufsize=1)
        workers[client]=process;report['environment'][client]=json.loads(process.stdout.readline())
    scenarios=[('normal',1),('normal',100),('normal',500),('normal',1000),('scalar',500),('float_integer_values',500),('integer_values',500)]
    if args.prototype:scenarios=[('normal',100),('normal',500),('normal',1000)]
    sample_clients=['v3','v4','prototype']if args.prototype else ['v3','v4']
    for variant,size in scenarios:
        for verb in ['insert','replace']:
            name=f'{verb}_{variant}_{size}';samples={c:[] for c in sample_clients};digests=set()
            for i in range(13):
                order=sample_clients[i%len(sample_clients):]+sample_clients[:i%len(sample_clients)]
                for client in order:
                    result=call('v4'if client=='prototype'else client,{'op':'measure','variant':variant,'size':size,'verb':verb,'profile':args.prototype_profile or not args.prototype,'prototype':client=='prototype'})
                    digests.add(result['digest'])
                    assert len(digests)==1,'Data mismatch: '+name
                    if i>=3:samples[client].append(result)
            summary={}
            for client,values in samples.items():
                stages={}
                for stage in values[0]['stages']:
                    stages[stage]={key:statistics.median(v['stages'][stage][key]for v in values)for key in ['inclusive_ms','exclusive_ms','calls']}
                summary[client]={'median_ms':statistics.median(v['total_ms']for v in values),'stages':stages}
            report['cases'][name]={'summary':summary,'samples':samples,'digest':next(iter(digests))}
            pathlib.Path(args.output).write_text(json.dumps(report,indent=2));print(name,json.dumps(summary),flush=True)
    report['ok']=True
finally:
    for process in workers.values():
        if process.poll()is None:process.stdin.write('{"op":"quit"}\n');process.stdin.flush();process.wait(timeout=15)
    pathlib.Path(args.output).write_text(json.dumps(report,indent=2))
