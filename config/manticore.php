<?php

/**
 * Default configuration of ManticoreSearch client, all available parameters
 * can be found here https://github.com/manticoresoftware/manticoresearch-php/blob/master/docs/configuration.md
 */
return [

    'defaultConnection' => env('MANTICORE_CONNECTION', 'default'),

    'connections' => [

        // Default connection which will be used with environment variables
        'default' => [
            'host'          => env('MANTICORE_HOST', 'localhost'),
            'port'          => env('MANTICORE_PORT', 9306),
            'username'      => env('MANTICORE_USER', null),
            'password'      => env('MANTICORE_PASS', null),
            'timeout'       => env('MANTICORE_TIMEOUT', 5),
            'prefix'        => '',
            'force_prefix'  => false,
        ],
    ],
];
