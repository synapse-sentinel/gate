<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;

return [
    'name' => 'Synapse Sentinel Gate',
    'version' => app('git.version'),
    'env' => 'development',
    'providers' => [
        AppServiceProvider::class,
    ],
];
