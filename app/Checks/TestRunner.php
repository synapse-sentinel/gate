<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\PestOutputParser;
use App\Services\SymfonyProcessRunner;

final class TestRunner implements CheckInterface
{
    /** @var \App\GitHub\CommentsClient|null For testing */
    private ?\App\GitHub\CommentsClient $commentsClient = null;

    /** @var \App\Services\CoverageReporter|null For testing */
    private ?\App\Services\CoverageReporter $coverageReporter = null;

    public function __construct(
        private readonly int $coverageThreshold = 100,
        private readonly PestOutputParser $parser = new PestOutputParser,
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
    ) {}

    /** @internal For testing only */
    public function withCommentDependencies(
        \App\GitHub\CommentsClient $commentsClient,
        \App\Services\CoverageReporter $coverageReporter,
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

        $commentsClient = $this->commentsClient ?? new \App\GitHub\CommentsClient($token);
        $reporter = $this->coverageReporter ?? new \App\Services\CoverageReporter($this->coverageThreshold);

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
