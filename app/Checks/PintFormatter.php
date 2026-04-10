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
        $details = [];

        foreach (array_slice($files, 0, 20) as $f) {
            if (is_array($f) && isset($f['path'])) {
                $details[] = $f['path'].' ('.implode(', ', $f['fixers'] ?? []).')';
            } elseif (is_string($f)) {
                $details[] = $f;
            }
        }

        return CheckResult::fail(
            count($files).' file(s) need formatting',
            $details,
            $result->output,
        );
    }
}
