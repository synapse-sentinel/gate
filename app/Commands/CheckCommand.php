<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;
use LaravelZero\Framework\Commands\Command;

class CheckCommand extends Command
{
    protected $signature = 'check
        {--format=pretty : Output format (pretty, json, minimal)}
        {--no-tests : Skip test execution}
        {--no-phpstan : Skip PHPStan analysis}
        {--no-style : Skip style checks}';

    protected $description = 'Run all quality gate checks';

    /** @var array<string, mixed> */
    protected array $results = [
        'coverage' => null,
        'phpstan' => null,
        'style' => null,
        'verdict' => 'pending',
    ];

    private ?ProcessRunner $processRunner = null;

    /** @internal For testing only */
    public function withMocks(?ProcessRunner $processRunner = null): self
    {
        $this->processRunner = $processRunner;

        return $this;
    }

    public function handle(): int
    {
        $processRunner = $this->processRunner ?? new SymfonyProcessRunner;

        $this->info('🔒 Synapse Sentinel Gate');
        $this->newLine();

        if (! $this->option('no-tests')) {
            $this->runTests($processRunner);
        }

        if (! $this->option('no-phpstan')) {
            $this->runPhpstan($processRunner);
        }

        if (! $this->option('no-style')) {
            $this->runStyle($processRunner);
        }

        return $this->outputResults();
    }

    protected function runTests(ProcessRunner $processRunner): void
    {
        $this->task('Running tests with coverage', function () use ($processRunner) {
            $result = $processRunner->run(
                command: [
                    'vendor/bin/pest',
                    '--coverage',
                    '--coverage-clover=coverage.xml',
                    '--no-interaction',
                ],
                workingDirectory: getcwd(),
                timeout: 300,
            );

            $this->results['tests'] = [
                'success' => $result->successful,
                'output' => $result->output,
                'exit_code' => $result->exitCode,
            ];

            // Parse coverage from output
            if (preg_match('/Coverage:\s+([\d.]+)%/', $result->output, $matches)) {
                $this->results['coverage'] = [
                    'percentage' => (float) $matches[1],
                    'meets_threshold' => (float) $matches[1] >= 100,
                ];
            }

            return $result->successful;
        });
    }

    protected function runPhpstan(ProcessRunner $processRunner): void
    {
        $this->task('Running PHPStan analysis', function () use ($processRunner) {
            $result = $processRunner->run(
                command: [
                    'vendor/bin/phpstan',
                    'analyse',
                    '--error-format=json',
                    '--no-progress',
                ],
                workingDirectory: getcwd(),
                timeout: 120,
            );

            $output = json_decode($result->output, true) ?? [];

            $this->results['phpstan'] = [
                'success' => $result->successful,
                'errors' => $output['totals']['errors'] ?? 0,
                'files' => $output['files'] ?? [],
            ];

            return $result->successful;
        });
    }

    protected function runStyle(ProcessRunner $processRunner): void
    {
        $this->task('Checking code style', function () use ($processRunner) {
            $result = $processRunner->run(
                command: [
                    'vendor/bin/pint',
                    '--test',
                ],
                workingDirectory: getcwd(),
                timeout: 60,
            );

            $this->results['style'] = [
                'success' => $result->successful,
                'output' => $result->output,
            ];

            return $result->successful;
        });
    }

    protected function outputResults(): int
    {
        $format = $this->option('format');

        // Determine verdict
        $allPassed =
            ($this->results['tests']['success'] ?? true) &&
            ($this->results['phpstan']['success'] ?? true) &&
            ($this->results['style']['success'] ?? true) &&
            ($this->results['coverage']['meets_threshold'] ?? true);

        $this->results['verdict'] = $allPassed ? 'APPROVED' : 'REJECTED';

        if ($format === 'json') {
            $this->line(json_encode($this->results, JSON_PRETTY_PRINT));
        } elseif ($format === 'minimal') {
            $this->outputMinimal();
        } else {
            $this->outputPretty();
        }

        return $allPassed ? 0 : 1;
    }

    protected function outputMinimal(): void
    {
        if ($this->results['verdict'] === 'APPROVED') {
            $this->info('GATE APPROVED');

            return;
        }

        $this->error('GATE REJECTED');
        $this->newLine();

        // Only show failures
        if (! ($this->results['coverage']['meets_threshold'] ?? true)) {
            $this->line(sprintf(
                'Coverage: %.1f%% (need 100%%)',
                $this->results['coverage']['percentage'] ?? 0
            ));
        }

        if (($this->results['phpstan']['errors'] ?? 0) > 0) {
            $this->line(sprintf(
                'PHPStan: %d errors',
                $this->results['phpstan']['errors']
            ));
        }

        if (! ($this->results['style']['success'] ?? true)) {
            $this->line('Style: violations found');
        }
    }

    protected function outputPretty(): void
    {
        $this->newLine();

        if ($this->results['verdict'] === 'APPROVED') {
            $this->info('✅ GATE APPROVED');
        } else {
            $this->error('❌ GATE REJECTED');
            $this->newLine();
            $this->warn('Fix the issues above before committing.');
        }
    }
}
