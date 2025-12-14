<?php

declare(strict_types=1);

namespace App\Checks;

use Symfony\Component\Process\Process;

final class SecurityScanner implements CheckInterface
{
    public function name(): string
    {
        return 'Security Audit';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $process = new Process(
            ['composer', 'audit', '--format=json'],
            $workingDirectory,
            timeout: 60,
        );

        $process->run();

        $output = $process->getOutput();
        $data = json_decode($output, true) ?? [];

        if ($process->isSuccessful() && empty($data['advisories'] ?? [])) {
            return CheckResult::pass('No security vulnerabilities found');
        }

        $advisories = $data['advisories'] ?? [];
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
