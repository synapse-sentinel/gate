<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\TestRunner;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

final class TestsCommand extends Command
{
    protected $signature = 'tests
        {--coverage=80 : Minimum coverage threshold percentage}
        {--token= : GitHub token for Checks API}';

    protected $description = 'Run tests with coverage check';

    public function handle(): int
    {
        $threshold = (int) $this->option('coverage');
        $token = $this->option('token') ?: getenv('GITHUB_TOKEN') ?: null;
        $checksClient = new ChecksClient($token);

        $check = new TestRunner($threshold);
        $result = $check->run(getcwd());

        // Report to GitHub Checks API
        $checksClient->reportCheck(
            name: 'Tests & Coverage',
            passed: $result->passed,
            title: $result->passed ? 'Passed' : 'Failed',
            summary: $result->message,
        );

        if ($result->passed) {
            $this->line('<info>Tests & Coverage</info> <fg=green>✓</>');
            return self::SUCCESS;
        }

        $this->line('<info>Tests & Coverage</info> <fg=red>✗</>');
        $this->newLine();
        $this->outputFailure($result->message, $result->details);

        return self::FAILURE;
    }

    private function outputFailure(string $message, array $details): void
    {
        $this->error("  {$message}");

        foreach ($details as $detail) {
            $this->line("  <fg=gray>•</> {$detail}");
        }
    }
}
