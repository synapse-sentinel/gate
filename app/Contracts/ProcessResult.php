<?php

declare(strict_types=1);

namespace App\Contracts;

final readonly class ProcessResult
{
    public function __construct(
        public bool $successful,
        public string $output,
        public int $exitCode = 0,
    ) {}
}
