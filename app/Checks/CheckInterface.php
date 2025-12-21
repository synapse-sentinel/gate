<?php

declare(strict_types=1);

namespace App\Checks;

interface CheckInterface
{
    public function name(): string;

    public function run(string $workingDirectory): CheckResult;
}
