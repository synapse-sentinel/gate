<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\TestRunner;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

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
            info("Tests & Coverage ✓");
            return self::SUCCESS;
        }

        error("Tests & Coverage ✗");
        error($result->message);

        foreach ($result->details as $detail) {
            $this->line("  {$detail}");
        }

        return self::FAILURE;
    }
}
