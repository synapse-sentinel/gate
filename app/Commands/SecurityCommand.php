<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\SecurityScanner;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

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

        $result = spin(
            fn () => $check->run(getcwd()),
            'Auditing dependencies...'
        );

        $checksClient->reportCheck(
            name: '🛡️ Security Audit',
            passed: $result->passed,
            title: $result->message,
            summary: $result->message,
        );

        if ($result->passed) {
            info("Security Audit ✓");
            return self::SUCCESS;
        }

        error("Security Audit ✗");
        error($result->message);

        if (! empty($result->details)) {
            table(
                headers: ['Vulnerabilities'],
                rows: array_map(fn ($d) => [$d], $result->details)
            );
        }

        return self::FAILURE;
    }
}
