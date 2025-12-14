<?php

declare(strict_types=1);

return [
    'name' => 'Synapse Sentinel Gate',
    'version' => app('git.version'),
    'env' => 'development',
    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],
];
