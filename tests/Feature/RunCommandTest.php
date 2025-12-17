<?php

declare(strict_types=1);

use App\Commands\CertifyCommand;
use App\Commands\SecurityCommand;
use App\Commands\SyntaxCommand;
use App\Commands\TestsCommand;

describe('Gate Commands', function () {
    describe('TestsCommand', function () {
        it('has the correct signature', function () {
            $command = new TestsCommand();
            expect($command->getName())->toBe('tests');
        });

        it('has coverage option with default of 80', function () {
            $command = new TestsCommand();
            $definition = $command->getDefinition();

            expect($definition->hasOption('coverage'))->toBeTrue();
            expect($definition->getOption('coverage')->getDefault())->toBe('80');
        });

        it('has token option', function () {
            $command = new TestsCommand();
            expect($command->getDefinition()->hasOption('token'))->toBeTrue();
        });
    });

    describe('SecurityCommand', function () {
        it('has the correct signature', function () {
            $command = new SecurityCommand();
            expect($command->getName())->toBe('security');
        });

        it('has token option', function () {
            $command = new SecurityCommand();
            expect($command->getDefinition()->hasOption('token'))->toBeTrue();
        });
    });

    describe('SyntaxCommand', function () {
        it('has the correct signature', function () {
            $command = new SyntaxCommand();
            expect($command->getName())->toBe('syntax');
        });

        it('has token option', function () {
            $command = new SyntaxCommand();
            expect($command->getDefinition()->hasOption('token'))->toBeTrue();
        });
    });

    describe('CertifyCommand', function () {
        it('has the correct signature', function () {
            $command = new CertifyCommand();
            expect($command->getName())->toBe('certify');
        });

        it('has coverage option with default of 80', function () {
            $command = new CertifyCommand();
            $definition = $command->getDefinition();

            expect($definition->hasOption('coverage'))->toBeTrue();
            expect($definition->getOption('coverage')->getDefault())->toBe('80');
        });

        it('has token option', function () {
            $command = new CertifyCommand();
            expect($command->getDefinition()->hasOption('token'))->toBeTrue();
        });

        it('has compact option', function () {
            $command = new CertifyCommand();
            $definition = $command->getDefinition();

            expect($definition->hasOption('compact'))->toBeTrue();
            expect($definition->getOption('compact')->getDefault())->toBeFalse();
        });
    });
});
