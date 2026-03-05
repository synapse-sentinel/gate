<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, string $workingDirectory, int $timeout = 120): ProcessResult
    {
        $process = new Process($command, $workingDirectory, timeout: $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return new ProcessResult(
                successful: false,
                output: "Process timed out after {$timeout} seconds: ".$e->getMessage(),
                exitCode: 124,
            );
        } catch (ProcessSignaledException $e) {
            return new ProcessResult(
                successful: false,
                output: 'Process killed by signal: '.$e->getMessage(),
                exitCode: $e->getProcess()->getExitCode() ?? 137,
            );
        }

        return new ProcessResult(
            successful: $process->isSuccessful(),
            output: $process->getOutput().$process->getErrorOutput(),
            exitCode: $process->getExitCode() ?? 1,
        );
    }
}
