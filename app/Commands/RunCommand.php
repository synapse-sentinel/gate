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

final class RunCommand extends Command
{
    protected $signature = 'run
        {--coverage=100 : Minimum coverage threshold percentage}
        {--repo= : Repository name (e.g., owner/repo)}
        {--pr= : Pull request number}
        {--token= : GitHub token for Checks API}';

    protected $description = 'Run quality gate checks on the current repository';

    public function handle(): int
    {
        $this->info('🛡️  Synapse Sentinel Gate');
        $this->newLine();

        $coverageThreshold = (int) $this->option('coverage');
        $workingDirectory = getcwd();
        $token = $this->option('token') ?: getenv('GITHUB_TOKEN') ?: null;

        // Initialize GitHub Checks client
        $checksClient = new ChecksClient($token);

        // Build the checks
        $checks = [
            new TestRunner($coverageThreshold),
            new SecurityScanner(),
            new PestSyntaxValidator(),
        ];

        // Run checks and report individually
        $failures = [];
        foreach ($checks as $check) {
            $result = $this->runAndReportCheck($check, $workingDirectory, $checksClient);
            if (! $result->passed) {
                $failures[] = "[{$check->name()}] {$result->message}";
            }
        }

        // Determine overall verdict
        $verdict = empty($failures)
            ? Verdict::approved('All checks passed')
            : Verdict::rejected('Technical gate failed', $failures);

        // Report Sentinel Certification (main verdict)
        $this->reportSentinelCertification($checksClient, $verdict);

        // Output results to console
        $this->outputResults($verdict);

        // Write GitHub Step Summary if available
        $this->writeGitHubSummary($verdict);

        // Write GitHub Output if available
        $this->writeGitHubOutput($verdict);

        // Output annotations for GitHub Actions
        if ($verdict->isRejected()) {
            $this->newLine();
            echo $verdict->toAnnotations();
        }

        return $verdict->exitCode();
    }

    private function runAndReportCheck(
        CheckInterface $check,
        string $workingDirectory,
        ChecksClient $checksClient,
    ): \App\Checks\CheckResult {
        $this->line("Running {$check->name()}...");

        $result = $check->run($workingDirectory);

        // Report to GitHub Checks API
        $checksClient->reportCheck(
            name: $check->name(),
            passed: $result->passed,
            title: $result->passed ? 'Passed' : 'Failed',
            summary: $result->message,
        );

        // Console output
        if ($result->passed) {
            $this->info("  ✅ {$check->name()}: {$result->message}");
        } else {
            $this->error("  ❌ {$check->name()}: {$result->message}");
        }

        return $result;
    }

    private function reportSentinelCertification(ChecksClient $checksClient, Verdict $verdict): void
    {
        $checksClient->reportCheck(
            name: 'Sentinel Certification',
            passed: $verdict->isApproved(),
            title: $verdict->isApproved() ? '✅ Certified' : '❌ Not Certified',
            summary: $verdict->toMarkdown(),
        );
    }

    private function outputResults(Verdict $verdict): void
    {
        $this->newLine();

        if ($verdict->isApproved()) {
            $this->info('═══════════════════════════════════════');
            $this->info('  ✅ SENTINEL CERTIFICATION: APPROVED');
            $this->info('═══════════════════════════════════════');
        } else {
            $this->error('═══════════════════════════════════════');
            $this->error('  ❌ SENTINEL CERTIFICATION: REJECTED');
            $this->error('═══════════════════════════════════════');
            $this->newLine();

            foreach ($verdict->failures() as $failure) {
                $this->line("  • {$failure}");
            }
        }
    }

    private function writeGitHubSummary(Verdict $verdict): void
    {
        $summaryFile = getenv('GITHUB_STEP_SUMMARY');
        if ($summaryFile !== false && $summaryFile !== '') {
            file_put_contents($summaryFile, $verdict->toMarkdown(), FILE_APPEND);
        }
    }

    private function writeGitHubOutput(Verdict $verdict): void
    {
        $outputFile = getenv('GITHUB_OUTPUT');
        if ($outputFile !== false && $outputFile !== '') {
            file_put_contents($outputFile, "verdict={$verdict->status()}\n", FILE_APPEND);
            file_put_contents($outputFile, "reason={$verdict->reason()}\n", FILE_APPEND);
        }
    }
}
