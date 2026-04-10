<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;

final class RectorAnalyzer implements CheckInterface
{
    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
    ) {}

    public function name(): string
    {
        return 'Rector';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $binary = $workingDirectory.'/vendor/bin/rector';

        if (! file_exists($binary)) {
            return CheckResult::pass('Rector not installed — skipped');
        }

        if (! file_exists($workingDirectory.'/rector.php')) {
            return CheckResult::pass('No rector.php config — skipped');
        }

        $result = $this->processRunner->run(
            [$binary, 'process', '--dry-run', '--no-progress-bar'],
            $workingDirectory,
            timeout: 300,
        );

        if ($result->exitCode === 0) {
            return CheckResult::pass('Rector found no issues');
        }

        // Count suggested changes from output
        $lines = explode("\n", trim($result->output));
        $changeCount = 0;
        $details = [];

        foreach ($lines as $line) {
            if (str_contains($line, 'diff')) {
                $changeCount++;
            }
            if (str_starts_with(trim($line), '---') || str_starts_with(trim($line), '+++')) {
                $details[] = trim($line);
            }
        }

        return CheckResult::fail(
            'Rector found '.max($changeCount, 1).' file(s) needing refactoring',
            array_slice($details, 0, 20),
            $result->output,
        );
    }
}
