<?php

namespace App\Commands;

use App\Checks\AttributionCheck;
use App\Checks\CheckInterface;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

class AttributionCheckCommand extends Command
{
    protected $signature = 'check:attribution
                            {--token= : GitHub token for checks API}';

    protected $description = 'Check for Claude Code attribution in commits';

    private ?CheckInterface $check = null;
    private ?ChecksClient $checksClient = null;

    public function withMocks(CheckInterface $check, ChecksClient $checksClient): void
    {
        $this->check = $check;
        $this->checksClient = $checksClient;
    }

    public function handle(): int
    {
        $check = $this->check ?? new AttributionCheck;
        $workingDirectory = getcwd();

        $this->info('🤖 Checking for Claude Code attribution...');
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
                'Attribution Check',
                $result->passed,
                $result->message,
                $summary
            );
        }

        return self::FAILURE;
    }
}
