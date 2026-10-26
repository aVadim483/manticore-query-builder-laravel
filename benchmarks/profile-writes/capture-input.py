import pathlib,subprocess,json,sys
root=pathlib.Path(sys.argv[1]);source=pathlib.Path(__file__).resolve().parent
process=subprocess.Popen([str(root/'bin/php'),'-d','xdebug.mode=off',str(source/'worker.php'),str(root),'v4'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True)
try:
    json.loads(process.stdout.readline())
    process.stdin.write(json.dumps({'op':'capture_numeric','size':500,'variant':'normal','verb':'replace'})+'\n');process.stdin.flush()
    captured=json.loads(process.stdout.readline());assert captured['ok']
    (source/'numeric-input.json').write_text(json.dumps(captured))
finally:
    process.stdin.write('{"op":"quit"}\n');process.stdin.flush();process.wait(timeout=10)
