<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;

final class AttributionCheck implements CheckInterface
{
    private array $attributionPatterns = [
        '/🤖 Generated with \[Claude Code\]/i',
        '/Generated with Claude Code/i',
        '/Co-Authored-By: Claude/i',
        '/Co-authored-by: Claude/i',
        '/noreply@anthropic\.com/i',
    ];

    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
    ) {}

    public function name(): string
    {
        return 'Attribution Check';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $result = $this->processRunner->run(
            ['git', 'log', '-1', '--pretty=%B'],
            $workingDirectory,
            timeout: 5,
        );

        if (! $result->successful || empty(trim($result->output))) {
            return CheckResult::pass('No commit to check');
        }

        $commitMessage = trim($result->output);

        if (! $this->hasAttribution($commitMessage)) {
            return CheckResult::pass('No Claude attribution found');
        }

        $foundPatterns = [];
        foreach ($this->attributionPatterns as $pattern) {
            if (preg_match($pattern, $commitMessage)) {
                $foundPatterns[] = str_replace(['/', 'i'], '', $pattern);
            }
        }

        return CheckResult::fail(
            'Claude Code attribution detected in commit',
            $foundPatterns
        );
    }

    private function hasAttribution(string $message): bool
    {
        foreach ($this->attributionPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }
}
