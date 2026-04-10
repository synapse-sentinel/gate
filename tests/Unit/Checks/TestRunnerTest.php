<?php

declare(strict_types=1);

use App\Checks\CheckInterface;
use App\Checks\TestRunner;
use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;
use App\GitHub\CommentsClient;
use App\Services\CoverageReporter;
use App\Services\PestOutputParser;

describe('TestRunner', function () {
    it('has a descriptive name', function () {
        $runner = new TestRunner(coverageThreshold: 100);
        expect($runner->name())->toBe('Tests & Coverage');
    });

    it('implements CheckInterface', function () {
        $runner = new TestRunner(coverageThreshold: 80);
        expect($runner)->toBeInstanceOf(CheckInterface::class);
    });

    it('returns pass when tests pass and coverage meets threshold', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: "Tests:  10 passed\nTotal:  100.00%",
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toContain('10 tests');
        expect($result->message)->toContain('100%');
    });

    it('returns fail when tests fail', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it fails  0.5s
    Expected true to be false.
    at tests/Unit/FooTest.php:42

Tests:  1 failed
OUTPUT,
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toContain('1 test(s) failed');
        expect($result->details)->not->toBeEmpty();
    });

    it('returns fail when coverage is below threshold', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: "Tests:  5 passed\nFAIL  Code coverage below expected  100.0 %, currently  89.50 %.",
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toContain('89.5%');
        expect($result->message)->toContain('below');
    });

    it('includes detailed failure info in details', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it does something  0.5s
    Expected true to be false.
    at tests/Unit/FooTest.php:42

⨯ Tests\Unit\BarTest → it does another thing  0.3s
    Values don't match.
    at tests/Unit/BarTest.php:18

Tests:  2 failed
OUTPUT,
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->details)->toHaveCount(2);
        expect($result->details[0])->toContain('FooTest');
        expect($result->details[0])->toContain('tests/Unit/FooTest.php:42');
        expect($result->details[1])->toContain('BarTest');
    });

    it('passes correct command to process runner', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->with(
                ['vendor/bin/pest', '--coverage', '--min=80', '--coverage-clover=/some/path/coverage.xml', '--colors=never'],
                '/some/path',
                Mockery::any()
            )
            ->andReturn(new ProcessResult(successful: true, output: 'Tests:  1 passed'));

        $runner = new TestRunner(
            coverageThreshold: 80,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $runner->run('/some/path');
    });

    it('handles output without coverage info', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'Tests:  5 passed',
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('5 tests passed');
    });

    it('includes file coverage details when below threshold', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: <<<'OUTPUT'
Tests:  5 passed
  App/Services/FooService .............. 85.0%
  App/Services/BarService .............. 92.5%
  App/Services/BazService .............. 100.0%
  Total ................................ 95.0%
FAIL  Code coverage below expected  100.0 %, currently  95.0 %.
OUTPUT,
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->details)->toContain('Coverage: 95% (threshold: 100%)');
        expect($result->details)->toContain('  App/Services/FooService: 85%');
        expect($result->details)->toContain('  App/Services/BarService: 92.5%');
    });

    describe('postCoverageComment', function () {
        afterEach(function () {
            putenv('COVERAGE_COMMENT');
            putenv('GITHUB_TOKEN');
        });

        it('skips comment when COVERAGE_COMMENT is false', function () {
            putenv('COVERAGE_COMMENT=false');

            $mockRunner = mock(ProcessRunner::class);
            $mockRunner->shouldReceive('run')
                ->once()
                ->andReturn(new ProcessResult(
                    successful: true,
                    output: 'Tests:  1 passed',
                ));

            $runner = new TestRunner(
                coverageThreshold: 100,
                parser: new PestOutputParser,
                processRunner: $mockRunner,
            );

            // Should not throw, should complete successfully
            $result = $runner->run('/tmp');
            expect($result->passed)->toBeTrue();
        });

        it('skips comment when coverage.xml does not exist', function () {
            putenv('COVERAGE_COMMENT=true');

            $mockRunner = mock(ProcessRunner::class);
            $mockRunner->shouldReceive('run')
                ->once()
                ->andReturn(new ProcessResult(
                    successful: true,
                    output: 'Tests:  1 passed',
                ));

            $runner = new TestRunner(
                coverageThreshold: 100,
                parser: new PestOutputParser,
                processRunner: $mockRunner,
            );

            // Use a nonexistent path
            $result = $runner->run('/nonexistent/path');
            expect($result->passed)->toBeTrue();
        });

        it('skips comment when GITHUB_TOKEN is not set', function () {
            putenv('COVERAGE_COMMENT=true');
            putenv('GITHUB_TOKEN=');

            // Create a temp coverage.xml to pass the file_exists check
            $tempDir = sys_get_temp_dir().'/test_coverage_'.uniqid();
            mkdir($tempDir);
            file_put_contents($tempDir.'/coverage.xml', '<?xml version="1.0"?><coverage><project><metrics/></project></coverage>');

            $mockRunner = mock(ProcessRunner::class);
            $mockRunner->shouldReceive('run')
                ->once()
                ->andReturn(new ProcessResult(
                    successful: true,
                    output: 'Tests:  1 passed',
                ));

            $runner = new TestRunner(
                coverageThreshold: 100,
                parser: new PestOutputParser,
                processRunner: $mockRunner,
            );

            $result = $runner->run($tempDir);
            expect($result->passed)->toBeTrue();

            // Cleanup
            unlink($tempDir.'/coverage.xml');
            rmdir($tempDir);
        });

        it('posts comment when dependencies are injected and available', function () {
            putenv('COVERAGE_COMMENT=true');

            // Create temp dir with coverage.xml
            $tempDir = sys_get_temp_dir().'/test_coverage_di_'.uniqid();
            mkdir($tempDir);
            file_put_contents($tempDir.'/coverage.xml', '<?xml version="1.0"?><coverage><project><metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/></project></coverage>');

            $mockRunner = mock(ProcessRunner::class);
            $mockRunner->shouldReceive('run')
                ->once()
                ->andReturn(new ProcessResult(
                    successful: true,
                    output: 'Tests:  1 passed',
                ));

            // Mock CommentsClient
            $mockCommentsClient = mock(CommentsClient::class);
            $mockCommentsClient->shouldReceive('isAvailable')->andReturn(true);
            $mockCommentsClient->shouldReceive('postOrUpdateComment')->once()->andReturn(true);

            // Mock CoverageReporter
            $mockReporter = mock(CoverageReporter::class);
            $mockReporter->shouldReceive('generatePRComment')
                ->with($tempDir.'/coverage.xml')
                ->once()
                ->andReturn('Coverage: 100%');

            $runner = new TestRunner(
                coverageThreshold: 100,
                parser: new PestOutputParser,
                processRunner: $mockRunner,
            );

            $runner->withCommentDependencies($mockCommentsClient, $mockReporter);

            $result = $runner->run($tempDir);

            expect($result->passed)->toBeTrue();

            // Cleanup
            unlink($tempDir.'/coverage.xml');
            rmdir($tempDir);
        });

        it('skips comment when CommentsClient is not available', function () {
            putenv('COVERAGE_COMMENT=true');

            // Create temp dir with coverage.xml
            $tempDir = sys_get_temp_dir().'/test_coverage_unavail_'.uniqid();
            mkdir($tempDir);
            file_put_contents($tempDir.'/coverage.xml', '<?xml version="1.0"?><coverage><project><metrics/></project></coverage>');

            $mockRunner = mock(ProcessRunner::class);
            $mockRunner->shouldReceive('run')
                ->once()
                ->andReturn(new ProcessResult(
                    successful: true,
                    output: 'Tests:  1 passed',
                ));

            // Mock CommentsClient - not available
            $mockCommentsClient = mock(CommentsClient::class);
            $mockCommentsClient->shouldReceive('isAvailable')->andReturn(false);
            $mockCommentsClient->shouldNotReceive('postOrUpdateComment');

            $mockReporter = mock(CoverageReporter::class);

            $runner = new TestRunner(
                coverageThreshold: 100,
                parser: new PestOutputParser,
                processRunner: $mockRunner,
            );

            $runner->withCommentDependencies($mockCommentsClient, $mockReporter);

            $result = $runner->run($tempDir);

            expect($result->passed)->toBeTrue();

            // Cleanup
            unlink($tempDir.'/coverage.xml');
            rmdir($tempDir);
        });

        it('silently catches exceptions when posting comment fails', function () {
            putenv('COVERAGE_COMMENT=true');

            // Create temp dir with coverage.xml
            $tempDir = sys_get_temp_dir().'/test_coverage_error_'.uniqid();
            mkdir($tempDir);
            file_put_contents($tempDir.'/coverage.xml', '<?xml version="1.0"?><coverage><project><metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/></project></coverage>');

            $mockRunner = mock(ProcessRunner::class);
            $mockRunner->shouldReceive('run')
                ->once()
                ->andReturn(new ProcessResult(
                    successful: true,
                    output: 'Tests:  1 passed',
                ));

            // Mock CommentsClient
            $mockCommentsClient = mock(CommentsClient::class);
            $mockCommentsClient->shouldReceive('isAvailable')->andReturn(true);

            // Mock CoverageReporter - throws exception
            $mockReporter = mock(CoverageReporter::class);
            $mockReporter->shouldReceive('generatePRComment')
                ->andThrow(new Exception('Test error'));

            $runner = new TestRunner(
                coverageThreshold: 100,
                parser: new PestOutputParser,
                processRunner: $mockRunner,
            );

            $runner->withCommentDependencies($mockCommentsClient, $mockReporter);

            ob_start();
            $result = $runner->run($tempDir);
            $output = ob_get_clean();

            expect($result->passed)->toBeTrue()
                ->and($output)->toContain('::debug::Coverage comment failed');

            // Cleanup
            unlink($tempDir.'/coverage.xml');
            rmdir($tempDir);
        });
    });

    it('limits file coverage details to 5 files', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: <<<'OUTPUT'
Tests:  5 passed
  App/Services/Service1 .............. 80.0%
  App/Services/Service2 .............. 81.0%
  App/Services/Service3 .............. 82.0%
  App/Services/Service4 .............. 83.0%
  App/Services/Service5 .............. 84.0%
  App/Services/Service6 .............. 85.0%
  App/Services/Service7 .............. 86.0%
  Total ............................... 83.0%
FAIL  Code coverage below expected  100.0 %, currently  83.0 %.
OUTPUT,
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeFalse();
        // Should have: coverage summary + 5 files + "...and X more files"
        $fileDetails = array_filter($result->details, fn ($d) => str_starts_with($d, '  App/'));
        expect($fileDetails)->toHaveCount(5);
        expect($result->details)->toContain('  ...and 2 more files');
    });

    it('detects when Pest rounds coverage to threshold but individual files are below', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,  // Pest considers 99.95% rounded to 100% as passing
                output: <<<'OUTPUT'
Tests:  15 passed
  App/Services/FooService .............. 99.5%
  App/Services/BarService .............. 99.8%
  Total:  100.0%
OUTPUT,
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeTrue()
            ->and($result->message)->toContain('99.65% (rounds to 100%, but some files below threshold)');
    });

    it('shows normal message when all files meet threshold without rounding issues', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: <<<'OUTPUT'
Tests:  15 passed
  App/Services/FooService .............. 100.0%
  App/Services/BarService .............. 100.0%
  Total:  100.0%
OUTPUT,
            ));

        $runner = new TestRunner(
            coverageThreshold: 100,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeTrue()
            ->and($result->message)->toBe('15 tests, 100% coverage')
            ->and($result->message)->not->toContain('rounds to');
    });

    it('does not show rounding warning when threshold is less than 100', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: <<<'OUTPUT'
Tests:  15 passed
  App/Services/FooService .............. 89.5%
  App/Services/BarService .............. 90.8%
  Total:  90.0%
OUTPUT,
            ));

        $runner = new TestRunner(
            coverageThreshold: 90,
            parser: new PestOutputParser,
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeTrue()
            ->and($result->message)->toBe('15 tests, 90% coverage')
            ->and($result->message)->not->toContain('rounds to');
    });
});
