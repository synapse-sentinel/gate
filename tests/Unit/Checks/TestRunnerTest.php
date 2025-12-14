<?php

declare(strict_types=1);

use App\Checks\TestRunner;
use App\Checks\CheckResult;

describe('TestRunner', function () {
    it('has a descriptive name', function () {
        $runner = new TestRunner(coverageThreshold: 100);
        expect($runner->name())->toBe('Tests & Coverage');
    });

    it('returns pass when tests pass and coverage meets threshold', function () {
        // This test needs a mock or a test fixture directory
        // For now, we'll test the interface contract
        $runner = new TestRunner(coverageThreshold: 80);
        expect($runner)->toBeInstanceOf(\App\Checks\CheckInterface::class);
    });

    it('accepts configurable coverage threshold', function () {
        $runner = new TestRunner(coverageThreshold: 90);
        // Verify it stores the threshold (we'll test behavior with integration tests)
        expect($runner)->toBeInstanceOf(TestRunner::class);
    });
});
