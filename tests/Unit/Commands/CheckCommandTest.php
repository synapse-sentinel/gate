<?php

declare(strict_types=1);

use App\Commands\CheckCommand;

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
