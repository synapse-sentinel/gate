<?php

declare(strict_types=1);

use App\Checks\AttributionCheck;
use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;

describe('AttributionCheck', function () {
    it('has a descriptive name', function () {
        $check = new AttributionCheck;
        expect($check->name())->toBe('Attribution Check');
    });

    it('implements CheckInterface', function () {
        $check = new AttributionCheck;
        expect($check)->toBeInstanceOf(\App\Checks\CheckInterface::class);
    });

    it('returns pass when no commit exists', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: '',
            ));

        $check = new AttributionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No commit to check');
    });

    it('returns pass when commit has no attribution', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'feat: add new feature

This is a clean commit message without any attribution.',
            ));

        $check = new AttributionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No Claude attribution found');
    });

    it('detects emoji Claude Code attribution', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'feat: add new feature

🤖 Generated with [Claude Code](https://claude.com/claude-code)',
            ));

        $check = new AttributionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('Claude Code attribution detected in commit');
        expect($result->details)->toContain('🤖 Generated wth \[Claude Code\]');
    });

    it('detects text Claude Code attribution', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'feat: add new feature

Generated with Claude Code',
            ));

        $check = new AttributionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('Claude Code attribution detected in commit');
    });

    it('detects Co-Authored-By Claude attribution', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'feat: add new feature

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>',
            ));

        $check = new AttributionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('Claude Code attribution detected in commit');
        expect($result->details)->toContain('Co-Authored-By: Claude');
        expect($result->details)->toContain('noreply@anthropc\.com');
    });

    it('detects lowercase co-authored-by', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'feat: add new feature

Co-authored-by: Claude <noreply@anthropic.com>',
            ));

        $check = new AttributionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('Claude Code attribution detected in commit');
    });

    it('detects multiple attribution patterns', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'feat: add new feature

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>',
            ));

        $check = new AttributionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->details)->toHaveCount(4); // emoji, co-authored-by (capital), co-authored-by (lowercase), noreply
    });

    it('passes correct command to process runner', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->with(
                ['git', 'log', '-1', '--pretty=%B'],
                '/some/path',
                Mockery::any()
            )
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'clean commit',
            ));

        $check = new AttributionCheck(processRunner: $mockRunner);
        $check->run('/some/path');
    });
});
