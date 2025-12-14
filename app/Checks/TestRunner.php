<?php

declare(strict_types=1);

namespace App\Checks;

use Symfony\Component\Process\Process;

final class TestRunner implements CheckInterface
{
    public function __construct(
        private readonly int $coverageThreshold = 100,
    ) {}

    public function name(): string
    {
        return 'Tests & Coverage';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $process = new Process(
            ['vendor/bin/pest', '--coverage', "--min={$this->coverageThreshold}"],
            $workingDirectory,
            timeout: 300,
        );

        $process->run();

        if ($process->isSuccessful()) {
            return CheckResult::pass("Tests passed with {$this->coverageThreshold}% coverage threshold");
        }

        return CheckResult::fail(
            'Tests failed or coverage below threshold',
            ['output' => $process->getOutput(), 'error' => $process->getErrorOutput()]
        );
    }
}
