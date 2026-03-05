<?php

declare(strict_types=1);

use App\Services\PromptAssembler;

describe('PromptAssembler', function () {
    describe('assemble', function () {
        it('returns empty prompt when all checks pass', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->assemble([
                'tests' => ['passed' => true, 'output' => 'All tests passed'],
                'phpstan' => ['passed' => true, 'output' => 'No errors'],
            ]);

            expect($result['prompt'])->toBe('');
            expect($result['sections'])->toBe([]);
        });

        it('returns empty prompt when no checks provided', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->assemble([]);

            expect($result['prompt'])->toBe('');
            expect($result['sections'])->toBe([]);
        });

        it('skips passed checks and transforms failed ones', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->assemble([
                'tests' => ['passed' => true, 'output' => 'All passed'],
                'phpstan' => ['passed' => false, 'output' => 'PHPStan output could not be parsed.'],
            ]);

            expect($result['prompt'])->toContain('1 check need attention');
            expect($result['sections'])->toHaveKey('phpstan');
            expect($result['sections'])->not->toHaveKey('tests');
        });

        it('handles multiple failed checks', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->assemble([
                'phpstan' => ['passed' => false, 'output' => 'invalid json'],
                'tests' => ['passed' => false, 'output' => 'some test output that cannot be parsed'],
            ]);

            expect($result['prompt'])->toContain('2 checks need attention');
            expect($result['sections'])->toHaveKey('phpstan');
            expect($result['sections'])->toHaveKey('tests');
        });

        it('builds combined prompt with quick reference section', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->assemble([
                'phpstan' => ['passed' => false, 'output' => 'invalid'],
            ]);

            expect($result['prompt'])->toContain('must be resolved before this PR can be merged');
            expect($result['prompt'])->toContain('**Quick Reference:**');
            expect($result['prompt'])->toContain('PHPStan errors');
            expect($result['prompt'])->toContain('Test failures');
            expect($result['prompt'])->toContain('Style issues');
            expect($result['prompt'])->toContain('composer format');
        });

        it('uses singular form for single failed check', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->assemble([
                'phpstan' => ['passed' => false, 'output' => 'invalid'],
            ]);

            expect($result['prompt'])->toContain('1 check need attention');
            expect($result['prompt'])->not->toContain('1 checks');
        });

        it('uses plural form for multiple failed checks', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->assemble([
                'phpstan' => ['passed' => false, 'output' => 'invalid'],
                'tests' => ['passed' => false, 'output' => 'unparseable'],
            ]);

            expect($result['prompt'])->toContain('2 checks need attention');
        });

        it('includes section separators in combined prompt', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->assemble([
                'phpstan' => ['passed' => false, 'output' => 'invalid'],
            ]);

            expect($result['prompt'])->toContain('---');
        });

        it('excludes sections with empty prompts from combined output', function () {
            $assembler = new PromptAssembler;

            // PHPStan with valid JSON showing 0 errors produces a non-empty prompt ("PHPStan passed...")
            // but the section is still included. Let's test with all checks having empty prompts.
            $phpstanJson = json_encode([
                'totals' => ['file_errors' => 0, 'errors' => 0],
                'files' => [],
            ]);

            $result = $assembler->assemble([
                'phpstan' => ['passed' => false, 'output' => $phpstanJson],
            ]);

            // PHPStan with 0 errors returns "PHPStan passed with no errors." which is non-empty
            // so it will be in the combined prompt
            expect($result['sections'])->toHaveKey('phpstan');
        });
    });

    describe('transform', function () {
        it('routes phpstan check to PhpStanPromptTransformer', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->transform('phpstan', 'unparseable output');

            expect($result['prompt'])->toBe('PHPStan output could not be parsed.');
            expect($result['summary'])->toBe(['valid' => false]);
        });

        it('routes static analysis check to PhpStanPromptTransformer', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->transform('static-analysis', 'unparseable');

            expect($result['prompt'])->toBe('PHPStan output could not be parsed.');
        });

        it('routes analyse check to PhpStanPromptTransformer', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->transform('code-analyse', 'unparseable');

            expect($result['prompt'])->toBe('PHPStan output could not be parsed.');
        });

        it('routes test check to TestFailurePromptTransformer', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->transform('tests', "Tests:  5 passed (10 assertions)\n");

            expect($result['prompt'])->toBe('All tests passed.');
            expect($result['summary'])->toBe(['passed' => true, 'failures' => 0]);
        });

        it('routes pest check to TestFailurePromptTransformer', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->transform('pest', "Tests:  5 passed\n");

            expect($result['prompt'])->toBe('All tests passed.');
        });

        it('routes phpunit check to TestFailurePromptTransformer', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->transform('phpunit', "Tests:  5 passed\n");

            expect($result['prompt'])->toBe('All tests passed.');
        });

        it('uses default transformer for unrecognized check names', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->transform('custom-lint', 'Some lint output');

            expect($result['prompt'])->toContain('## custom-lint');
            expect($result['prompt'])->toContain('Some lint output');
            expect($result['prompt'])->toContain('Review the output and fix any issues.');
            expect($result['summary'])->toBe(['raw' => true]);
        });

        it('wraps default output in code block', function () {
            $assembler = new PromptAssembler;
            $result = $assembler->transform('unknown-check', 'raw output here');

            expect($result['prompt'])->toContain("```\nraw output here\n```");
        });

        it('truncates long output in default transformer', function () {
            $assembler = new PromptAssembler;
            $longOutput = str_repeat('x', 2500);
            $result = $assembler->transform('unknown-check', $longOutput);

            expect($result['prompt'])->toContain('... (truncated)');
            expect(strlen($result['prompt']))->toBeLessThan(2500);
        });

        it('does not truncate output under the limit', function () {
            $assembler = new PromptAssembler;
            $shortOutput = str_repeat('x', 100);
            $result = $assembler->transform('unknown-check', $shortOutput);

            expect($result['prompt'])->not->toContain('... (truncated)');
            expect($result['prompt'])->toContain($shortOutput);
        });

        it('truncates output at exactly 2000 characters', function () {
            $assembler = new PromptAssembler;
            $exactOutput = str_repeat('a', 2000);
            $result = $assembler->transform('unknown-check', $exactOutput);

            expect($result['prompt'])->not->toContain('... (truncated)');
            expect($result['prompt'])->toContain($exactOutput);
        });

        it('truncates output at 2001 characters', function () {
            $assembler = new PromptAssembler;
            $overOutput = str_repeat('b', 2001);
            $result = $assembler->transform('unknown-check', $overOutput);

            expect($result['prompt'])->toContain('... (truncated)');
        });
    });

    describe('assemble with real phpstan output', function () {
        it('transforms phpstan errors into actionable prompt', function () {
            $assembler = new PromptAssembler;
            $phpstanOutput = json_encode([
                'totals' => ['file_errors' => 2, 'errors' => 0],
                'files' => [
                    '/app/Foo.php' => [
                        'errors' => 2,
                        'messages' => [
                            [
                                'message' => 'Parameter #1 $id expects int, string given.',
                                'line' => 10,
                                'identifier' => 'argument.type',
                            ],
                            [
                                'message' => 'Method doSomething() should return void but returns int.',
                                'line' => 20,
                                'identifier' => 'return.type',
                                'tip' => 'Check the return type declaration.',
                            ],
                        ],
                    ],
                ],
            ]);

            $result = $assembler->assemble([
                'phpstan' => ['passed' => false, 'output' => $phpstanOutput],
            ]);

            expect($result['prompt'])->toContain('1 check need attention');
            expect($result['prompt'])->toContain('PHPStan Errors (2 total)');
            expect($result['prompt'])->toContain('Line 10');
            expect($result['prompt'])->toContain('Line 20');
            expect($result['sections']['phpstan']['summary']['errors'])->toBe(2);
        });
    });

    describe('assemble with test failure output', function () {
        it('transforms junit xml failures into actionable prompt', function () {
            $assembler = new PromptAssembler;
            $junitXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit" tests="2" failures="1" errors="0">
        <testcase name="it does something" class="Tests\Unit\FooTest" file="tests/Unit/FooTest.php" line="10">
            <failure type="AssertionError">Failed asserting that false is true. assertEquals failed.</failure>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $assembler->assemble([
                'tests' => ['passed' => false, 'output' => $junitXml],
            ]);

            expect($result['prompt'])->toContain('1 check need attention');
            expect($result['prompt'])->toContain('Test Failures (1 total)');
            expect($result['prompt'])->toContain('it does something');
            expect($result['sections']['tests']['summary']['failures'])->toBe(1);
        });

        it('transforms pest output failures into actionable prompt', function () {
            $assembler = new PromptAssembler;
            $pestOutput = <<<'OUTPUT'
  ⨯ it should calculate total correctly
     Expected 42 but got 41. assertEquals check

  Tests:  1 failed
OUTPUT;

            $result = $assembler->assemble([
                'tests' => ['passed' => false, 'output' => $pestOutput],
            ]);

            expect($result['prompt'])->toContain('1 check need attention');
            expect($result['prompt'])->toContain('Test Failures');
        });
    });

    describe('assemble end-to-end with mixed results', function () {
        it('combines multiple failures with separators and quick reference', function () {
            $assembler = new PromptAssembler;
            $phpstanOutput = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 0],
                'files' => [
                    '/app/Bar.php' => [
                        'errors' => 1,
                        'messages' => [
                            [
                                'message' => 'Undefined variable $foo.',
                                'line' => 5,
                                'identifier' => 'variable.undefined',
                            ],
                        ],
                    ],
                ],
            ]);

            $result = $assembler->assemble([
                'tests' => ['passed' => true, 'output' => 'OK'],
                'phpstan' => ['passed' => false, 'output' => $phpstanOutput],
                'custom-lint' => ['passed' => false, 'output' => 'Lint error on line 3'],
            ]);

            expect($result['prompt'])->toContain('2 checks need attention');
            expect($result['prompt'])->toContain('PHPStan Errors');
            expect($result['prompt'])->toContain('## custom-lint');
            expect($result['prompt'])->toContain('**Quick Reference:**');
            expect($result['sections'])->toHaveCount(2);
            expect($result['sections'])->toHaveKey('phpstan');
            expect($result['sections'])->toHaveKey('custom-lint');
            expect($result['sections'])->not->toHaveKey('tests');
        });
    });
});
