<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\SecurityScanner;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

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
            $this->line('<info>Security Audit</info> <fg=green>✓</>');
            return self::SUCCESS;
        }

        $this->line('<info>Security Audit</info> <fg=red>✗</>');
        $this->newLine();
        $this->error("  {$result->message}");

        foreach ($result->details as $detail) {
            $this->line("  <fg=gray>•</> {$detail}");
        }

        return self::FAILURE;
    }
}
