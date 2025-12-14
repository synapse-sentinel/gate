<?php

declare(strict_types=1);

namespace App\Stages;

use App\Checks\CheckInterface;
use App\Verdict;

final class TechnicalGate
{
    /**
     * @param array<CheckInterface> $checks
     */
    public function __construct(
        private readonly array $checks,
    ) {}

    public function run(string $workingDirectory): Verdict
    {
        $failures = [];

        foreach ($this->checks as $check) {
            $result = $check->run($workingDirectory);

            if (! $result->passed) {
                $failures[] = "[{$check->name()}] {$result->message}";
            }
        }

        if (empty($failures)) {
            return Verdict::approved('All checks passed');
        }

        return Verdict::rejected('Technical gate failed', $failures);
    }
}
