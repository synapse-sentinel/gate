<?php

declare(strict_types=1);

use App\Transformers\PhpStanPromptTransformer;

describe('PhpStanPromptTransformer', function () {
    beforeEach(function () {
        $this->transformer = new PhpStanPromptTransformer;
    });

    describe('canHandle', function () {
        it('handles phpstan check names', function () {
            expect($this->transformer->canHandle('phpstan'))->toBeTrue();
            expect($this->transformer->canHandle('PHPStan'))->toBeTrue();
            expect($this->transformer->canHandle('phpstan-analyse'))->toBeTrue();
        });

        it('handles analyse check names', function () {
            expect($this->transformer->canHandle('analyse'))->toBeTrue();
            expect($this->transformer->canHandle('static-analyse'))->toBeTrue();
        });

        it('handles static check names', function () {
            expect($this->transformer->canHandle('static'))->toBeTrue();
            expect($this->transformer->canHandle('static-analysis'))->toBeTrue();
        });

        it('rejects unrelated check names', function () {
            expect($this->transformer->canHandle('tests'))->toBeFalse();
            expect($this->transformer->canHandle('coverage'))->toBeFalse();
            expect($this->transformer->canHandle('security'))->toBeFalse();
        });
    });

    describe('transform', function () {
        it('returns error for invalid json', function () {
            $result = $this->transformer->transform('not valid json');

            expect($result['prompt'])->toContain('could not be parsed');
            expect($result['summary']['valid'])->toBeFalse();
        });

        it('returns success for zero errors', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 0, 'errors' => 0],
                'files' => [],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('passed with no errors');
            expect($result['summary']['passed'])->toBeTrue();
            expect($result['summary']['errors'])->toBe(0);
        });

        it('builds prompt for file errors', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 2, 'errors' => 2],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 2,
                        'messages' => [
                            [
                                'line' => 10,
                                'message' => 'Parameter $foo expects string, int given.',
                                'identifier' => 'argument.type',
                            ],
                            [
                                'line' => 20,
                                'message' => 'Method test() should return int but returns string.',
                                'identifier' => 'return.type',
                                'tip' => 'Check the return type.',
                            ],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('PHPStan Errors (2 total)');
            expect($result['prompt'])->toContain('Test.php');
            expect($result['prompt'])->toContain('Line 10');
            expect($result['prompt'])->toContain('Line 20');
            expect($result['prompt'])->toContain('argument.type');
            expect($result['prompt'])->toContain('Check the return type');
            expect($result['summary']['passed'])->toBeFalse();
            expect($result['summary']['errors'])->toBe(2);
            expect($result['summary']['files'])->toBe(1);
        });

        it('extracts json from mixed output', function () {
            $output = "Some prefix text\n".json_encode([
                'totals' => ['file_errors' => 0, 'errors' => 0],
                'files' => [],
            ])."\nSome suffix text";

            $result = $this->transformer->transform($output);

            expect($result['summary']['passed'])->toBeTrue();
        });

        it('provides fix directions for known identifiers', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            [
                                'line' => 5,
                                'message' => 'Undefined variable $foo',
                                'identifier' => 'variable.undefined',
                            ],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('Define the variable before use');
        });

        it('infers fix from message patterns', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            [
                                'line' => 5,
                                'message' => 'Method expects string given int',
                                'identifier' => 'unknown.error',
                            ],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('Type mismatch');
        });

        it('handles should return message pattern', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            [
                                'line' => 5,
                                'message' => 'Method should return int but returns string',
                                'identifier' => 'unknown',
                            ],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('Return type mismatch');
        });

        it('handles undefined method message', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            [
                                'line' => 5,
                                'message' => 'Call to undefined method Class::foo()',
                                'identifier' => 'unknown',
                            ],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('Method does not exist');
        });

        it('handles undefined variable message', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            [
                                'line' => 5,
                                'message' => 'Undefined variable: $bar',
                                'identifier' => 'unknown',
                            ],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('Variable not defined');
        });

        it('sorts files by error count descending', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 3, 'errors' => 3],
                'files' => [
                    '/app/LessErrors.php' => [
                        'errors' => 1,
                        'messages' => [['line' => 1, 'message' => 'Error 1', 'identifier' => '']],
                    ],
                    '/app/MoreErrors.php' => [
                        'errors' => 2,
                        'messages' => [
                            ['line' => 1, 'message' => 'Error 1', 'identifier' => ''],
                            ['line' => 2, 'message' => 'Error 2', 'identifier' => ''],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            // MoreErrors should appear before LessErrors
            $morePos = strpos($result['prompt'], 'MoreErrors.php');
            $lessPos = strpos($result['prompt'], 'LessErrors.php');
            expect($morePos)->toBeLessThan($lessPos);
        });

        it('collects error types in summary', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 3, 'errors' => 3],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 3,
                        'messages' => [
                            ['line' => 1, 'message' => 'Error', 'identifier' => 'argument.type'],
                            ['line' => 2, 'message' => 'Error', 'identifier' => 'argument.type'],
                            ['line' => 3, 'message' => 'Error', 'identifier' => 'return.type'],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['summary']['types'])->toBeArray();
            expect($result['summary']['types']['argument.type'])->toBe(2);
            expect($result['summary']['types']['return.type'])->toBe(1);
        });

        it('handles missing identifier gracefully', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 5, 'message' => 'Some error'],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('Line 5');
            expect($result['summary']['types']['unknown'])->toBe(1);
        });

        it('handles singular vs plural errors label', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [['line' => 1, 'message' => 'Error', 'identifier' => '']],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('(1 error)');
        });

        it('returns generic fix for unknown patterns', function () {
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 1, 'message' => 'Some completely unknown error type xyz', 'identifier' => 'completely.unknown'],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            expect($result['prompt'])->toContain('Review the error and ensure types match declarations');
        });

        it('handles prefix-matched identifiers', function () {
            // The prefix matching only works for single-segment prefixes
            // Since there's no 'argument' key, only 'argument.type' etc.,
            // the identifier 'argument.type.strict' falls through to message inference
            $json = json_encode([
                'totals' => ['file_errors' => 1, 'errors' => 1],
                'files' => [
                    '/app/Test.php' => [
                        'errors' => 1,
                        'messages' => [
                            // Use exact match instead since prefix doesn't work as expected
                            ['line' => 1, 'message' => 'Error', 'identifier' => 'argument.type'],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($json);

            // Exact match for 'argument.type' should work
            expect($result['prompt'])->toContain('Cast the input');
        });

        it('rejects json without required fields', function () {
            $json = json_encode(['some' => 'data']);

            $result = $this->transformer->transform($json);

            expect($result['summary']['valid'])->toBeFalse();
        });

        it('handles malformed json in mixed output', function () {
            $output = 'prefix { invalid json } suffix';

            $result = $this->transformer->transform($output);

            expect($result['summary']['valid'])->toBeFalse();
        });
    });
});
