<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\CheckInterface;
use App\Checks\PestSyntaxValidator;
use App\Checks\SecurityScanner;
use App\Checks\TestRunner;
use App\GitHub\ChecksClient;
use App\Verdict;
use LaravelZero\Framework\Commands\Command;

final class CertifyCommand extends Command
{
    protected $signature = 'certify
        {--coverage=80 : Minimum coverage threshold percentage}
        {--token= : GitHub token for Checks API}';

    protected $description = 'Run all checks and issue Sentinel Certification';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<fg=blue>🛡️  Synapse Sentinel Gate</>');
        $this->newLine();

        $coverageThreshold = (int) $this->option('coverage');
        $token = $this->option('token') ?: getenv('GITHUB_TOKEN') ?: null;
        $checksClient = new ChecksClient($token);
        $workingDirectory = getcwd();

        $checks = [
            new TestRunner($coverageThreshold),
            new SecurityScanner(),
            new PestSyntaxValidator(),
        ];

        $failures = [];
        $failureDetails = [];

        foreach ($checks as $check) {
            $result = $this->runCheck($check, $workingDirectory, $checksClient);
            if (! $result->passed) {
                $failures[] = "[{$check->name()}] {$result->message}";
                $failureDetails[$check->name()] = [
                    'message' => $result->message,
                    'details' => $result->details,
                ];
            }
        }

        $this->newLine();

        // Determine overall verdict
        $verdict = empty($failures)
            ? Verdict::approved('All checks passed')
            : Verdict::rejected('Certification failed', $failures);

        // Report Sentinel Certification
        $checksClient->reportCheck(
            name: 'Sentinel Certification',
            passed: $verdict->isApproved(),
            title: $verdict->isApproved() ? 'Certified' : 'Not Certified',
            summary: $verdict->toMarkdown(),
        );

        // Output verdict
        if ($verdict->isApproved()) {
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info('  ✓ SENTINEL CERTIFICATION: APPROVED');
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        } else {
            $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->error('  ✗ SENTINEL CERTIFICATION: REJECTED');
            $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->newLine();

            // Show failure details
            foreach ($failureDetails as $checkName => $data) {
                $this->line("<fg=red>  {$checkName}:</>");
                $this->line("    {$data['message']}");
                foreach ($data['details'] as $detail) {
                    $this->line("    <fg=gray>•</> {$detail}");
                }
                $this->newLine();
            }
        }

        // Write GitHub Step Summary
        $summaryFile = getenv('GITHUB_STEP_SUMMARY');
        if ($summaryFile !== false && $summaryFile !== '') {
            file_put_contents($summaryFile, $verdict->toMarkdown(), FILE_APPEND);
        }

        // Write GitHub Output
        $outputFile = getenv('GITHUB_OUTPUT');
        if ($outputFile !== false && $outputFile !== '') {
            file_put_contents($outputFile, "verdict={$verdict->status()}\n", FILE_APPEND);
            file_put_contents($outputFile, "reason={$verdict->reason()}\n", FILE_APPEND);
        }

        return $verdict->exitCode();
    }

    private function runCheck(
        CheckInterface $check,
        string $workingDirectory,
        ChecksClient $checksClient,
    ): \App\Checks\CheckResult {
        $result = $check->run($workingDirectory);

        // Report to GitHub Checks API
        $checksClient->reportCheck(
            name: $check->name(),
            passed: $result->passed,
            title: $result->passed ? 'Passed' : 'Failed',
            summary: $result->message,
        );

        // Console output - minimal
        if ($result->passed) {
            $this->line("<info>{$check->name()}</info> <fg=green>✓</>");
        } else {
            $this->line("<info>{$check->name()}</info> <fg=red>✗</>");
        }

        return $result;
    }
}
