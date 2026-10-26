"""Read-only verification for the existing migration-test host after this experiment."""
import argparse
import hashlib
import json
import pathlib
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--apps', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
apps = pathlib.Path(args.apps)
live = apps / 'manticore-migration'
manifest = json.loads((apps / 'migration-published-4.0.0/source-manifest.json').read_text())
for root in [live, apps / 'manticore-benchmark-v4']:
    for entry in manifest['files']:
        actual = hashlib.sha256((root / 'vendor/avadim/manticore-query-builder-laravel' / entry['path']).read_bytes()).hexdigest()
        assert actual == entry['sha256'], (root, entry['path'])
code = r'''require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$catalog=$app->make(App\Services\Catalog::class);
$baseline=json_decode(file_get_contents(storage_path('app/migration-v3.json')),true);
if($catalog->fingerprint()!==$baseline['fingerprint_after'] || app()->isDownForMaintenance())throw new RuntimeException('Public application changed');
$pdo=new PDO('mysql:host=127.0.0.1;port=19306');
$tables=$pdo->query("SHOW TABLES LIKE 'pipe_%'")->fetchAll(PDO::FETCH_ASSOC);
if($tables)throw new RuntimeException('Pipeline scratch tables remain');
echo json_encode(['ok'=>true,'package'=>Composer\InstalledVersions::getPrettyVersion('avadim/manticore-query-builder-laravel'),
'documents'=>$catalog->query()->where('id','<=',2105)->count(),'fingerprint'=>$catalog->fingerprint(),
'temporary_tables'=>count($tables),'maintenance'=>false],JSON_PRETTY_PRINT).PHP_EOL;
'''
value = json.loads(subprocess.check_output([str(live / 'bin/php'), '-r', code], cwd=live, text=True))
assert value['documents'] == 2105 and value['package'] == 'v4.0.0'
value['published_source_files_verified'] = len(manifest['files'])
pathlib.Path(args.output).write_text(json.dumps(value, indent=2))
print(json.dumps(value, indent=2))
