<?php

declare(strict_types=1);

use App\Stages\TechnicalGate;
use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Verdict;

describe('TechnicalGate', function () {
    it('returns approved verdict when all checks pass', function () {
        $passingCheck = new class implements CheckInterface {
            public function name(): string { return 'Mock Check'; }
            public function run(string $workingDirectory): CheckResult {
                return CheckResult::pass('All good');
            }
        };

        $gate = new TechnicalGate([$passingCheck]);
        $verdict = $gate->run('/tmp');

        expect($verdict->isApproved())->toBeTrue();
        expect($verdict->exitCode())->toBe(0);
    });

    it('returns rejected verdict when a check fails', function () {
        $failingCheck = new class implements CheckInterface {
            public function name(): string { return 'Failing Check'; }
            public function run(string $workingDirectory): CheckResult {
                return CheckResult::fail('Something went wrong', ['detail 1']);
            }
        };

        $gate = new TechnicalGate([$failingCheck]);
        $verdict = $gate->run('/tmp');

        expect($verdict->isRejected())->toBeTrue();
        expect($verdict->exitCode())->toBe(1);
        expect($verdict->failures())->not->toBeEmpty();
    });

    it('aggregates multiple failures into single verdict', function () {
        $failingCheck1 = new class implements CheckInterface {
            public function name(): string { return 'Check 1'; }
            public function run(string $workingDirectory): CheckResult {
                return CheckResult::fail('First failure');
            }
        };
        $failingCheck2 = new class implements CheckInterface {
            public function name(): string { return 'Check 2'; }
            public function run(string $workingDirectory): CheckResult {
                return CheckResult::fail('Second failure');
            }
        };

        $gate = new TechnicalGate([$failingCheck1, $failingCheck2]);
        $verdict = $gate->run('/tmp');

        expect($verdict->isRejected())->toBeTrue();
        expect($verdict->failures())->toHaveCount(2);
    });

    it('runs all checks even if some fail', function () {
        $checkRuns = [];

        $failingCheck = new class($checkRuns) implements CheckInterface {
            public function __construct(private array &$runs) {}
            public function name(): string { return 'Failing'; }
            public function run(string $workingDirectory): CheckResult {
                $this->runs[] = 'failing';
                return CheckResult::fail('Failed');
            }
        };
        $passingCheck = new class($checkRuns) implements CheckInterface {
            public function __construct(private array &$runs) {}
            public function name(): string { return 'Passing'; }
            public function run(string $workingDirectory): CheckResult {
                $this->runs[] = 'passing';
                return CheckResult::pass('Passed');
            }
        };

        $gate = new TechnicalGate([$failingCheck, $passingCheck]);
        $gate->run('/tmp');

        expect($checkRuns)->toHaveCount(2);
    });

    it('includes check name in failure messages', function () {
        $failingCheck = new class implements CheckInterface {
            public function name(): string { return 'Security Audit'; }
            public function run(string $workingDirectory): CheckResult {
                return CheckResult::fail('Vulnerabilities found');
            }
        };

        $gate = new TechnicalGate([$failingCheck]);
        $verdict = $gate->run('/tmp');

        expect($verdict->failures()[0])->toContain('Security Audit');
    });
});
