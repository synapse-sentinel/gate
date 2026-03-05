<?php

declare(strict_types=1);

use App\Contracts\ProcessResult;
use App\Services\SymfonyProcessRunner;

describe('SymfonyProcessRunner', function () {
    describe('run', function () {
        it('returns successful result for successful command', function () {
            $runner = new SymfonyProcessRunner;
            $result = $runner->run(['echo', 'hello'], sys_get_temp_dir());

            expect($result)->toBeInstanceOf(ProcessResult::class);
            expect($result->successful)->toBeTrue();
            expect($result->output)->toContain('hello');
            expect($result->exitCode)->toBe(0);
        });

        it('returns failed result for failing command', function () {
            $runner = new SymfonyProcessRunner;
            $result = $runner->run(['false'], sys_get_temp_dir());

            expect($result)->toBeInstanceOf(ProcessResult::class);
            expect($result->successful)->toBeFalse();
            expect($result->exitCode)->not->toBe(0);
        });

        it('captures stderr in output', function () {
            $runner = new SymfonyProcessRunner;
            $result = $runner->run(['ls', '/nonexistent_directory_12345'], sys_get_temp_dir());

            expect($result->successful)->toBeFalse();
            expect($result->output)->toContain('No such file');
        });

        it('respects working directory', function () {
            $tmpDir = sys_get_temp_dir();
            $runner = new SymfonyProcessRunner;
            $result = $runner->run(['pwd'], $tmpDir);

            expect($result->successful)->toBeTrue();
            // The output should contain the temp dir path
            expect(trim($result->output))->toBe(realpath($tmpDir));
        });

        it('applies timeout parameter', function () {
            $runner = new SymfonyProcessRunner;
            // This should complete quickly, we're just testing the timeout parameter is accepted
            $result = $runner->run(['echo', 'fast'], sys_get_temp_dir(), timeout: 5);

            expect($result->successful)->toBeTrue();
        });

        it('returns failed result with exit code 124 on process timeout', function () {
            $runner = new SymfonyProcessRunner;
            $result = $runner->run(['sleep', '30'], sys_get_temp_dir(), timeout: 1);

            expect($result)->toBeInstanceOf(ProcessResult::class);
            expect($result->successful)->toBeFalse();
            expect($result->exitCode)->toBe(124);
            expect($result->output)->toContain('Process timed out after 1 seconds');
        });

        it('returns failed result when process is killed by signal', function () {
            $runner = new SymfonyProcessRunner;
            // bash -c that sends SIGKILL to itself
            $result = $runner->run(['bash', '-c', 'kill -9 $$'], sys_get_temp_dir());

            expect($result)->toBeInstanceOf(ProcessResult::class);
            expect($result->successful)->toBeFalse();
            expect($result->output)->toContain('Process killed by signal');
            expect($result->exitCode)->toBe(137);
        });
    });
});
