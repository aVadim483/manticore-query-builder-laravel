<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use Illuminate\Support\Facades\Facade as BaseFacade;

/**
 * Class Facade
 *
 * @package avadim\Manticore\Laravel
 */
class Facade extends BaseFacade
{
    /**
     * {@inheritdoc}
     */
    protected static function getFacadeAccessor(): string
    {
        return 'manticore';
    }
}
