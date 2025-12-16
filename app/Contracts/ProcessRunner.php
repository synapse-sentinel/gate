<?php

declare(strict_types=1);

namespace App\Contracts;

interface ProcessRunner
{
    /**
     * Run a command and return the result.
     *
     * @param  array<string>  $command
     */
    public function run(array $command, string $workingDirectory, int $timeout = 120): ProcessResult;
}
