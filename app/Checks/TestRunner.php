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

        // Extract failed test names and messages
        // Pest format: FAIL  Tests\Unit\ExampleTest > it does something
        if (preg_match_all('/FAIL\s+(.+?)\n\s*(.+?)(?=\n\s*at|\n\s*FAIL|\n\s*Tests:|\z)/s', $output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $testName = trim($match[1]);
                $message = trim(preg_replace('/\s+/', ' ', $match[2]));
                if (strlen($message) > 100) {
                    $message = substr($message, 0, 100) . '...';
                }
                $details[] = "{$testName}: {$message}";
            }
        }

        // If coverage failure, show what's uncovered
        if (preg_match('/Code coverage below expected/', $output)) {
            if (preg_match('/Total:\s*([\d.]+)%/', $output, $match)) {
                $details[] = "Actual coverage: {$match[1]}%";
            }
        }

        return array_slice($details, 0, 5); // Limit to 5 details
    }
}
