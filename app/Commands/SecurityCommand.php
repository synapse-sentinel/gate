<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\SecurityScanner;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

final class SecurityCommand extends Command
{
    protected $signature = 'security
        {--token= : GitHub token for Checks API}';

    protected $description = 'Run security audit on dependencies';

    public function handle(): int
    {
        $token = $this->option('token') ?: getenv('GITHUB_TOKEN') ?: null;
        $checksClient = new ChecksClient($token);

        $check = new SecurityScanner();
        $result = $check->run(getcwd());

        // Report to GitHub Checks API
        $checksClient->reportCheck(
            name: 'Security Audit',
            passed: $result->passed,
            title: $result->passed ? 'Passed' : 'Vulnerabilities Found',
            summary: $result->message,
        );

        if ($result->passed) {
            info("Security Audit ✓");
            return self::SUCCESS;
        }

        error("Security Audit ✗");
        error($result->message);

        foreach ($result->details as $detail) {
            $this->line("  {$detail}");
        }

        return self::FAILURE;
    }
}
