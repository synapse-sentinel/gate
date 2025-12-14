<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\PestSyntaxValidator;
use App\Checks\SecurityScanner;
use App\Checks\TestRunner;
use App\Stages\TechnicalGate;
use LaravelZero\Framework\Commands\Command;

final class RunCommand extends Command
{
    protected $signature = 'run
        {--coverage=100 : Minimum coverage threshold percentage}
        {--repo= : Repository name (e.g., owner/repo)}
        {--pr= : Pull request number}';

    protected $description = 'Run quality gate checks on the current repository';

    public function handle(): int
    {
        $this->info('Synapse Sentinel Gate');
        $this->newLine();

        $coverageThreshold = (int) $this->option('coverage');
        $workingDirectory = getcwd();

        // Build the checks
        $checks = [
            new TestRunner($coverageThreshold),
            new SecurityScanner(),
            new PestSyntaxValidator(),
        ];

        // Run the technical gate
        $gate = new TechnicalGate($checks);
        $verdict = $gate->run($workingDirectory);

        // Output results
        if ($verdict->isApproved()) {
            $this->info('✅ ' . $verdict->reason());
        } else {
            $this->error('❌ ' . $verdict->reason());
            $this->newLine();

            foreach ($verdict->failures() as $failure) {
                $this->line("  • {$failure}");
            }
        }

        // Write GitHub Step Summary if available
        $summaryFile = getenv('GITHUB_STEP_SUMMARY');
        if ($summaryFile !== false && $summaryFile !== '') {
            file_put_contents($summaryFile, $verdict->toMarkdown(), FILE_APPEND);
        }

        // Write GitHub Output if available
        $outputFile = getenv('GITHUB_OUTPUT');
        if ($outputFile !== false && $outputFile !== '') {
            file_put_contents($outputFile, "verdict={$verdict->status()}\n", FILE_APPEND);
            file_put_contents($outputFile, "reason={$verdict->reason()}\n", FILE_APPEND);
        }

        // Output annotations for GitHub Actions
        if ($verdict->isRejected()) {
            $this->newLine();
            echo $verdict->toAnnotations();
        }

        return $verdict->exitCode();
    }
}
