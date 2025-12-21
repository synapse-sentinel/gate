<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;

final class SecurityScanner implements CheckInterface
{
    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner(),
    ) {}

    public function name(): string
    {
        return 'Security Audit';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $result = $this->processRunner->run(
            ['composer', 'audit', '--format=json'],
            $workingDirectory,
            timeout: 60,
        );

        $data = json_decode($result->output, true);

        // Handle JSON parsing errors
        if ($data === null) {
            return CheckResult::fail(
                'Failed to parse composer audit output',
                ['Raw output: '.substr($result->output, 0, 200)]
            );
        }

        // Handle composer errors
        if (isset($data['error'])) {
            return CheckResult::fail(
                'Composer audit error: '.$data['error'],
                []
            );
        }

        // Check for vulnerabilities
        $advisories = $data['advisories'] ?? [];

        if (empty($advisories)) {
            return CheckResult::pass('No security vulnerabilities found');
        }

        // Parse vulnerabilities
        $vulnerabilities = [];
        foreach ($advisories as $package => $issues) {
            foreach ($issues as $issue) {
                $cve = $issue['cve'] ?? 'N/A';
                $vulnerabilities[] = "{$package}: {$issue['title']} ({$cve})";
            }
        }

        return CheckResult::fail(
            'Security vulnerabilities found',
            $vulnerabilities
        );
    }
}
