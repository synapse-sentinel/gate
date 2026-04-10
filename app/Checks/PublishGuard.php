<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;

final class PublishGuard implements CheckInterface
{
    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
    ) {}

    public function name(): string
    {
        return 'Publish Guard';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $violations = [];

        // Check for .env files (excluding .env.testing and .env.example)
        $envResult = $this->processRunner->run(
            ['git', 'ls-files', '*.env', '.env*'],
            $workingDirectory,
            timeout: 10,
        );

        foreach (array_filter(explode("\n", trim($envResult->output))) as $file) {
            if (! str_ends_with($file, '.env.testing') && ! str_ends_with($file, '.env.example')) {
                $violations[] = "Tracked .env file: {$file}";
            }
        }

        // Check for source map files
        $mapResult = $this->processRunner->run(
            ['git', 'ls-files', '*.map'],
            $workingDirectory,
            timeout: 10,
        );

        foreach (array_filter(explode("\n", trim($mapResult->output))) as $file) {
            $violations[] = "Tracked .map file: {$file}";
        }

        // Check for large files (> 1MB)
        $lsResult = $this->processRunner->run(
            ['git', 'ls-files'],
            $workingDirectory,
            timeout: 10,
        );

        foreach (array_filter(explode("\n", trim($lsResult->output))) as $file) {
            $path = $workingDirectory.'/'.$file;
            if (file_exists($path) && filesize($path) > 1_048_576) {
                $size = round(filesize($path) / 1_048_576, 1);
                $violations[] = "Large file ({$size}MB): {$file}";
            }
        }

        if ($violations === []) {
            return CheckResult::pass('No disallowed artifacts found');
        }

        return CheckResult::fail(
            count($violations).' publish violation(s) found',
            $violations,
            implode("\n", $violations),
        );
    }
}
