<?php

declare(strict_types=1);

use App\Transformers\TestFailurePromptTransformer;

describe('TestFailurePromptTransformer', function () {
    beforeEach(function () {
        $this->transformer = new TestFailurePromptTransformer;
    });

    describe('canHandle', function () {
        it('handles test check names', function () {
            expect($this->transformer->canHandle('test'))->toBeTrue();
            expect($this->transformer->canHandle('tests'))->toBeTrue();
            expect($this->transformer->canHandle('unit-tests'))->toBeTrue();
        });

        it('handles pest check names', function () {
            expect($this->transformer->canHandle('pest'))->toBeTrue();
            expect($this->transformer->canHandle('Pest'))->toBeTrue();
        });

        it('handles phpunit check names', function () {
            expect($this->transformer->canHandle('phpunit'))->toBeTrue();
            expect($this->transformer->canHandle('PHPUnit'))->toBeTrue();
        });

        it('rejects unrelated check names', function () {
            expect($this->transformer->canHandle('phpstan'))->toBeFalse();
            expect($this->transformer->canHandle('security'))->toBeFalse();
            expect($this->transformer->canHandle('lint'))->toBeFalse();
        });
    });

    describe('transform with JUnit XML', function () {
        it('parses junit xml with no failures', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests" tests="2" failures="0" errors="0">
                        <testcase name="test_one" class="TestClass" file="Test.php" line="10"/>
                        <testcase name="test_two" class="TestClass" file="Test.php" line="20"/>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('All tests passed');
            expect($result['summary']['passed'])->toBeTrue();
            expect($result['summary']['failures'])->toBe(0);
        });

        it('parses junit xml with failures', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests" tests="2" failures="1" errors="0">
                        <testcase name="test_passes" class="TestClass" file="Test.php" line="10"/>
                        <testcase name="test_fails" class="TestClass" file="Test.php" line="20">
                            <failure type="AssertionError">Expected true to be false</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Test Failures (1 total)');
            expect($result['prompt'])->toContain('test_fails');
            expect($result['prompt'])->toContain('Expected true to be false');
            expect($result['summary']['passed'])->toBeFalse();
            expect($result['summary']['failures'])->toBe(1);
        });

        it('parses junit xml with errors', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests" tests="1" failures="0" errors="1">
                        <testcase name="test_error" class="TestClass" file="Test.php" line="30">
                            <error type="Error">Call to undefined function foo()</error>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Test Failures (1 total)');
            expect($result['prompt'])->toContain('ERROR');
            expect($result['prompt'])->toContain('undefined function');
            expect($result['summary']['errors'])->toBe(1);
        });

        it('handles nested test suites', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="All Tests">
                        <testsuite name="Unit Tests">
                            <testcase name="test_one" class="UnitTest" file="Unit.php" line="10">
                                <failure type="AssertionError">Unit failure</failure>
                            </testcase>
                        </testsuite>
                        <testsuite name="Feature Tests">
                            <testcase name="test_two" class="FeatureTest" file="Feature.php" line="20">
                                <failure type="AssertionError">Feature failure</failure>
                            </testcase>
                        </testsuite>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['summary']['failures'])->toBe(2);
            expect($result['prompt'])->toContain('test_one');
            expect($result['prompt'])->toContain('test_two');
        });

        it('handles testsuite root without wrapper', function () {
            $xml = '<testsuites><testsuite name="Tests" tests="1" failures="1">
                <testcase name="test_fails" class="TestClass" file="Test.php" line="10">
                    <failure type="AssertionError">Failed</failure>
                </testcase>
            </testsuite></testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['summary']['failures'])->toBe(1);
        });

        it('falls back to pest parsing for invalid xml', function () {
            $invalidXml = '<?xml This is not valid XML';

            $result = $this->transformer->transform($invalidXml);

            // Should fall back and return something
            expect($result)->toBeArray();
            expect($result)->toHaveKey('prompt');
        });
    });

    describe('transform with Pest output', function () {
        it('parses pest output with failures', function () {
            $output = "
   PASS  Tests\\Unit\\ExampleTest
  ✓ it works

   FAIL  Tests\\Feature\\ExampleTest
  ✗ it fails
     Expected 'foo' to equal 'bar'.
     at tests/Feature/ExampleTest.php:15

  Tests:    1 passed, 1 failed
            ";

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('Test Failures');
            expect($result['prompt'])->toContain('it fails');
            expect($result['summary']['passed'])->toBeFalse();
        });

        it('parses pest output with x marker', function () {
            $output = '
  ⨯ it should work
     Failed asserting that false is true.
     at tests/ExampleTest.php:10
            ';

            $result = $this->transformer->transform($output);

            expect($result['summary']['passed'])->toBeFalse();
            expect($result['prompt'])->toContain('it should work');
        });

        it('returns passed for all tests passing', function () {
            $output = '
   PASS  Tests\\Unit\\ExampleTest
  ✓ it works
  ✓ it also works

  Tests:    2 passed (4 assertions)
            ';

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('All tests passed');
            expect($result['summary']['passed'])->toBeTrue();
        });

        it('handles unparseable output', function () {
            $output = 'Some random output with no test markers';

            $result = $this->transformer->transform($output);

            expect($result['summary']['valid'])->toBeFalse();
            expect($result['prompt'])->toContain('could not be parsed');
        });

        it('extracts file from trace', function () {
            $output = '
  ✗ test failure
     Error message
     at /path/to/file.php:42
            ';

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('file.php');
        });
    });

    describe('fix directions', function () {
        it('provides fix for assertEquals', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>Failed assertEquals: expected 5 got 3</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Check the expected vs actual values');
        });

        it('provides fix for assertTrue', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>assertTrue failed</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('evaluated to false');
        });

        it('provides fix for assertNull', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>assertNull failed</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('non-null value was returned');
        });

        it('provides fix for database assertions', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>assertDatabaseHas failed for table users</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Record not found in database');
        });

        it('provides fix for assertStatus', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>assertStatus 200 but got 404</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('HTTP response has wrong status code');
        });

        it('infers fix for matches expected pattern', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>Failed asserting that "actual" matches expected "expected"</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('trace the logic');
        });

        it('infers fix for exception messages', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>Unexpected exception was thrown</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('stack trace');
        });

        it('infers fix for null messages', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>Got null instead of expected value</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Unexpected null value');
        });

        it('infers fix for database/sql messages', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>SQL query returned wrong count</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Database assertion failed');
        });

        it('provides generic fix for unknown patterns', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>Some completely unique pattern xyzzyx</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            // Generic fix for unknown patterns
            expect($result['prompt'])->toContain('Review the test');
        });
    });

    describe('message cleaning', function () {
        it('removes ansi color codes', function () {
            // Test cleanMessage method directly via reflection
            $transformer = new TestFailurePromptTransformer;
            $reflection = new ReflectionClass($transformer);
            $method = $reflection->getMethod('cleanMessage');
            $method->setAccessible(true);

            $input = "\x1B[31mRed error message\x1B[0m";
            $result = $method->invoke($transformer, $input);

            expect($result)->not->toContain("\x1B[31m");
            expect($result)->toContain('Red error message');
        });

        it('truncates long messages', function () {
            $longMessage = str_repeat('A', 600);
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test" class="Test" file="Test.php" line="10">
                            <failure>'.$longMessage.'</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('truncated');
        });
    });

    describe('summary', function () {
        it('includes test names in summary', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test_one" class="Test" file="Test.php" line="10">
                            <failure>Failed</failure>
                        </testcase>
                        <testcase name="test_two" class="Test" file="Test.php" line="20">
                            <failure>Also failed</failure>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['summary']['tests'])->toContain('test_one');
            expect($result['summary']['tests'])->toContain('test_two');
        });

        it('separates failures and errors count', function () {
            $xml = '<?xml version="1.0"?>
                <testsuites>
                    <testsuite name="Tests">
                        <testcase name="test_fail" class="Test" file="Test.php" line="10">
                            <failure>Assertion failed</failure>
                        </testcase>
                        <testcase name="test_error" class="Test" file="Test.php" line="20">
                            <error>Runtime error</error>
                        </testcase>
                    </testsuite>
                </testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['summary']['failures'])->toBe(1);
            expect($result['summary']['errors'])->toBe(1);
        });
    });
});
