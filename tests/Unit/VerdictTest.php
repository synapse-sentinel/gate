<?php

declare(strict_types=1);

use App\Verdict;

describe('Verdict', function () {
    describe('creation', function () {
        it('creates an approved verdict', function () {
            $verdict = Verdict::approved('All checks passed');

            expect($verdict->status())->toBe('approved');
            expect($verdict->reason())->toBe('All checks passed');
            expect($verdict->isApproved())->toBeTrue();
            expect($verdict->isRejected())->toBeFalse();
            expect($verdict->isEscalated())->toBeFalse();
        });

        it('creates a rejected verdict with failures', function () {
            $verdict = Verdict::rejected('Tests failed', ['Test X failed', 'Coverage too low']);

            expect($verdict->status())->toBe('rejected');
            expect($verdict->reason())->toBe('Tests failed');
            expect($verdict->isRejected())->toBeTrue();
            expect($verdict->failures())->toBe(['Test X failed', 'Coverage too low']);
        });

        it('creates an escalate verdict for human review', function () {
            $verdict = Verdict::escalate('Requires human decision');

            expect($verdict->status())->toBe('escalate');
            expect($verdict->isEscalated())->toBeTrue();
        });
    });

    describe('exit codes', function () {
        it('returns exit code 0 for approved', function () {
            $verdict = Verdict::approved('All good');
            expect($verdict->exitCode())->toBe(0);
        });

        it('returns exit code 1 for rejected', function () {
            $verdict = Verdict::rejected('Failed');
            expect($verdict->exitCode())->toBe(1);
        });

        it('returns exit code 1 for escalate', function () {
            $verdict = Verdict::escalate('Needs review');
            expect($verdict->exitCode())->toBe(1);
        });
    });

    describe('markdown rendering', function () {
        it('renders approved verdict as markdown', function () {
            $verdict = Verdict::approved('All checks passed');
            $markdown = $verdict->toMarkdown();

            expect($markdown)->toContain('## ✅ Gate: Approved');
            expect($markdown)->toContain('All checks passed');
        });

        it('renders rejected verdict with failures as markdown', function () {
            $verdict = Verdict::rejected('Checks failed', ['Test failed', 'Coverage 80%']);
            $markdown = $verdict->toMarkdown();

            expect($markdown)->toContain('## ❌ Gate: Rejected');
            expect($markdown)->toContain('Test failed');
            expect($markdown)->toContain('Coverage 80%');
        });

        it('renders escalate verdict as markdown', function () {
            $verdict = Verdict::escalate('Complex scenario');
            $markdown = $verdict->toMarkdown();

            expect($markdown)->toContain('## ⚠️ Gate: Escalate');
        });
    });

    describe('GitHub Actions output', function () {
        it('formats failures as GitHub error annotations', function () {
            $verdict = Verdict::rejected('Failed', ['Error in src/Foo.php']);
            $annotations = $verdict->toAnnotations();

            expect($annotations)->toContain('::error::Error in src/Foo.php');
        });
    });
});
