<?php

declare(strict_types=1);

return [
    'tests' => [
        'command' => 'vendor/bin/pest --coverage --min=%threshold%',
        'default_threshold' => 100,
    ],

    'security' => [
        'command' => 'composer audit --format=json',
        'block_on_vulnerability' => true,
    ],

    'pest_syntax' => [
        'require_describe_blocks' => true,
        'require_it_blocks' => true,
        'scan_paths' => ['tests/'],
        'forbidden_patterns' => [
            '/^\s*test\s*\(/m', // test() function calls
        ],
    ],
];
