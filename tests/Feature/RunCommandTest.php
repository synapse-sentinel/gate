<?php

declare(strict_types=1);

use App\Commands\RunCommand;

describe('RunCommand', function () {
    it('has the correct signature', function () {
        $command = new RunCommand();

        expect($command->getName())->toBe('run');
    });

    it('has coverage option with default of 100', function () {
        $command = new RunCommand();
        $definition = $command->getDefinition();

        expect($definition->hasOption('coverage'))->toBeTrue();
        expect($definition->getOption('coverage')->getDefault())->toBe('100');
    });

    it('has repo option', function () {
        $command = new RunCommand();
        $definition = $command->getDefinition();

        expect($definition->hasOption('repo'))->toBeTrue();
    });

    it('has pr option', function () {
        $command = new RunCommand();
        $definition = $command->getDefinition();

        expect($definition->hasOption('pr'))->toBeTrue();
    });

    it('has token option for GitHub Checks API', function () {
        $command = new RunCommand();
        $definition = $command->getDefinition();

        expect($definition->hasOption('token'))->toBeTrue();
    });
});
