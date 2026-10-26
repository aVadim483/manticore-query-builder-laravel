<?php

declare(strict_types=1);

require $argv[1] ?? dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/Support/ApiContractInspector.php';

use avadim\Manticore\Laravel\Tests\Support\ApiContractInspector;
use Composer\InstalledVersions;

$errors = array_merge(ApiContractInspector::catalogErrors(), ApiContractInspector::signatureErrors());
if ($errors) {
    throw new RuntimeException(implode("\n", $errors));
}
$version = InstalledVersions::getPrettyVersion('illuminate/database')
    ?? (InstalledVersions::isInstalled('laravel/framework') ? InstalledVersions::getPrettyVersion('laravel/framework') : 'unknown');
echo 'OK: API catalog and 19 method contracts, Illuminate '.$version.PHP_EOL;
