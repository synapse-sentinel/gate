<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;

final class PintFormatter implements CheckInterface
{
    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
    ) {}

    public function name(): string
    {
        return 'Pint Style';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $binary = $workingDirectory.'/vendor/bin/pint';

        if (! file_exists($binary)) {
            return CheckResult::pass('Pint not installed — skipped');
        }

        $result = $this->processRunner->run(
            [$binary, '--test', '--format=json'],
            $workingDirectory,
            timeout: 120,
        );

        $data = json_decode($result->output, true);

        if ($data === null) {
            if ($result->exitCode === 0) {
                return CheckResult::pass('Code style is clean');
            }

            return CheckResult::fail(
                'Pint check failed',
                [substr($result->output, 0, 500)],
                $result->output,
            );
        }

        if (($data['result'] ?? '') === 'pass') {
            return CheckResult::pass('Code style is clean');
        }

        $files = $data['files'] ?? [];
        $details = array_map(
            fn (array $f): string => $f['path'].' ('.implode(', ', $f['fixers'] ?? []).')',
            array_slice($files, 0, 20),
        );

        return CheckResult::fail(
            count($files).' file(s) need formatting',
            $details,
            $result->output,
        );
    }
}
