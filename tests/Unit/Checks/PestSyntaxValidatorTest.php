<?php

declare(strict_types=1);

use App\Checks\PestSyntaxValidator;

describe('PestSyntaxValidator', function () {
    it('has a descriptive name', function () {
        $validator = new PestSyntaxValidator;
        expect($validator->name())->toBe('Pest Syntax');
    });

    it('implements CheckInterface', function () {
        $validator = new PestSyntaxValidator;
        expect($validator)->toBeInstanceOf(\App\Checks\CheckInterface::class);
    });

    it('passes when test files use describe/it blocks', function () {
        $validator = new PestSyntaxValidator;

        // Create a temp test file with valid syntax
        $tempDir = sys_get_temp_dir().'/gate-test-'.uniqid();
        mkdir($tempDir.'/tests', recursive: true);
        file_put_contents($tempDir.'/tests/ExampleTest.php', <<<'PHP'
<?php
describe('Example', function () {
    it('works', function () {
        expect(true)->toBeTrue();
    });
});
PHP);

        $result = $validator->run($tempDir);

        expect($result->passed)->toBeTrue();

        // Cleanup
        unlink($tempDir.'/tests/ExampleTest.php');
        rmdir($tempDir.'/tests');
        rmdir($tempDir);
    });

    it('passes when no tests directory exists', function () {
        $validator = new PestSyntaxValidator;

        $tempDir = sys_get_temp_dir().'/gate-test-'.uniqid();
        mkdir($tempDir);

        $result = $validator->run($tempDir);

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No tests directory found');

        rmdir($tempDir);
    });

    it('fails when test files use test() function', function () {
        $validator = new PestSyntaxValidator;

        // Create a temp test file with invalid syntax
        // Note: Using concatenation to avoid triggering our own syntax validator
        $tempDir = sys_get_temp_dir().'/gate-test-'.uniqid();
        mkdir($tempDir.'/tests', recursive: true);
        $badContent = "<?php\n"."test('something works', function () {\n    expect(true)->toBeTrue();\n});";
        file_put_contents($tempDir.'/tests/BadTest.php', $badContent);

        $result = $validator->run($tempDir);

        expect($result->passed)->toBeFalse();
        expect($result->message)->toContain('test() function');

        // Cleanup
        unlink($tempDir.'/tests/BadTest.php');
        rmdir($tempDir.'/tests');
        rmdir($tempDir);
    });
});
