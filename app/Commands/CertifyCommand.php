<?php

declare(strict_types=1);

namespace App\Commands;

use App\Branding;
use App\Checks\CheckInterface;
use App\Checks\PestSyntaxValidator;
use App\Checks\SecurityScanner;
use App\Checks\TestRunner;
use App\GitHub\ChecksClient;
use App\Verdict;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

final class CertifyCommand extends Command
{
    protected $signature = 'certify
        {--coverage=80 : Minimum coverage threshold percentage}
        {--token= : GitHub token for Checks API}
        {--stop-on-failure : Stop at first failing check}';

    protected $description = 'Run all checks and issue Sentinel Certification';

    /** @var array<CheckInterface>|null For testing */
    private ?array $checks = null;

    /** @var ChecksClient|null For testing */
    private ?ChecksClient $checksClient = null;

    public function __construct()
    {
        parent::__construct();
    }

    /** @internal For testing only */
    public function withMocks(?array $checks = null, ?ChecksClient $checksClient = null): self
    {
        $this->checks = $checks;
        $this->checksClient = $checksClient;

        return $this;
    }

    public function handle(): int
    {
        $coverageThreshold = (int) $this->option('coverage');
        $optionToken = $this->option('token');
        $envToken = getenv('GITHUB_TOKEN');
        $token = $optionToken ?: $envToken ?: null;

        $checksClient = $this->checksClient ?? new ChecksClient($token);
        $workingDirectory = getcwd();

        $checks = $this->checks ?? [
            new TestRunner($coverageThreshold),
            new SecurityScanner(),
            new PestSyntaxValidator(),
        ];

        $stopOnFailure = $this->option('stop-on-failure');
        $failures = [];
        $failureRows = [];
        $checkResults = [];

        foreach ($checks as $check) {
            $result = $this->runCheck($check, $workingDirectory, $checksClient);
            $checkResults[$check->name()] = $result->message;

            if (! $result->passed) {
                $failures[] = "[{$check->name()}] {$result->message}";
                foreach ($result->details as $detail) {
                    $failureRows[] = [$check->name(), $detail];
                }

                if ($stopOnFailure) {
                    break;
                }
            }
        }

        // Determine overall verdict
        $verdict = empty($failures)
            ? Verdict::approved('All checks passed')
            : Verdict::rejected('Certification failed', $failures);

        // Report Sentinel Certification (last, after all checks)
        $title = $verdict->isApproved()
            ? 'All checks passed'
            : count($failures) . ' check(s) failed';

        $checksClient->reportCheck(
            name: Branding::CERTIFICATION,
            passed: $verdict->isApproved(),
            title: $title,
            summary: $verdict->toMarkdown(),
        );

        // Post PR comment with badge on success
        if ($verdict->isApproved()) {
            $checksClient->postCertificationComment($checkResults);
        }

        // Output verdict
        if ($verdict->isApproved()) {
            info('');
            info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            info('  ✓ SENTINEL CERTIFICATION: APPROVED');
            info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        } else {
            error('');
            error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            error('  ✗ SENTINEL CERTIFICATION: REJECTED');
            error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

            if (! empty($failureRows)) {
                table(
                    headers: ['Check', 'Issue'],
                    rows: $failureRows
                );
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
            name: Branding::checkName($check->name()),
            passed: $result->passed,
            title: $result->message,
            summary: $result->message,
        );

        // Console output
        if ($result->passed) {
            info("{$check->name()} ✓");
        } else {
            error("{$check->name()} ✗");
        }

        return $result;
    }
}
