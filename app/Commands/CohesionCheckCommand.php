<?php

namespace App\Commands;

use App\Checks\CheckInterface;
use App\Checks\CohesionCheck;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

class CohesionCheckCommand extends Command
{
    protected $signature = 'check:cohesion
                            {--token= : GitHub token for checks API}';

    protected $description = 'Analyze cross-file relationships and PR cohesion using AI';

    private ?CheckInterface $check = null;
    private ?ChecksClient $checksClient = null;

    public function withMocks(CheckInterface $check, ChecksClient $checksClient): void
    {
        $this->check = $check;
        $this->checksClient = $checksClient;
    }

    public function handle(): int
    {
        $check = $this->check ?? new CohesionCheck;
        $workingDirectory = getcwd();

        $this->info('🔗 Analyzing PR cohesion...');
        $this->newLine();

        $result = $check->run($workingDirectory);

        if ($result->passed) {
            $this->info("✓ {$result->message}");
            return self::SUCCESS;
        }

        $this->error("✗ {$result->message}");

        if (!empty($result->details)) {
            $this->newLine();
            foreach ($result->details as $detail) {
                $this->line("  • {$detail}");
            }
        }

        // Report to GitHub if in CI
        if ($this->checksClient ?? ChecksClient::isAvailable()) {
            $client = $this->checksClient ?? ChecksClient::fromEnvironment();
            $summary = !empty($result->details)
                ? implode("\n", $result->details)
                : $result->message;
            $client->reportCheck(
                'PR Cohesion',
                $result->passed,
                $result->message,
                $summary
            );
        }

        return self::FAILURE;
    }
}
