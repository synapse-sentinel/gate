<?php

declare(strict_types=1);

use App\Commands\CheckCommand;
use Symfony\Component\Process\Process;

describe('CheckCommand', function () {
    describe('signature', function () {
        it('has the correct name', function () {
            $command = new CheckCommand;

            expect($command->getName())->toBe('check');
        });

        it('has format option', function () {
            $command = new CheckCommand;
            $definition = $command->getDefinition();

            expect($definition->hasOption('format'))->toBeTrue();
            expect($definition->getOption('format')->getDefault())->toBe('pretty');
        });

        it('has no-tests option', function () {
            $command = new CheckCommand;
            $definition = $command->getDefinition();

            expect($definition->hasOption('no-tests'))->toBeTrue();
        });

        it('has no-phpstan option', function () {
            $command = new CheckCommand;
            $definition = $command->getDefinition();

            expect($definition->hasOption('no-phpstan'))->toBeTrue();
        });

        it('has no-style option', function () {
            $command = new CheckCommand;
            $definition = $command->getDefinition();

            expect($definition->hasOption('no-style'))->toBeTrue();
        });
    });

    describe('results structure', function () {
        it('initializes with pending verdict', function () {
            $command = new CheckCommand;

            $reflection = new ReflectionClass($command);
            $property = $reflection->getProperty('results');
            $property->setAccessible(true);
            $results = $property->getValue($command);

            expect($results['verdict'])->toBe('pending');
            expect($results['coverage'])->toBeNull();
            expect($results['phpstan'])->toBeNull();
            expect($results['style'])->toBeNull();
        });
    });
});

describe('CheckCommand with mock results', function () {
    beforeEach(function () {
        $this->createCommand = function (array $results) {
            $command = new CheckCommand;
            $command->withMockResults($results);
            app()->singleton(CheckCommand::class, fn () => $command);
        };
    });

    describe('verdict calculation', function () {
        it('returns approved when all checks pass', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('returns rejected when tests fail', function () {
            ($this->createCommand)([
                'tests' => ['success' => false],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check')
                ->assertFailed();
        });

        it('returns rejected when phpstan has errors', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => false, 'errors' => 5],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check')
                ->assertFailed();
        });

        it('returns rejected when style fails', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => false],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check')
                ->assertFailed();
        });

        it('returns rejected when coverage below threshold', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 75.0, 'meets_threshold' => false],
            ]);

            $this->artisan('check')
                ->assertFailed();
        });
    });

    describe('format option', function () {
        it('outputs json format', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check', ['--format' => 'json'])
                ->assertSuccessful()
                ->expectsOutputToContain('"verdict": "APPROVED"');
        });

        it('outputs minimal format for approved', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertSuccessful()
                ->expectsOutputToContain('GATE APPROVED');
        });

        it('outputs minimal format for rejected with coverage issue', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 75.5, 'meets_threshold' => false],
            ]);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertFailed()
                ->expectsOutputToContain('GATE REJECTED')
                ->expectsOutputToContain('Coverage:');
        });

        it('outputs minimal format for rejected with phpstan errors', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => false, 'errors' => 3],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertFailed()
                ->expectsOutputToContain('PHPStan: 3 errors');
        });

        it('outputs minimal format for rejected with style violations', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => false],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertFailed()
                ->expectsOutputToContain('Style: violations found');
        });

        it('outputs pretty format for approved', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check', ['--format' => 'pretty'])
                ->assertSuccessful()
                ->expectsOutputToContain('GATE APPROVED');
        });

        it('outputs pretty format for rejected', function () {
            ($this->createCommand)([
                'tests' => ['success' => false],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check', ['--format' => 'pretty'])
                ->assertFailed()
                ->expectsOutputToContain('GATE REJECTED')
                ->expectsOutputToContain('Fix the issues');
        });
    });

    describe('default handling', function () {
        it('handles missing coverage gracefully', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                // coverage intentionally missing
            ]);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('handles missing test results gracefully', function () {
            ($this->createCommand)([
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('handles null phpstan errors count', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => false],  // errors key missing
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertFailed();
        });
    });

    describe('branding', function () {
        it('shows synapse sentinel branding', function () {
            ($this->createCommand)([
                'tests' => ['success' => true],
                'phpstan' => ['success' => true, 'errors' => 0],
                'style' => ['success' => true],
                'coverage' => ['percentage' => 100.0, 'meets_threshold' => true],
            ]);

            $this->artisan('check')
                ->assertSuccessful()
                ->expectsOutputToContain('Synapse Sentinel Gate');
        });
    });
});

describe('CheckCommand with mocked processes', function () {
    beforeEach(function () {
        $this->createProcessMock = function (bool $successful, string $output = '', int $exitCode = 0) {
            $process = Mockery::mock(Process::class);
            $process->shouldReceive('setTimeout')->andReturnSelf();
            $process->shouldReceive('run')->andReturn($exitCode);
            $process->shouldReceive('isSuccessful')->andReturn($successful);
            $process->shouldReceive('getOutput')->andReturn($output);
            $process->shouldReceive('getExitCode')->andReturn($exitCode);

            return $process;
        };

        $this->createCommand = function (array $processes) {
            $index = 0;
            $command = new CheckCommand;
            $command->withProcessFactory(function ($cmd) use ($processes, &$index) {
                return $processes[$index++] ?? $processes[0];
            });
            app()->singleton(CheckCommand::class, fn () => $command);
        };
    });

    describe('runTests', function () {
        it('runs tests and parses coverage output', function () {
            $testProcess = ($this->createProcessMock)(true, "Tests: 10 passed\nCoverage: 95.5%");
            $phpstanProcess = ($this->createProcessMock)(true, json_encode(['totals' => ['errors' => 0], 'files' => []]));
            $styleProcess = ($this->createProcessMock)(true);

            ($this->createCommand)([$testProcess, $phpstanProcess, $styleProcess]);

            $this->artisan('check')
                ->assertFailed(); // 95.5% < 100%
        });

        it('handles test failure', function () {
            $testProcess = ($this->createProcessMock)(false, 'FAILED Tests', 1);
            $phpstanProcess = ($this->createProcessMock)(true, json_encode(['totals' => ['errors' => 0], 'files' => []]));
            $styleProcess = ($this->createProcessMock)(true);

            ($this->createCommand)([$testProcess, $phpstanProcess, $styleProcess]);

            $this->artisan('check')
                ->assertFailed();
        });

        it('handles 100% coverage', function () {
            $testProcess = ($this->createProcessMock)(true, "Tests: 10 passed\nCoverage: 100.0%");
            $phpstanProcess = ($this->createProcessMock)(true, json_encode(['totals' => ['errors' => 0], 'files' => []]));
            $styleProcess = ($this->createProcessMock)(true);

            ($this->createCommand)([$testProcess, $phpstanProcess, $styleProcess]);

            $this->artisan('check')
                ->assertSuccessful();
        });

        it('handles output without coverage info', function () {
            $testProcess = ($this->createProcessMock)(true, 'Tests: 10 passed');
            $phpstanProcess = ($this->createProcessMock)(true, json_encode(['totals' => ['errors' => 0], 'files' => []]));
            $styleProcess = ($this->createProcessMock)(true);

            ($this->createCommand)([$testProcess, $phpstanProcess, $styleProcess]);

            $this->artisan('check')
                ->assertSuccessful();
        });
    });

    describe('runPhpstan', function () {
        it('runs phpstan and parses errors', function () {
            $testProcess = ($this->createProcessMock)(true, 'Coverage: 100.0%');
            $phpstanProcess = ($this->createProcessMock)(false, json_encode([
                'totals' => ['errors' => 3],
                'files' => ['/app/Test.php' => ['errors' => 3]],
            ]));
            $styleProcess = ($this->createProcessMock)(true);

            ($this->createCommand)([$testProcess, $phpstanProcess, $styleProcess]);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertFailed()
                ->expectsOutputToContain('PHPStan: 3 errors');
        });

        it('handles invalid json output', function () {
            $testProcess = ($this->createProcessMock)(true, 'Coverage: 100.0%');
            $phpstanProcess = ($this->createProcessMock)(true, 'not json');
            $styleProcess = ($this->createProcessMock)(true);

            ($this->createCommand)([$testProcess, $phpstanProcess, $styleProcess]);

            $this->artisan('check')
                ->assertSuccessful();
        });
    });

    describe('runStyle', function () {
        it('runs style check and handles failure', function () {
            $testProcess = ($this->createProcessMock)(true, 'Coverage: 100.0%');
            $phpstanProcess = ($this->createProcessMock)(true, json_encode(['totals' => ['errors' => 0], 'files' => []]));
            $styleProcess = ($this->createProcessMock)(false, 'Style violations found');

            ($this->createCommand)([$testProcess, $phpstanProcess, $styleProcess]);

            $this->artisan('check', ['--format' => 'minimal'])
                ->assertFailed()
                ->expectsOutputToContain('Style: violations found');
        });
    });

    describe('skip options', function () {
        it('skips tests with --no-tests', function () {
            $phpstanProcess = ($this->createProcessMock)(true, json_encode(['totals' => ['errors' => 0], 'files' => []]));
            $styleProcess = ($this->createProcessMock)(true);

            ($this->createCommand)([$phpstanProcess, $styleProcess]);

            $this->artisan('check', ['--no-tests' => true])
                ->assertSuccessful();
        });

        it('skips phpstan with --no-phpstan', function () {
            $testProcess = ($this->createProcessMock)(true, 'Coverage: 100.0%');
            $styleProcess = ($this->createProcessMock)(true);

            ($this->createCommand)([$testProcess, $styleProcess]);

            $this->artisan('check', ['--no-phpstan' => true])
                ->assertSuccessful();
        });

        it('skips style with --no-style', function () {
            $testProcess = ($this->createProcessMock)(true, 'Coverage: 100.0%');
            $phpstanProcess = ($this->createProcessMock)(true, json_encode(['totals' => ['errors' => 0], 'files' => []]));

            ($this->createCommand)([$testProcess, $phpstanProcess]);

            $this->artisan('check', ['--no-style' => true])
                ->assertSuccessful();
        });

        it('skips all checks with all skip options', function () {
            $command = new CheckCommand;
            app()->singleton(CheckCommand::class, fn () => $command);

            $this->artisan('check', [
                '--no-tests' => true,
                '--no-phpstan' => true,
                '--no-style' => true,
            ])
                ->assertSuccessful();
        });
    });

    describe('createProcess', function () {
        it('uses factory when provided', function () {
            $command = new CheckCommand;
            $mockProcess = Mockery::mock(Process::class);

            $command->withProcessFactory(fn ($cmd) => $mockProcess);

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('createProcess');
            $method->setAccessible(true);

            $result = $method->invoke($command, ['test', 'command']);

            expect($result)->toBe($mockProcess);
        });

        it('creates real process without factory', function () {
            $command = new CheckCommand;

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('createProcess');
            $method->setAccessible(true);

            $result = $method->invoke($command, ['echo', 'test']);

            expect($result)->toBeInstanceOf(Process::class);
        });
    });
});
