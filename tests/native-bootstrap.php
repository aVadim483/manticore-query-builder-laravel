<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// All tests must remain independent of the standalone PHP builder.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'avadim\\Manticore\\QueryBuilder\\')) {
        throw new LogicException('The native Laravel driver requested a legacy class: '.$class);
    }
}, true, true);
