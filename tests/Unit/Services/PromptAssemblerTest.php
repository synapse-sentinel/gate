<?php

declare(strict_types=1);

use App\Services\PromptAssembler;

describe('PromptAssembler', function () {
    beforeEach(function () {
        $this->assembler = new PromptAssembler;
    });

    describe('assemble', function () {
        it('returns empty prompt when all checks pass', function () {
            $checkResults = [
                'Tests & Coverage' => ['passed' => true, 'output' => ''],
                'Security Audit' => ['passed' => true, 'output' => ''],
            ];

            $result = $this->assembler->assemble($checkResults);

            expect($result['prompt'])->toBe('');
            expect($result['sections'])->toBe([]);
        });

        it('returns empty prompt when no checks provided', function () {
            $result = $this->assembler->assemble([]);

            expect($result['prompt'])->toBe('');
            expect($result['sections'])->toBe([]);
        });

        it('transforms failed checks into prompts', function () {
            $phpstanOutput = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 10, 'message' => 'Error message', 'identifier' => 'argument.type'],
                        ],
                    ],
                ],
            ]);

            $checkResults = [
                'phpstan' => ['passed' => false, 'output' => $phpstanOutput],
            ];

            $result = $this->assembler->assemble($checkResults);

            expect($result['prompt'])->toContain('Synapse Sentinel');
            expect($result['prompt'])->toContain('check');
            expect($result['sections'])->toHaveKey('phpstan');
        });

        it('combines multiple failed checks', function () {
            $phpstanOutput = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 10, 'message' => 'PHPStan error', 'identifier' => ''],
                        ],
                    ],
                ],
            ]);

            $testOutput = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test_fails" class="Test" file="Test.php" line="10">
                            <failure>Test failure</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $checkResults = [
                'phpstan' => ['passed' => false, 'output' => $phpstanOutput],
                'tests' => ['passed' => false, 'output' => $testOutput],
            ];

            $result = $this->assembler->assemble($checkResults);

            expect($result['prompt'])->toContain('2 checks');
            expect($result['sections'])->toHaveCount(2);
        });

        it('skips passed checks in output', function () {
            $phpstanOutput = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 10, 'message' => 'Error', 'identifier' => ''],
                        ],
                    ],
                ],
            ]);

            $checkResults = [
                'phpstan' => ['passed' => false, 'output' => $phpstanOutput],
                'security' => ['passed' => true, 'output' => 'All good'],
            ];

            $result = $this->assembler->assemble($checkResults);

            expect($result['prompt'])->toContain('1 check');
            expect($result['sections'])->toHaveCount(1);
        });

        it('uses singular form for single check', function () {
            $phpstanOutput = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 10, 'message' => 'Error', 'identifier' => ''],
                        ],
                    ],
                ],
            ]);

            $checkResults = [
                'phpstan' => ['passed' => false, 'output' => $phpstanOutput],
            ];

            $result = $this->assembler->assemble($checkResults);

            expect($result['prompt'])->toContain('1 check need');
            expect($result['prompt'])->not->toContain('checks need');
        });
    });

    describe('transform', function () {
        it('uses default transformer for unknown check types', function () {
            $result = $this->assembler->transform('unknown-check', 'Some raw output');

            expect($result['prompt'])->toContain('unknown-check');
            expect($result['prompt'])->toContain('Some raw output');
            expect($result['summary']['raw'])->toBeTrue();
        });

        it('truncates very long output in default transformer', function () {
            $longOutput = str_repeat('A', 3000);
            $result = $this->assembler->transform('unknown-check', $longOutput);

            expect($result['prompt'])->toContain('truncated');
            expect(strlen($result['prompt']))->toBeLessThan(3000);
        });

        it('does not truncate short output', function () {
            $shortOutput = 'Short output';
            $result = $this->assembler->transform('unknown-check', $shortOutput);

            expect($result['prompt'])->not->toContain('truncated');
            expect($result['prompt'])->toContain('Short output');
        });
    });
});
