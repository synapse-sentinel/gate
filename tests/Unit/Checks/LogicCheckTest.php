<?php

declare(strict_types=1);

use App\Checks\LogicCheck;
use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;

describe('LogicCheck', function () {
    it('has a descriptive name', function () {
        $check = new LogicCheck;
        expect($check->name())->toBe('Logic & Atomicity');
    });

    it('implements CheckInterface', function () {
        $check = new LogicCheck;
        expect($check)->toBeInstanceOf(\App\Checks\CheckInterface::class);
    });

    it('returns pass when Ollama not available', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: false,
                output: '',
            ));

        $check = new LogicCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('Ollama not installed - skipping logic validation');
    });

    it('returns pass when Ollama not running', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['ollama', 'list'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $check = new LogicCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('Ollama not running - skipping logic validation');
    });

    it('returns pass when no staged files', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['ollama', 'list'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'model list'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: ''));

        $check = new LogicCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No staged files to validate');
    });

    it('returns pass when no changes', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['ollama', 'list'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'model list'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'file.php'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: ''));

        $check = new LogicCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No changes to validate');
    });

    it('returns pass when commit is atomic and related', function () {
        $mockRunner = mock(ProcessRunner::class);

        // Setup mocks for all prerequisite checks
        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['ollama', 'list'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'model list'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'src/User.php'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff content'));

        // Model availability check
        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        // Ollama analysis
        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'ATOMIC: YES
RELATED: YES
LOGIC_SOUND: YES
PURPOSE: Add user authentication feature
ISSUES: none',
            ));

        $check = new LogicCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('Add user authentication feature');
    });

    it('returns fail when commit is not atomic', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['ollama', 'list'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'model list'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: "src/User.php\nsrc/Payment.php"));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff content'));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'ATOMIC: NO
RELATED: NO
LOGIC_SOUND: YES
PURPOSE: Mixed changes
ISSUES: Mixing user auth and payment processing',
            ));

        $check = new LogicCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('Commit atomicity validation failed');
        expect($result->details)->toContain('Commit is NOT atomic - mixes multiple concerns');
        expect($result->details)->toContain('Changes are NOT all related');
    });

    it('pulls model if not available', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['ollama', 'list'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'model list'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'file.php'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff'));

        // Model not found
        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        // Should pull model
        $mockRunner->shouldReceive('run')
            ->once()
            ->with(['ollama', 'pull', 'llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'pulled'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'ATOMIC: YES
RELATED: YES
LOGIC_SOUND: YES
PURPOSE: Test
ISSUES: none',
            ));

        $check = new LogicCheck(processRunner: $mockRunner);
        $check->run('/tmp');
    });

    it('returns fail when Ollama analysis fails', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['ollama', 'list'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'model list'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'file.php'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff'));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        // Ollama command returns unsuccessful
        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $check = new LogicCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('Analysis failed');
    });

    it('returns fail when getting staged files fails', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['ollama', 'list'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'model list'));

        // getStagedFiles returns unsuccessful
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $check = new LogicCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No staged files to validate');
    });
});
