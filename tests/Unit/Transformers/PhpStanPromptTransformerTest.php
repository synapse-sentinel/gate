<?php

declare(strict_types=1);

use App\Transformers\PhpStanPromptTransformer;

describe('PhpStanPromptTransformer', function () {
    beforeEach(function () {
        $this->transformer = new PhpStanPromptTransformer;
    });

    describe('canHandle', function () {
        it('returns true for phpstan', function () {
            expect($this->transformer->canHandle('phpstan'))->toBeTrue();
        });

        it('returns true for PHPStan Analysis (case-insensitive)', function () {
            expect($this->transformer->canHandle('PHPStan Analysis'))->toBeTrue();
        });

        it('returns true for analyse', function () {
            expect($this->transformer->canHandle('analyse'))->toBeTrue();
        });

        it('returns true for static', function () {
            expect($this->transformer->canHandle('static'))->toBeTrue();
        });

        it('returns false for tests', function () {
            expect($this->transformer->canHandle('tests'))->toBeFalse();
        });

        it('returns false for security', function () {
            expect($this->transformer->canHandle('security'))->toBeFalse();
        });

        it('returns false for style', function () {
            expect($this->transformer->canHandle('style'))->toBeFalse();
        });
    });

    describe('transform', function () {
        describe('invalid input', function () {
            it('returns parse error for non-JSON input', function () {
                $result = $this->transformer->transform('not json at all');

                expect($result['prompt'])->toBe('PHPStan output could not be parsed.')
                    ->and($result['summary'])->toBe(['valid' => false]);
            });

            it('returns parse error for empty string', function () {
                $result = $this->transformer->transform('');

                expect($result['prompt'])->toBe('PHPStan output could not be parsed.')
                    ->and($result['summary'])->toBe(['valid' => false]);
            });

            it('returns parse error for malformed JSON', function () {
                $result = $this->transformer->transform('{invalid json}');

                expect($result['prompt'])->toBe('PHPStan output could not be parsed.')
                    ->and($result['summary'])->toBe(['valid' => false]);
            });

            it('returns parse error when totals key is missing', function () {
                $result = $this->transformer->transform(json_encode(['files' => []]));

                expect($result['prompt'])->toBe('PHPStan output could not be parsed.')
                    ->and($result['summary'])->toBe(['valid' => false]);
            });

            it('returns parse error when files key is missing', function () {
                $result = $this->transformer->transform(json_encode(['totals' => ['file_errors' => 0]]));

                expect($result['prompt'])->toBe('PHPStan output could not be parsed.')
                    ->and($result['summary'])->toBe(['valid' => false]);
            });

            it('returns parse error when closing brace appears before opening', function () {
                $result = $this->transformer->transform('}only closing{');

                expect($result['prompt'])->toBe('PHPStan output could not be parsed.')
                    ->and($result['summary'])->toBe(['valid' => false]);
            });
        });

        describe('passing output', function () {
            it('returns passed prompt for zero errors', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 0],
                    'files' => [],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toBe('PHPStan passed with no errors.')
                    ->and($result['summary'])->toBe(['passed' => true, 'errors' => 0]);
            });

            it('returns passed prompt when file_errors key is missing', function () {
                $output = json_encode([
                    'totals' => [],
                    'files' => [],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toBe('PHPStan passed with no errors.')
                    ->and($result['summary'])->toBe(['passed' => true, 'errors' => 0]);
            });
        });

        describe('error output', function () {
            it('generates prompt for single error', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Foo.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 42,
                                    'message' => 'Parameter #1 $x expects int, string given.',
                                    'identifier' => 'argument.type',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('## PHPStan Errors (1 total)')
                    ->and($result['prompt'])->toContain('(1 error)')
                    ->and($result['prompt'])->toContain('**Line 42** `argument.type`')
                    ->and($result['prompt'])->toContain('Parameter #1 $x expects int, string given.')
                    ->and($result['prompt'])->toContain('Cast the input to the expected type')
                    ->and($result['summary']['passed'])->toBeFalse()
                    ->and($result['summary']['errors'])->toBe(1)
                    ->and($result['summary']['files'])->toBe(1)
                    ->and($result['summary']['types'])->toBe(['argument.type' => 1]);
            });

            it('pluralizes errors correctly for multiple errors', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 3],
                    'files' => [
                        '/app/Bar.php' => [
                            'errors' => 3,
                            'messages' => [
                                ['line' => 10, 'message' => 'Error one', 'identifier' => 'argument.type'],
                                ['line' => 20, 'message' => 'Error two', 'identifier' => 'return.type'],
                                ['line' => 30, 'message' => 'Error three', 'identifier' => 'argument.type'],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('(3 errors)')
                    ->and($result['prompt'])->not->toContain('(3 error)');
            });

            it('sorts files by error count descending', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 4],
                    'files' => [
                        '/app/FewErrors.php' => [
                            'errors' => 1,
                            'messages' => [
                                ['line' => 1, 'message' => 'Error', 'identifier' => 'return.type'],
                            ],
                        ],
                        '/app/ManyErrors.php' => [
                            'errors' => 3,
                            'messages' => [
                                ['line' => 1, 'message' => 'Error', 'identifier' => 'argument.type'],
                                ['line' => 2, 'message' => 'Error', 'identifier' => 'argument.type'],
                                ['line' => 3, 'message' => 'Error', 'identifier' => 'argument.type'],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                $manyPos = strpos($result['prompt'], 'ManyErrors.php');
                $fewPos = strpos($result['prompt'], 'FewErrors.php');

                expect($manyPos)->toBeLessThan($fewPos);
            });

            it('includes tip when present', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Tip.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 5,
                                    'message' => 'Some error',
                                    'identifier' => 'argument.type',
                                    'tip' => 'Try using strict types',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('Try using strict types');
            });

            it('handles error with no identifier', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/NoId.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 1,
                                    'message' => 'Something went wrong',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('**Line 1**')
                    ->and($result['prompt'])->not->toContain('``')
                    ->and($result['prompt'])->toContain('Review the error and ensure types match declarations.');
            });

            it('handles error with no line number', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/NoLine.php' => [
                            'errors' => 1,
                            'messages' => [
                                ['message' => 'No line error', 'identifier' => 'return.type'],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('**Line ?**');
            });

            it('handles error with no message', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/NoMsg.php' => [
                            'errors' => 1,
                            'messages' => [
                                ['line' => 1, 'identifier' => 'return.type'],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('Unknown error');
            });

            it('handles file with empty messages array', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Empty.php' => [
                            'errors' => 1,
                            'messages' => [],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['summary']['files'])->toBe(1)
                    ->and($result['summary']['types'])->toBe([]);
            });

            it('handles file with no messages key', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/NoMessages.php' => [
                            'errors' => 1,
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['summary']['files'])->toBe(1);
            });

            it('handles file with no errors key', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/NoErrorCount.php' => [
                            'messages' => [
                                ['line' => 1, 'message' => 'Error', 'identifier' => 'class.notFound'],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('(0 errors)');
            });
        });

        describe('JSON extraction from mixed output', function () {
            it('extracts JSON embedded in text', function () {
                $json = json_encode([
                    'totals' => ['file_errors' => 0],
                    'files' => [],
                ]);
                $output = "PHPStan is analyzing...\n{$json}\nDone!";

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toBe('PHPStan passed with no errors.');
            });

            it('handles output with only opening brace and no closing', function () {
                $result = $this->transformer->transform('some text { but no close');

                expect($result['prompt'])->toBe('PHPStan output could not be parsed.');
            });
        });
    });

    describe('fix directions', function () {
        describe('exact identifier match', function () {
            $knownIdentifiers = [
                'argument.type' => 'Cast the input to the expected type',
                'argument.named' => 'Check the parameter name spelling',
                'argument.count' => 'Add missing arguments or remove extra arguments',
                'return.type' => 'Fix the return statement to match',
                'return.void' => 'Remove the return value or change the return type from void',
                'return.missing' => 'Add a return statement or change the return type to void',
                'property.notFound' => 'Define the missing property',
                'property.nonObject' => 'Add a null check before accessing properties',
                'property.readonly' => 'Remove the assignment to readonly property',
                'method.notFound' => 'Define the method, fix spelling',
                'method.nonObject' => 'Add a null check before calling methods',
                'class.notFound' => 'Add use statement or fix the namespace',
                'missingType.iterableValue' => 'Add generic type: array<int, Type>',
                'missingType.generics' => 'Add generic parameters',
                'variable.undefined' => 'Define the variable before use',
                'variable.certainty' => 'Add a null check or assertion',
            ];

            foreach ($knownIdentifiers as $identifier => $expectedFix) {
                it("maps {$identifier} to its fix direction", function () use ($identifier, $expectedFix) {
                    $output = json_encode([
                        'totals' => ['file_errors' => 1],
                        'files' => [
                            '/app/Test.php' => [
                                'errors' => 1,
                                'messages' => [
                                    ['line' => 1, 'message' => 'Some error', 'identifier' => $identifier],
                                ],
                            ],
                        ],
                    ]);

                    $result = $this->transformer->transform($output);

                    expect($result['prompt'])->toContain($expectedFix);
                });
            }
        });

        describe('prefix match', function () {
            it('falls back to single-segment prefix when no exact match', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Test.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 1,
                                    'message' => 'Some error',
                                    'identifier' => 'argument.type.strict',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                // "argument" alone is not in FIX_DIRECTIONS, so falls through to inferFromMessage
                expect($result['prompt'])->toContain('Review the error and ensure types match declarations.');
            });
        });

        describe('message inference', function () {
            it('infers type mismatch from expects/given pattern', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Test.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 1,
                                    'message' => 'Method expects int, string given.',
                                    'identifier' => 'some.unknown.id',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('Type mismatch. Cast the value or update the type annotation.');
            });

            it('infers return type mismatch from should return/but returns pattern', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Test.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 1,
                                    'message' => 'Method should return string but returns int.',
                                    'identifier' => 'some.unknown.id',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('Return type mismatch. Fix the return statement or annotation.');
            });

            it('infers undefined method fix', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Test.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 1,
                                    'message' => 'Call to undefined method Foo::bar().',
                                    'identifier' => 'some.unknown.id',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('Method does not exist. Define it or fix the spelling.');
            });

            it('infers undefined variable fix', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Test.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 1,
                                    'message' => 'Undefined variable: $foo',
                                    'identifier' => 'some.unknown.id',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('Variable not defined. Initialize before use.');
            });

            it('returns generic fix for unrecognized messages', function () {
                $output = json_encode([
                    'totals' => ['file_errors' => 1],
                    'files' => [
                        '/app/Test.php' => [
                            'errors' => 1,
                            'messages' => [
                                [
                                    'line' => 1,
                                    'message' => 'Something completely unrecognizable happened.',
                                    'identifier' => 'some.unknown.id',
                                ],
                            ],
                        ],
                    ],
                ]);

                $result = $this->transformer->transform($output);

                expect($result['prompt'])->toContain('Review the error and ensure types match declarations.');
            });
        });
    });

    describe('collectErrorTypes', function () {
        it('counts error types across files and sorts by frequency', function () {
            $output = json_encode([
                'totals' => ['file_errors' => 5],
                'files' => [
                    '/app/A.php' => [
                        'errors' => 3,
                        'messages' => [
                            ['line' => 1, 'message' => 'E', 'identifier' => 'argument.type'],
                            ['line' => 2, 'message' => 'E', 'identifier' => 'return.type'],
                            ['line' => 3, 'message' => 'E', 'identifier' => 'argument.type'],
                        ],
                    ],
                    '/app/B.php' => [
                        'errors' => 2,
                        'messages' => [
                            ['line' => 1, 'message' => 'E', 'identifier' => 'class.notFound'],
                            ['line' => 2, 'message' => 'E', 'identifier' => 'argument.type'],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($output);

            expect($result['summary']['types'])->toBe([
                'argument.type' => 3,
                'return.type' => 1,
                'class.notFound' => 1,
            ]);
        });

        it('uses unknown for messages without identifier', function () {
            $output = json_encode([
                'totals' => ['file_errors' => 1],
                'files' => [
                    '/app/C.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 1, 'message' => 'E'],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($output);

            expect($result['summary']['types'])->toBe(['unknown' => 1]);
        });
    });

    describe('relativePath', function () {
        it('converts absolute paths relative to cwd', function () {
            $cwd = getcwd();
            $output = json_encode([
                'totals' => ['file_errors' => 1],
                'files' => [
                    $cwd.'/app/Foo.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 1, 'message' => 'Error', 'identifier' => 'return.type'],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('### app/Foo.php');
        });

        it('leaves paths unchanged when not under cwd', function () {
            $output = json_encode([
                'totals' => ['file_errors' => 1],
                'files' => [
                    '/some/other/path/Bar.php' => [
                        'errors' => 1,
                        'messages' => [
                            ['line' => 1, 'message' => 'Error', 'identifier' => 'return.type'],
                        ],
                    ],
                ],
            ]);

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('### /some/other/path/Bar.php');
        });
    });
});
