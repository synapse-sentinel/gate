<?php

declare(strict_types=1);

namespace App\Commands;

use App\Branding;
use App\Checks\PestSyntaxValidator;
use App\GitHub\ChecksClient;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

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

        $result = spin(
            fn () => $check->run(getcwd()),
            'Validating test syntax...'
        );

        $title = $result->passed
            ? 'All tests use describe/it'
            : count($result->details) . ' files using test()';

        $checksClient->reportCheck(
            name: Branding::SYNTAX,
            passed: $result->passed,
            title: $title,
            summary: $result->message,
        );

        if ($result->passed) {
            info("Pest Syntax ✓");
            return self::SUCCESS;
        }

        error("Pest Syntax ✗");
        error($result->message);

        if (! empty($result->details)) {
            table(
                headers: ['Files using test() instead of describe/it'],
                rows: array_map(fn ($d) => [$d], $result->details)
            );
        }

        return self::FAILURE;
    }
}
