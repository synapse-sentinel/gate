<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

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

    public function handle(): int
    {
        $this->info('🔒 Synapse Sentinel Gate');
        $this->newLine();

        if (! $this->option('no-tests')) {
            $this->runTests();
        }

        if (! $this->option('no-phpstan')) {
            $this->runPhpstan();
        }

        if (! $this->option('no-style')) {
            $this->runStyle();
        }

        return $this->outputResults();
    }

    protected function runTests(): void
    {
        $this->task('Running tests with coverage', function () {
            $process = new Process([
                'vendor/bin/pest',
                '--coverage',
                '--coverage-clover=coverage.xml',
                '--no-interaction',
            ]);
            $process->setTimeout(300);
            $process->run();

            $this->results['tests'] = [
                'success' => $process->isSuccessful(),
                'output' => $process->getOutput(),
                'exit_code' => $process->getExitCode(),
            ];

            // Parse coverage from output
            if (preg_match('/Coverage:\s+([\d.]+)%/', $process->getOutput(), $matches)) {
                $this->results['coverage'] = [
                    'percentage' => (float) $matches[1],
                    'meets_threshold' => (float) $matches[1] >= 100,
                ];
            }

            return $process->isSuccessful();
        });
    }

    protected function runPhpstan(): void
    {
        $this->task('Running PHPStan analysis', function () {
            $process = new Process([
                'vendor/bin/phpstan',
                'analyse',
                '--error-format=json',
                '--no-progress',
            ]);
            $process->setTimeout(120);
            $process->run();

            $output = json_decode($process->getOutput(), true) ?? [];

            $this->results['phpstan'] = [
                'success' => $process->isSuccessful(),
                'errors' => $output['totals']['errors'] ?? 0,
                'files' => $output['files'] ?? [],
            ];

            return $process->isSuccessful();
        });
    }

    protected function runStyle(): void
    {
        $this->task('Checking code style', function () {
            $process = new Process([
                'vendor/bin/pint',
                '--test',
            ]);
            $process->setTimeout(60);
            $process->run();

            $this->results['style'] = [
                'success' => $process->isSuccessful(),
                'output' => $process->getOutput(),
            ];

            return $process->isSuccessful();
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
