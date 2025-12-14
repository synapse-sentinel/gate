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
            ['vendor/bin/pest', '--coverage', "--min={$this->coverageThreshold}", '--colors=never'],
            $workingDirectory,
            timeout: 300,
        );

        $process->run();
        $output = $process->getOutput() . $process->getErrorOutput();

        if ($process->isSuccessful()) {
            $stats = $this->parseStats($output);
            return CheckResult::pass($stats);
        }

        return CheckResult::fail(
            $this->parseFailureMessage($output),
            $this->parseFailureDetails($output),
        );
    }

    private function parseStats(string $output): string
    {
        // Extract test count and coverage
        if (preg_match('/Tests:\s*(\d+)\s*passed/', $output, $tests)) {
            $testCount = $tests[1];
        } else {
            $testCount = '?';
        }

        if (preg_match('/Total:\s*([\d.]+)%/', $output, $coverage)) {
            return "{$testCount} tests, {$coverage[1]}% coverage";
        }

        return "{$testCount} tests passed";
    }

    private function parseFailureMessage(string $output): string
    {
        // Check if it's a coverage failure
        if (preg_match('/Code coverage below expected:\s*([\d.]+)%/', $output, $match)) {
            return "Coverage {$match[1]}% below {$this->coverageThreshold}% threshold";
        }

        // Count failed tests
        if (preg_match('/Tests:\s*\d+\s*passed,\s*(\d+)\s*failed/', $output, $match)) {
            return "{$match[1]} test(s) failed";
        }

        if (preg_match('/(\d+)\s*failed/', $output, $match)) {
            return "{$match[1]} test(s) failed";
        }

        return 'Tests failed';
    }

    private function parseFailureDetails(string $output): array
    {
        $details = [];

        // Extract failed test names - look for the FAIL marker
        // Pest format: ⨯ TestName → it does something
        if (preg_match_all('/[⨯✗]\s*(.+?→.+?)(?:\s+[\d.]+s|\n)/u', $output, $matches)) {
            foreach (array_slice($matches[1], 0, 5) as $test) {
                $details[] = trim($test);
            }
        }

        // If coverage failure, show the gap
        if (preg_match('/Code coverage below expected:\s*([\d.]+)%/', $output, $match)) {
            $details[] = "Coverage: {$match[1]}% (need {$this->coverageThreshold}%)";
        } elseif (preg_match('/Total:\s*([\d.]+)%/', $output, $match)) {
            if ((float) $match[1] < $this->coverageThreshold) {
                $details[] = "Coverage: {$match[1]}% (need {$this->coverageThreshold}%)";
            }
        }

        return $details;
    }
}
