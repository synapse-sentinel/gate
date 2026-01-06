<?php

declare(strict_types=1);

use App\Checks\CohesionCheck;
use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;

describe('CohesionCheck', function () {
    it('has a descriptive name', function () {
        $check = new CohesionCheck;
        expect($check->name())->toBe('PR Cohesion');
    });

    it('implements CheckInterface', function () {
        $check = new CohesionCheck;
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

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('Ollama not installed - skipping cohesion check');
    });

    it('returns pass when no changed files', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        // Try all base branches and return empty
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/master...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/develop...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No changed files to analyze');
    });

    it('tries multiple base branches when main fails', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        // Try main - fail
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        // Try master - succeed
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/master...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'src/User.php'));

        // Try main diff - fail (for getPRDiff)
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        // Try master diff - succeed (for getPRDiff)
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/master...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff content'));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'COHESIVE: YES
MISSING_FILES: none
DEPENDENCY_ISSUES: none
PURPOSE: Add user authentication
CONCERNS: none',
            ));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        // Verify the check succeeded using the fallback master branch
        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('Add user authentication');
    });

    it('returns pass when PR is cohesive', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: "app/Models/User.php\napp/Http/Controllers/UserController.php\ntests/Feature/UserTest.php"));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff content'));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'COHESIVE: YES
MISSING_FILES: none
DEPENDENCY_ISSUES: none
PURPOSE: Add user management feature with tests
CONCERNS: none',
            ));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('Add user management feature with tests');
    });

    it('returns fail when PR lacks cohesion', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: "app/Models/User.php\napp/Services/PaymentService.php"));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff content'));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'COHESIVE: NO
MISSING_FILES: tests for User and Payment changes
DEPENDENCY_ISSUES: User model changed but no migration
PURPOSE: Mixed user and payment changes
CONCERNS: Unrelated features in same PR',
            ));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('PR cohesion validation failed');
        expect($result->details)->toContain('PR lacks cohesion - mixing unrelated changes');
        expect($result->details)->toContain('Missing files: tests for User and Payment changes');
        expect($result->details)->toContain('Cross-file issues: User model changed but no migration');
        expect($result->details)->toContain('Concerns: Unrelated features in same PR');
    });

    it('categorizes files correctly', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: "app/Models/User.php\napp/Http/Controllers/UserController.php\nresources/views/users/index.blade.php\ntests/Feature/UserTest.php\ndatabase/migrations/2024_01_01_create_users.php\nconfig/auth.php\nroutes/web.php\napp/Services/UserService.php\nREADME.md",
            ));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff'));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'COHESIVE: YES
MISSING_FILES: none
DEPENDENCY_ISSUES: none
PURPOSE: Complete user feature
CONCERNS: none',
            ));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        // Verify the check completed successfully with categorized files
        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('Complete user feature');
    });

    it('returns fail when cohesion analysis fails', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'file.php'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff'));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        // Ollama command fails
        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('Cohesion analysis failed');
    });

    it('pulls model when not available', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'file.php'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff'));

        // Model not available - should trigger pull
        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $mockRunner->shouldReceive('run')
            ->once()
            ->with(['ollama', 'pull', 'llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'pulled'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'COHESIVE: YES
MISSING_FILES: none
DEPENDENCY_ISSUES: none
PURPOSE: Test
CONCERNS: none',
            ));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
    });

    it('returns pass when all branch diffs fail', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        // All git diff --name-only attempts fail
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/master...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/develop...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No changed files to analyze');
    });

    it('categorizes test config and route files correctly', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: "tests/Unit/FooTest.php\nconfig/app.php\nroutes/api.php",
            ));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'diff'));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'COHESIVE: YES
MISSING_FILES: none
DEPENDENCY_ISSUES: none
PURPOSE: Test
CONCERNS: none',
            ));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
    });

    it('returns fail when PR diff unavailable', function () {
        $mockRunner = mock(ProcessRunner::class);

        $mockRunner->shouldReceive('run')
            ->with(['which', 'ollama'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: '/usr/bin/ollama'));

        // getChangedFiles succeeds
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', '--name-only', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'file.php'));

        // But all getPRDiff attempts fail
        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/main...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/master...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $mockRunner->shouldReceive('run')
            ->with(['git', 'diff', 'origin/develop...HEAD'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: false, output: ''));

        $mockRunner->shouldReceive('run')
            ->with(['sh', '-c', 'ollama list | grep llama3.2:3b'], Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(successful: true, output: 'llama3.2:3b'));

        $mockRunner->shouldReceive('run')
            ->with(Mockery::on(fn ($cmd) => $cmd[0] === 'ollama' && $cmd[1] === 'run' && $cmd[2] === 'llama3.2:3b'), Mockery::any(), Mockery::any())
            ->andReturn(new ProcessResult(
                successful: true,
                output: 'COHESIVE: YES
MISSING_FILES: none
DEPENDENCY_ISSUES: none
PURPOSE: Test
CONCERNS: none',
            ));

        $check = new CohesionCheck(processRunner: $mockRunner);
        $result = $check->run('/tmp');

        expect($result->passed)->toBeTrue();
    });
});
