<?php

declare(strict_types=1);

namespace App\Commands;

use App\Checks\PestSyntaxValidator;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

final class SyntaxCommand extends Command
{
    protected $signature = 'syntax
        {--token= : GitHub token for Checks API}';

    protected $description = 'Validate Pest test syntax (describe/it blocks)';

    public function handle(): int
    {
        $token = $this->option('token') ?: getenv('GITHUB_TOKEN') ?: null;
        $checksClient = new ChecksClient($token);

        $check = new PestSyntaxValidator();
        $result = $check->run(getcwd());

        // Report to GitHub Checks API
        $checksClient->reportCheck(
            name: 'Pest Syntax',
            passed: $result->passed,
            title: $result->passed ? 'Passed' : 'Invalid Syntax',
            summary: $result->message,
        );

        if ($result->passed) {
            info("Pest Syntax ✓");
            return self::SUCCESS;
        }

        error("Pest Syntax ✗");
        error($result->message);

        foreach ($result->details as $detail) {
            $this->line("  {$detail}");
        }

        return self::FAILURE;
    }
}
