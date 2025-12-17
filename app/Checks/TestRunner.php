<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\PestOutputParser;
use App\Services\SymfonyProcessRunner;

final class TestRunner implements CheckInterface
{
    public function __construct(
        private readonly int $coverageThreshold = 100,
        private readonly PestOutputParser $parser = new PestOutputParser(),
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner(),
    ) {}

    public function name(): string
    {
        return 'Tests & Coverage';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $result = $this->processRunner->run(
            ['vendor/bin/pest', '--coverage', "--min={$this->coverageThreshold}", "--coverage-clover={$workingDirectory}/coverage.xml", '--colors=never'],
            $workingDirectory,
            timeout: 300,
        );

        if ($result->successful) {
            return CheckResult::pass($this->parseStats($result->output));
        }

        return CheckResult::fail(
            $this->parseFailureMessage($result->output),
            $this->parseFailureDetails($result->output),
        );
    }

    private function parseStats(string $output): string
    {
        $tests = $this->parser->parseTestCount($output);
        $coverage = $this->parser->parseCoverage($output);

        return $coverage !== null
            ? "{$tests} tests, {$coverage}% coverage"
            : "{$tests} tests passed";
    }

    private function parseFailureMessage(string $output): string
    {
        if ($actual = $this->parser->isCoverageBelowThreshold($output)) {
            return "Coverage {$actual}% below {$this->coverageThreshold}% threshold";
        }

        $failures = $this->parser->parseFailures($output);

        return count($failures) > 0 ? count($failures).' test(s) failed' : 'Tests failed';
    }

    private function parseFailureDetails(string $output): array
    {
        $details = array_slice($this->parser->parseFailures($output), 0, 10);

        if ($actual = $this->parser->isCoverageBelowThreshold($output)) {
            $details[] = "Coverage: {$actual}% (threshold: {$this->coverageThreshold}%)";

            // Add files missing coverage (limit to 5)
            $uncovered = $this->parser->parseFileCoverage($output, (float) $this->coverageThreshold);
            foreach (array_slice($uncovered, 0, 5, true) as $file => $coverage) {
                $details[] = "  {$file}: {$coverage}%";
            }

            if (count($uncovered) > 5) {
                $details[] = '  ...and '.(count($uncovered) - 5).' more files';
            }
        }

        return $details;
    }
}
