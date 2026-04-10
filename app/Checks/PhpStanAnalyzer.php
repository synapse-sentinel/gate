<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;

final class PhpStanAnalyzer implements CheckInterface
{
    public function __construct(
        private readonly int $level = 5,
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
    ) {}

    public function name(): string
    {
        return 'PHPStan';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $binary = $workingDirectory.'/vendor/bin/phpstan';

        if (! file_exists($binary)) {
            return CheckResult::pass('PHPStan not installed — skipped');
        }

        $result = $this->processRunner->run(
            [$binary, 'analyse', '--no-progress', '--error-format=json', "--level={$this->level}", '--memory-limit=512M'],
            $workingDirectory,
            timeout: 300,
        );

        $data = json_decode($result->output, true);

        if ($data === null) {
            // Fall back to exit code if JSON parsing fails
            if ($result->exitCode === 0) {
                return CheckResult::pass('PHPStan passed (level '.$this->level.')');
            }

            return CheckResult::fail(
                'PHPStan failed',
                [substr($result->output, 0, 500)],
                $result->output,
            );
        }

        $errorCount = $data['totals']['errors'] ?? 0;
        $fileErrors = $data['totals']['file_errors'] ?? 0;
        $totalErrors = $errorCount + $fileErrors;

        if ($totalErrors === 0) {
            return CheckResult::pass('PHPStan passed (level '.$this->level.', 0 errors)');
        }

        $details = [];
        foreach ($data['files'] ?? [] as $file => $fileData) {
            foreach ($fileData['messages'] ?? [] as $msg) {
                $details[] = basename($file).':'.$msg['line'].' — '.$msg['message'];
            }
        }

        return CheckResult::fail(
            "PHPStan found {$totalErrors} error(s) at level {$this->level}",
            array_slice($details, 0, 20),
            $result->output,
        );
    }
}
