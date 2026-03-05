<?php

declare(strict_types=1);

use App\Commands\CheckCommand;
use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;

beforeEach(function () {
    $this->createCommand = function (ProcessRunner $processRunner) {
        $command = new CheckCommand;
        $command->withMocks($processRunner);
        app()->singleton(CheckCommand::class, fn () => $command);
    };
});

describe('CheckCommand', function () {
    describe('handle orchestration', function () {
        it('runs all checks by default and returns success when all pass', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            // Tests
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            // PHPStan
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0], 'files' => []]), exitCode: 0));

            // Pint
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: 'No style issues found', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('returns failure when any check fails', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            // Tests pass
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            // PHPStan fails
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->once()
                ->andReturn(new ProcessResult(successful: false, output: json_encode(['totals' => ['errors' => 3], 'files' => []]), exitCode: 1));

            // Pint passes
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });

        it('skips tests when --no-tests is set', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            // Tests should NOT be called
            $processRunner->shouldNotReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300);

            // PHPStan
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            // Pint
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check', ['--no-tests' => true])
                ->assertSuccessful();
        });

        it('skips phpstan when --no-phpstan is set', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            // Tests
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            // PHPStan should NOT be called
            $processRunner->shouldNotReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120);

            // Pint
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check', ['--no-phpstan' => true])
                ->assertSuccessful();
        });

        it('skips style when --no-style is set', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            // Tests
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            // PHPStan
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            // Pint should NOT be called
            $processRunner->shouldNotReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60);

            ($this->createCommand)($processRunner);

            $this->artisan('check', ['--no-style' => true])
                ->assertSuccessful();
        });
    });

    describe('runTests', function () {
        it('stores success result when tests pass', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: "Tests passed\nCoverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('stores failure result when tests fail', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->once()
                ->andReturn(new ProcessResult(successful: false, output: 'FAILED', exitCode: 1));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });

        it('parses coverage percentage from output', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 95.2%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            // Coverage below 100% threshold → REJECTED
            $this->artisan('check', ['--format' => 'json'])
                ->assertFailed();
        });

        it('leaves coverage null when no coverage in output', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: 'Tests passed, no coverage info', exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            // No coverage info → coverage remains null → meets_threshold defaults to true
            $this->artisan('check')
                ->assertSuccessful();
        });
    });

    describe('runPhpstan', function () {
        it('stores success with zero errors', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0], 'files' => []]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('stores failure with error count', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->once()
                ->andReturn(new ProcessResult(successful: false, output: json_encode(['totals' => ['errors' => 5], 'files' => ['app/Foo.php' => ['errors' => 5]]]), exitCode: 1));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });

        it('handles invalid json output gracefully', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->once()
                ->andReturn(new ProcessResult(successful: false, output: 'not valid json at all', exitCode: 1));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });
    });

    describe('runStyle', function () {
        it('stores success when pint passes', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->once()
                ->andReturn(new ProcessResult(successful: true, output: 'No issues', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('stores failure when pint fails', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->once()
                ->andReturn(new ProcessResult(successful: false, output: 'Style violations', exitCode: 1));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });
    });

    describe('output formats', function () {
        it('outputs json format with --format=json', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0], 'files' => []]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check', ['--format' => 'json'])
                ->assertSuccessful();
        });

        it('outputs GATE APPROVED in minimal format when all pass', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertSuccessful();
        });

        it('outputs GATE REJECTED in minimal format with failure details', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            // Tests pass but coverage below threshold
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 85.0%\n", exitCode: 0));

            // PHPStan fails with errors
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: false, output: json_encode(['totals' => ['errors' => 3]]), exitCode: 1));

            // Style fails
            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: false, output: 'violations', exitCode: 1));

            ($this->createCommand)($processRunner);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertFailed();
        });

        it('outputs pretty APPROVED format by default', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('outputs pretty REJECTED format with fix hint', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: false, output: 'FAILED', exitCode: 1));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });
    });

    describe('verdict determination', function () {
        it('approves when all checks are skipped', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);
            $processRunner->shouldNotReceive('run');

            ($this->createCommand)($processRunner);

            $this->artisan('check', ['--no-tests' => true, '--no-phpstan' => true, '--no-style' => true])
                ->assertSuccessful();
        });

        it('rejects when coverage is below threshold', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 75.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });

        it('rejects when phpstan has errors', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: false, output: json_encode(['totals' => ['errors' => 2]]), exitCode: 1));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: true, output: '', exitCode: 0));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });

        it('rejects when style check fails', function () {
            $processRunner = Mockery::mock(ProcessRunner::class);

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pest', '--coverage', '--coverage-clover=coverage.xml', '--no-interaction'], Mockery::any(), 300)
                ->andReturn(new ProcessResult(successful: true, output: "Coverage: 100.0%\n", exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress'], Mockery::any(), 120)
                ->andReturn(new ProcessResult(successful: true, output: json_encode(['totals' => ['errors' => 0]]), exitCode: 0));

            $processRunner->shouldReceive('run')
                ->with(['vendor/bin/pint', '--test'], Mockery::any(), 60)
                ->andReturn(new ProcessResult(successful: false, output: 'Style violations', exitCode: 1));

            ($this->createCommand)($processRunner);

            $this->artisan('check')
                ->assertFailed();
        });
    });
});
