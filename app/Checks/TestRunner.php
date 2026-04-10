<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\GitHub\CommentsClient;
use App\Services\CoverageReporter;
use App\Services\PestOutputParser;
use App\Services\SymfonyProcessRunner;

final class TestRunner implements CheckInterface
{
    /** @var CommentsClient|null For testing */
    private ?CommentsClient $commentsClient = null;

    /** @var CoverageReporter|null For testing */
    private ?CoverageReporter $coverageReporter = null;

    public function __construct(
        private readonly int $coverageThreshold = 100,
        private readonly PestOutputParser $parser = new PestOutputParser,
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
    ) {}

    /** @internal For testing only */
    public function withCommentDependencies(
        CommentsClient $commentsClient,
        CoverageReporter $coverageReporter,
    ): self {
        $this->commentsClient = $commentsClient;
        $this->coverageReporter = $coverageReporter;

        return $this;
    }

    public function name(): string
    {
        return 'Tests & Coverage';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $cloverPath = $workingDirectory.'/coverage.xml';

        $result = $this->processRunner->run(
            ['vendor/bin/pest', '--coverage', "--min={$this->coverageThreshold}", "--coverage-clover={$cloverPath}", '--colors=never'],
            $workingDirectory,
            timeout: 300,
        );

        // Post coverage comment to PR if enabled
        $this->postCoverageComment($cloverPath);

        if ($result->successful) {
            return CheckResult::pass($this->parseStats($result->output));
        }

        return CheckResult::fail(
            $this->parseFailureMessage($result->output),
            $this->parseFailureDetails($result->output),
            $result->output, // Raw output for prompt transformation
        );
    }

    private function postCoverageComment(string $cloverPath): void
    {
        // Only post if coverage-comment is enabled (default: true)
        $coverageCommentEnabled = getenv('COVERAGE_COMMENT') !== 'false';
        if (! $coverageCommentEnabled || ! file_exists($cloverPath)) {
            return;
        }

        $token = getenv('GITHUB_TOKEN');
        if (! $token && $this->commentsClient === null) {
            return;
        }

        $commentsClient = $this->commentsClient ?? new CommentsClient($token);
        $reporter = $this->coverageReporter ?? new CoverageReporter($this->coverageThreshold);

        if ($commentsClient->isAvailable()) {
            try {
                $comment = $reporter->generatePRComment($cloverPath);
                $commentsClient->postOrUpdateComment($comment);
            } catch (\Exception $e) {
                // Silent fail - don't break the build
                echo "::debug::Coverage comment failed: {$e->getMessage()}\n";
            }
        }
    }

    private function parseStats(string $output): string
    {
        $tests = $this->parser->parseTestCount($output);
        $coverage = $this->parser->parseCoverage($output);

        if ($coverage === null) {
            return "{$tests} tests passed";
        }

        // Check for rounding discrepancy (e.g., 99.95% rounds to 100% but files are below threshold)
        if ($this->parser->hasRoundingDiscrepancy($output, (float) $this->coverageThreshold)) {
            $actualCoverage = $this->parser->calculateActualCoverage($output);
            if ($actualCoverage !== null) {
                return "{$tests} tests, {$actualCoverage}% (rounds to {$coverage}%, but some files below threshold)";
            }
        }

        return "{$tests} tests, {$coverage}% coverage";
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
