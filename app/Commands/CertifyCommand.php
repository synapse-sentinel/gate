<?php

declare(strict_types=1);

namespace App\Commands;

use App\Branding;
use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Checks\PestSyntaxValidator;
use App\Checks\PhpStanAnalyzer;
use App\Checks\PintFormatter;
use App\Checks\PublishGuard;
use App\Checks\RectorAnalyzer;
use App\Checks\SecurityScanner;
use App\Checks\TestRunner;
use App\GitHub\ChecksClient;
use App\Services\PromptAssembler;
use App\Verdict;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

final class CertifyCommand extends Command
{
    protected $signature = 'certify
        {--coverage=80 : Minimum coverage threshold percentage}
        {--phpstan-level=5 : PHPStan analysis level (0-9)}
        {--token= : GitHub token for Checks API}
        {--stop-on-failure : Stop at first failing check}
        {--compact : Show single-line output instead of verbose}';

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
        $compact = (bool) $this->option('compact');

        $checksClient = $this->checksClient ?? new ChecksClient($token);
        $workingDirectory = getcwd();

        $phpstanLevel = (int) $this->option('phpstan-level');

        $checks = $this->checks ?? [
            new TestRunner($coverageThreshold),
            new PintFormatter,
            new PhpStanAnalyzer($phpstanLevel),
            new RectorAnalyzer,
            new SecurityScanner,
            new PestSyntaxValidator,
            new PublishGuard,
        ];

        $stopOnFailure = $this->option('stop-on-failure');
        $failures = [];
        $failureRows = [];
        $checkResults = [];
        $compactResults = [];
        $rawOutputs = []; // For prompt assembly

        foreach ($checks as $check) {
            $result = $this->runCheck($check, $workingDirectory, $checksClient, $compact);
            $checkResults[$check->name()] = $result->message;
            $compactResults[] = [
                'name' => $this->shortName($check->name()),
                'passed' => $result->passed,
                'message' => $result->message,
            ];

            // Collect raw outputs for prompt transformation
            $rawOutputs[$check->name()] = [
                'passed' => $result->passed,
                'output' => $result->rawOutput,
            ];

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
            : count($failures).' check(s) failed';

        $checksClient->reportCheck(
            name: Branding::CERTIFICATION,
            passed: $verdict->isApproved(),
            title: $title,
            summary: $verdict->toMarkdown(),
        );

        // Post PR comment with badge on success
        if ($verdict->isApproved()) {
            $checksClient->postCertificationComment($checkResults);
        } else {
            // Post actionable prompt with fix directions on failure
            $assembler = new PromptAssembler;
            $assembled = $assembler->assemble($rawOutputs);

            if ($assembled['prompt'] !== '') {
                $checksClient->postActionablePrompt($assembled['prompt']);
            }
        }

        // Output verdict
        if ($compact) {
            $this->renderCompactOutput($compactResults, $verdict);
        } elseif ($verdict->isApproved()) {
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
        bool $compact = false,
    ): CheckResult {
        if ($compact) {
            $result = $check->run($workingDirectory);
        } else {
            $result = spin(
                fn () => $check->run($workingDirectory),
                "Running {$check->name()}..."
            );
        }

        // Report to GitHub Checks API
        $checksClient->reportCheck(
            name: Branding::checkName($check->name()),
            passed: $result->passed,
            title: $result->message,
            summary: $result->message,
        );

        // Console output (skip in compact mode - we'll show summary at end)
        if (! $compact) {
            if ($result->passed) {
                info("{$check->name()} ✓");
            } else {
                error("{$check->name()} ✗");
            }
        }

        return $result;
    }

    private function shortName(string $name): string
    {
        return match ($name) {
            'Tests & Coverage' => 'Tests',
            'Security Audit' => 'Security',
            'Pest Syntax' => 'Syntax',
            'Pint Style' => 'Pint',
            'Publish Guard' => 'Publish',
            default => $name,
        };
    }

    private function renderCompactOutput(array $results, Verdict $verdict): void
    {
        $parts = [];
        foreach ($results as $r) {
            $icon = $r['passed'] ? '✓' : '✗';
            $parts[] = "{$r['name']} {$icon}";
        }

        $status = $verdict->isApproved() ? '✓ APPROVED' : '✗ REJECTED';
        $line = implode('  ', $parts)."  │  {$status}";

        if ($verdict->isApproved()) {
            info($line);
        } else {
            error($line);
        }
    }
}
