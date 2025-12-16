<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, string $workingDirectory, int $timeout = 120): ProcessResult
    {
        $process = new Process($command, $workingDirectory, timeout: $timeout);
        $process->run();

        return new ProcessResult(
            successful: $process->isSuccessful(),
            output: $process->getOutput().$process->getErrorOutput(),
            exitCode: $process->getExitCode() ?? 1,
        );
    }
}
