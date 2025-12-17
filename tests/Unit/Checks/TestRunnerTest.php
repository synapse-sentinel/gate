<?php

declare(strict_types=1);

use App\Checks\TestRunner;
use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;
use App\Services\PestOutputParser;

describe('TestRunner', function () {
    it('has a descriptive name', function () {
        $runner = new TestRunner(coverageThreshold: 100);
        expect($runner->name())->toBe('Tests & Coverage');
    });

    it('implements CheckInterface', function () {
        $runner = new TestRunner(coverageThreshold: 80);
        expect($runner)->toBeInstanceOf(\App\Checks\CheckInterface::class);
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
            parser: new PestOutputParser(),
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
            parser: new PestOutputParser(),
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
            parser: new PestOutputParser(),
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
            parser: new PestOutputParser(),
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
            parser: new PestOutputParser(),
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
            parser: new PestOutputParser(),
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
            parser: new PestOutputParser(),
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->details)->toContain('Coverage: 95% (threshold: 100%)');
        expect($result->details)->toContain('  App/Services/FooService: 85%');
        expect($result->details)->toContain('  App/Services/BarService: 92.5%');
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
            parser: new PestOutputParser(),
            processRunner: $mockRunner,
        );

        $result = $runner->run('/tmp');

        expect($result->passed)->toBeFalse();
        // Should have: coverage summary + 5 files + "...and X more files"
        $fileDetails = array_filter($result->details, fn ($d) => str_starts_with($d, '  App/'));
        expect($fileDetails)->toHaveCount(5);
        expect($result->details)->toContain('  ...and 2 more files');
    });
});
