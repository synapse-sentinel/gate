<?php

declare(strict_types=1);

use App\Contracts\PromptTransformerInterface;
use App\Transformers\TestFailurePromptTransformer;

function buildXmlWithMessage(string $message): string
{
    $escaped = htmlspecialchars($message, ENT_XML1);

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it fails" class="FooTest" file="FooTest.php" line="1">
            <failure type="AssertionError">{$escaped}</failure>
        </testcase>
    </testsuite>
</testsuites>
XML;
}

describe('TestFailurePromptTransformer', function () {
    beforeEach(function () {
        $this->transformer = new TestFailurePromptTransformer;
    });

    it('implements PromptTransformerInterface', function () {
        expect($this->transformer)->toBeInstanceOf(PromptTransformerInterface::class);
    });

    describe('canHandle', function () {
        it('handles check names containing "test"', function () {
            expect($this->transformer->canHandle('test'))->toBeTrue();
            expect($this->transformer->canHandle('Tests & Coverage'))->toBeTrue();
            expect($this->transformer->canHandle('unit-test'))->toBeTrue();
        });

        it('handles check names containing "pest"', function () {
            expect($this->transformer->canHandle('pest'))->toBeTrue();
            expect($this->transformer->canHandle('Pest Syntax'))->toBeTrue();
        });

        it('handles check names containing "phpunit"', function () {
            expect($this->transformer->canHandle('phpunit'))->toBeTrue();
            expect($this->transformer->canHandle('PHPUnit Runner'))->toBeTrue();
        });

        it('rejects unrelated check names', function () {
            expect($this->transformer->canHandle('security'))->toBeFalse();
            expect($this->transformer->canHandle('syntax'))->toBeFalse();
            expect($this->transformer->canHandle('coverage'))->toBeFalse();
        });
    });

    describe('JUnit XML parsing', function () {
        it('parses JUnit XML with failures', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it does something" class="Tests\Unit\FooTest" file="tests/Unit/FooTest.php" line="10">
            <failure type="AssertionError">Failed asserting that false is true.</failure>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Test Failures (1 total)')
                ->and($result['prompt'])->toContain('it does something')
                ->and($result['prompt'])->toContain('FAIL')
                ->and($result['prompt'])->toContain('tests/Unit/FooTest.php:10')
                ->and($result['prompt'])->toContain('Failed asserting that false is true.')
                ->and($result['summary']['passed'])->toBeFalse()
                ->and($result['summary']['failures'])->toBe(1)
                ->and($result['summary']['errors'])->toBe(0)
                ->and($result['summary']['tests'])->toBe(['it does something']);
        });

        it('parses JUnit XML with errors', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it throws" class="Tests\Unit\BarTest" file="tests/Unit/BarTest.php" line="20">
            <error type="RuntimeException">Something exploded</error>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Test Failures (1 total)')
                ->and($result['prompt'])->toContain('ERROR')
                ->and($result['prompt'])->toContain('it throws')
                ->and($result['summary']['failures'])->toBe(0)
                ->and($result['summary']['errors'])->toBe(1);
        });

        it('parses JUnit XML with both failures and errors', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it fails" class="Tests\Unit\FooTest" file="tests/Unit/FooTest.php" line="10">
            <failure type="AssertionError">Expected true</failure>
        </testcase>
        <testcase name="it errors" class="Tests\Unit\BarTest" file="tests/Unit/BarTest.php" line="20">
            <error type="Error">Fatal error</error>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Test Failures (2 total)')
                ->and($result['summary']['failures'])->toBe(1)
                ->and($result['summary']['errors'])->toBe(1)
                ->and($result['summary']['tests'])->toHaveCount(2);
        });

        it('returns all-passed for JUnit XML with no failures', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it works" class="Tests\Unit\FooTest" file="tests/Unit/FooTest.php" line="5"/>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toBe('All tests passed.')
                ->and($result['summary']['passed'])->toBeTrue()
                ->and($result['summary']['failures'])->toBe(0);
        });

        it('handles testsuite root without testsuites wrapper', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuite name="Unit">
    <testcase name="it fails" class="Tests\Unit\FooTest" file="tests/Unit/FooTest.php" line="10">
        <failure type="AssertionError">Expected true</failure>
    </testcase>
</testsuite>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Test Failures (1 total)')
                ->and($result['summary']['failures'])->toBe(1);
        });

        it('handles nested test suites', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testsuite name="Nested">
            <testcase name="it fails nested" class="Tests\Unit\NestedTest" file="tests/Unit/NestedTest.php" line="5">
                <failure type="AssertionError">Nested failure</failure>
            </testcase>
        </testsuite>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('it fails nested')
                ->and($result['summary']['failures'])->toBe(1);
        });

        it('falls back to Pest parsing for invalid XML', function () {
            $invalidXml = '<?xml version="1.0"?><broken><unclosed>';

            $result = $this->transformer->transform($invalidXml);

            expect($result)->toHaveKeys(['prompt', 'summary']);
        });

        it('handles testcase with missing attributes', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase>
            <failure>Some failure message</failure>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Unknown')
                ->and($result['prompt'])->toContain('Unknown file')
                ->and($result['summary']['failures'])->toBe(1);
        });

        it('detects XML by testsuites tag without xml declaration', function () {
            $xml = '<testsuites><testsuite name="Unit"><testcase name="it works"/></testsuite></testsuites>';

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toBe('All tests passed.');
        });

        it('handles failure with missing type attribute', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it fails" class="FooTest" file="FooTest.php" line="1">
            <failure>No type attribute</failure>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['summary']['failures'])->toBe(1);
        });

        it('handles error with missing type attribute', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it errors" class="FooTest" file="FooTest.php" line="1">
            <error>No type attribute</error>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['summary']['errors'])->toBe(1);
        });
    });

    describe('Pest output parsing', function () {
        it('parses Pest failures with cross mark', function () {
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it does something  0.5s
    Expected true to be false.
    at tests/Unit/FooTest.php:42

Tests:  1 failed
OUTPUT;

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('Test Failures (1 total)')
                ->and($result['prompt'])->toContain('it does something')
                ->and($result['prompt'])->toContain('tests/Unit/FooTest.php')
                ->and($result['summary']['passed'])->toBeFalse()
                ->and($result['summary']['failures'])->toBe(1);
        });

        it('parses Pest failures with X mark', function () {
            $output = <<<OUTPUT
✗ Tests\Unit\BarTest → it breaks  0.3s
    Values don't match.
    at tests/Unit/BarTest.php:18

Tests:  1 failed
OUTPUT;

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('Test Failures (1 total)')
                ->and($result['prompt'])->toContain('it breaks')
                ->and($result['summary']['failures'])->toBe(1);
        });

        it('parses multiple Pest failures', function () {
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it fails first  0.5s
    Expected true to be false.
    at tests/Unit/FooTest.php:42

⨯ Tests\Unit\BarTest → it fails second  0.3s
    Values don't match.
    at tests/Unit/BarTest.php:18

Tests:  2 failed
OUTPUT;

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('Test Failures (2 total)')
                ->and($result['summary']['failures'])->toBe(2)
                ->and($result['summary']['tests'])->toHaveCount(2);
        });

        it('returns all-passed for passing Pest output', function () {
            $output = "Tests:  10 passed\nTime:  0.5s";

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toBe('All tests passed.')
                ->and($result['summary']['passed'])->toBeTrue()
                ->and($result['summary']['failures'])->toBe(0);
        });

        it('returns unparseable prompt for unrecognized output', function () {
            $output = 'Some random output that is not test results';

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('Test output could not be parsed')
                ->and($result['prompt'])->toContain('Some random output')
                ->and($result['summary']['valid'])->toBeFalse();
        });

        it('extracts file path from trace', function () {
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it fails  0.5s
    Failed assertion at tests/Unit/FooTest.php:42

Tests:  1 failed
OUTPUT;

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('tests/Unit/FooTest.php');
        });

        it('extracts line number from trace', function () {
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it fails  0.5s
    at tests/Unit/FooTest.php:99

Tests:  1 failed
OUTPUT;

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain(':99');
        });

        it('handles trace without file path', function () {
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it fails  0.5s
    Something went wrong with no file reference

Tests:  1 failed
OUTPUT;

            $result = $this->transformer->transform($output);

            expect($result['prompt'])->toContain('Unknown file');
        });
    });

    describe('fix directions', function () {
        it('provides fix direction for assertEquals', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('Failed assertEquals: expected 5, got 10'));

            expect($result['prompt'])->toContain('Check the expected vs actual values');
        });

        it('provides fix direction for assertSame', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertSame failed: types differ'));

            expect($result['prompt'])->toContain('identical (type + value)');
        });

        it('provides fix direction for assertTrue', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertTrue failed'));

            expect($result['prompt'])->toContain('condition evaluated to false');
        });

        it('provides fix direction for assertFalse', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertFalse failed'));

            expect($result['prompt'])->toContain('condition evaluated to true');
        });

        it('provides fix direction for assertNull', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertNull failed: got object'));

            expect($result['prompt'])->toContain('non-null value was returned');
        });

        it('provides fix direction for assertNotNull', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertNotNull failed'));

            expect($result['prompt'])->toContain('Null was returned');
        });

        it('provides fix direction for assertInstanceOf', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertInstanceOf failed'));

            expect($result['prompt'])->toContain('Wrong class type');
        });

        it('provides fix direction for assertCount', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertCount failed'));

            expect($result['prompt'])->toContain('wrong number of items');
        });

        it('provides fix direction for assertEmpty', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertEmpty failed'));

            expect($result['prompt'])->toContain('not empty when it should be');
        });

        it('provides fix direction for assertNotEmpty', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertNotEmpty failed'));

            expect($result['prompt'])->toContain('empty when it should have items');
        });

        it('provides fix direction for assertContains', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertContains failed'));

            expect($result['prompt'])->toContain('not found in collection');
        });

        it('provides fix direction for assertArrayHasKey', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertArrayHasKey failed'));

            expect($result['prompt'])->toContain('missing expected key');
        });

        it('provides fix direction for assertStringContains', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertStringContains failed'));

            expect($result['prompt'])->toContain('substring not found');
        });

        it('provides fix direction for assertJson', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertJson failed'));

            expect($result['prompt'])->toContain('Invalid JSON');
        });

        it('provides fix direction for assertDatabaseHas', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertDatabaseHas failed'));

            expect($result['prompt'])->toContain('Record not found');
        });

        it('provides fix direction for assertDatabaseMissing', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertDatabaseMissing failed'));

            expect($result['prompt'])->toContain('Record exists');
        });

        it('provides fix direction for assertStatus', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertStatus failed: expected 200 got 500'));

            expect($result['prompt'])->toContain('wrong status code');
        });

        it('provides fix direction for assertRedirect', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertRedirect failed'));

            expect($result['prompt'])->toContain('not a redirect');
        });

        it('provides fix direction for assertSee', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertSee failed'));

            expect($result['prompt'])->toContain('not visible in response');
        });

        it('provides fix direction for assertAuthenticated', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertAuthenticated failed'));

            expect($result['prompt'])->toContain('not authenticated');
        });

        it('provides fix direction for expectException', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('expectException was not thrown'));

            expect($result['prompt'])->toContain('Expected exception was not thrown');
        });

        it('provides fix direction for "failed asserting matches expected" pattern', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('Failed asserting that "foo" matches expected "bar"'));

            expect($result['prompt'])->toContain("Values don't match");
        });

        it('provides fix direction for exception messages', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('Unexpected exception was raised during test'));

            expect($result['prompt'])->toContain('Check the stack trace');
        });

        it('provides fix direction for error messages', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('A fatal error occurred in the handler'));

            expect($result['prompt'])->toContain('Check the stack trace');
        });

        it('provides fix direction for null-related messages', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('Call to a member function on null'));

            expect($result['prompt'])->toContain('Unexpected null value');
        });

        it('provides fix direction for database-related messages', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('Database connection refused'));

            expect($result['prompt'])->toContain('Database assertion failed');
        });

        it('provides fix direction for SQL-related messages', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('SQL query failed to return rows'));

            expect($result['prompt'])->toContain('Database assertion failed');
        });

        it('provides generic fix direction for unrecognized patterns', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('Something completely unknown happened'));

            expect($result['prompt'])->toContain('Review the test expectation vs actual behavior');
        });
    });

    describe('message cleaning', function () {
        it('removes ANSI color codes from messages', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it fails" class="FooTest" file="FooTest.php" line="1">
            <failure type="AssertionError">&#x1B;[31mFailed assertion&#x1B;[0m</failure>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Failed assertion')
                ->and($result['prompt'])->not->toContain("\x1B[31m");
        });

        it('truncates messages longer than 500 characters', function () {
            $result = $this->transformer->transform(buildXmlWithMessage(str_repeat('A', 600)));

            expect($result['prompt'])->toContain('... (truncated)');
        });

        it('does not truncate messages under 500 characters', function () {
            $result = $this->transformer->transform(buildXmlWithMessage(str_repeat('B', 100)));

            expect($result['prompt'])->not->toContain('(truncated)');
        });
    });

    describe('prompt formatting', function () {
        it('numbers failures sequentially', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="first test" class="FooTest" file="FooTest.php" line="1">
            <failure type="AssertionError">First failure</failure>
        </testcase>
        <testcase name="second test" class="BarTest" file="BarTest.php" line="2">
            <failure type="AssertionError">Second failure</failure>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('### 1. first test')
                ->and($result['prompt'])->toContain('### 2. second test');
        });

        it('numbers errors after failures', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="failure test" class="FooTest" file="FooTest.php" line="1">
            <failure type="AssertionError">A failure</failure>
        </testcase>
        <testcase name="error test" class="BarTest" file="BarTest.php" line="2">
            <error type="Error">An error</error>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('### 1. failure test')
                ->and($result['prompt'])->toContain('### 2. error test');
        });

        it('shows "Fix these failing tests" header', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('Some failure'));

            expect($result['prompt'])->toContain('Fix these failing tests:');
        });

        it('uses Unknown file when file is empty', function () {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
    <testsuite name="Unit">
        <testcase name="it fails" class="FooTest" file="" line="0">
            <failure type="AssertionError">Some failure</failure>
        </testcase>
    </testsuite>
</testsuites>
XML;

            $result = $this->transformer->transform($xml);

            expect($result['prompt'])->toContain('Unknown file:?');
        });

        it('includes Fix direction in output', function () {
            $result = $this->transformer->transform(buildXmlWithMessage('assertTrue failed'));

            expect($result['prompt'])->toContain('**Fix:**');
        });
    });

    describe('context parameter', function () {
        it('accepts context parameter without error', function () {
            $result = $this->transformer->transform("Tests:  5 passed\nTime:  0.5s", ['path' => '/some/path']);

            expect($result['prompt'])->toBe('All tests passed.');
        });
    });
});
