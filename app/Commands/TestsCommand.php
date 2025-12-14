<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\TestRunner;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

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

        $result = spin(
            fn () => $check->run(getcwd()),
            'Running tests...'
        );

        $checksClient->reportCheck(
            name: '🛡️ Tests & Coverage',
            passed: $result->passed,
            title: $result->passed ? $result->message : $result->message,
            summary: $this->formatSummary($result),
        );

        if ($result->passed) {
            info("Tests & Coverage ✓ {$result->message}");
            return self::SUCCESS;
        }

        error("Tests & Coverage ✗");
        error($result->message);

        if (! empty($result->details)) {
            table(
                headers: ['Failed Tests'],
                rows: array_map(fn ($d) => [$d], $result->details)
            );
        }

        return self::FAILURE;
    }

    private function formatSummary($result): string
    {
        if ($result->passed) {
            return $result->message;
        }

        $summary = $result->message . "\n\n";
        foreach ($result->details as $detail) {
            $summary .= "- {$detail}\n";
        }
        return $summary;
    }
}
