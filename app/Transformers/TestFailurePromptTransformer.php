<?php

declare(strict_types=1);

namespace App\Transformers;

use App\Contracts\PromptTransformerInterface;

/**
 * Transforms Pest/PHPUnit test failures into actionable Claude prompts.
 *
 * Supports both JUnit XML format (--log-junit) and raw Pest output.
 */
final class TestFailurePromptTransformer implements PromptTransformerInterface
{
    /**
     * Common assertion patterns mapped to fix directions.
     */
    private const ASSERTION_FIXES = [
        'assertEquals' => 'Check the expected vs actual values. Verify your logic produces the expected result.',
        'assertSame' => 'Values must be identical (type + value). Check for type coercion issues.',
        'assertTrue' => 'The condition evaluated to false. Trace the logic to understand why.',
        'assertFalse' => 'The condition evaluated to true when it should be false.',
        'assertNull' => 'A non-null value was returned. Check what\'s being returned.',
        'assertNotNull' => 'Null was returned when a value was expected.',
        'assertInstanceOf' => 'Wrong class type returned. Check the factory/creation logic.',
        'assertCount' => 'Collection has wrong number of items. Check filtering/mapping logic.',
        'assertEmpty' => 'Collection is not empty when it should be.',
        'assertNotEmpty' => 'Collection is empty when it should have items.',
        'assertContains' => 'Expected item not found in collection.',
        'assertArrayHasKey' => 'Array missing expected key. Check the data structure.',
        'assertStringContains' => 'Expected substring not found in string.',
        'assertJson' => 'Invalid JSON or JSON structure mismatch.',
        'assertDatabaseHas' => 'Record not found in database. Check if it was created/saved.',
        'assertDatabaseMissing' => 'Record exists when it should have been deleted.',
        'assertStatus' => 'HTTP response has wrong status code. Check controller logic.',
        'assertRedirect' => 'Response is not a redirect. Check controller return statement.',
        'assertSee' => 'Expected text not visible in response. Check view/template.',
        'assertAuthenticated' => 'User is not authenticated. Check auth middleware/guards.',
        'expectException' => 'Expected exception was not thrown. Check conditional logic.',
    ];

    public function transform(string $output, array $context = []): array
    {
        // Try JUnit XML first
        if (str_contains($output, '<?xml') || str_contains($output, '<testsuites')) {
            return $this->parseJunitXml($output);
        }

        // Fall back to Pest output parsing
        return $this->parsePestOutput($output);
    }

    public function canHandle(string $checkName): bool
    {
        return str_contains(strtolower($checkName), 'test')
            || str_contains(strtolower($checkName), 'pest')
            || str_contains(strtolower($checkName), 'phpunit');
    }

    /**
     * Parse JUnit XML format (preferred).
     *
     * @return array{prompt: string, summary: array<string, mixed>}
     */
    private function parseJunitXml(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            return $this->parsePestOutput($xml);
        }

        $failures = [];
        $errors = [];

        // Handle both <testsuites><testsuite> and <testsuite> root
        $suites = $doc->testsuite ?? [$doc];

        foreach ($suites as $suite) {
            $this->extractTestCases($suite, $failures, $errors);
        }

        if (empty($failures) && empty($errors)) {
            return [
                'prompt' => 'All tests passed.',
                'summary' => ['passed' => true, 'failures' => 0],
            ];
        }

        return $this->buildPromptFromFailures($failures, $errors);
    }

    /**
     * Extract test cases from a test suite.
     *
     * @param  \SimpleXMLElement  $suite
     * @param  array<array<string, mixed>>  $failures
     * @param  array<array<string, mixed>>  $errors
     */
    private function extractTestCases(\SimpleXMLElement $suite, array &$failures, array &$errors): void
    {
        foreach ($suite->testcase ?? [] as $testcase) {
            $name = (string) ($testcase['name'] ?? 'Unknown');
            $class = (string) ($testcase['class'] ?? '');
            $file = (string) ($testcase['file'] ?? '');
            $line = (int) ($testcase['line'] ?? 0);

            // Check for failures
            if (isset($testcase->failure)) {
                $failures[] = [
                    'name' => $name,
                    'class' => $class,
                    'file' => $file,
                    'line' => $line,
                    'message' => (string) $testcase->failure,
                    'type' => (string) ($testcase->failure['type'] ?? 'AssertionError'),
                ];
            }

            // Check for errors
            if (isset($testcase->error)) {
                $errors[] = [
                    'name' => $name,
                    'class' => $class,
                    'file' => $file,
                    'line' => $line,
                    'message' => (string) $testcase->error,
                    'type' => (string) ($testcase->error['type'] ?? 'Error'),
                ];
            }
        }

        // Recurse into nested suites
        foreach ($suite->testsuite ?? [] as $nested) {
            $this->extractTestCases($nested, $failures, $errors);
        }
    }

    /**
     * Parse raw Pest output format.
     *
     * @return array{prompt: string, summary: array<string, mixed>}
     */
    private function parsePestOutput(string $output): array
    {
        $failures = [];

        // Match Pest failure pattern: ⨯ or ✗ followed by test name and details
        $pattern = '/[⨯✗]\s+([^\n]+)\n((?:[ \t]+[^\n]+\n)*)/u';

        if (preg_match_all($pattern, $output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $failures[] = [
                    'name' => trim($match[1]),
                    'class' => '',
                    'file' => $this->extractFileFromTrace($match[2] ?? ''),
                    'line' => $this->extractLineFromTrace($match[2] ?? ''),
                    'message' => trim($match[2] ?? ''),
                    'type' => 'AssertionError',
                ];
            }
        }

        if (empty($failures)) {
            // Check if tests passed
            if (str_contains($output, 'Tests:') && str_contains($output, 'passed')) {
                return [
                    'prompt' => 'All tests passed.',
                    'summary' => ['passed' => true, 'failures' => 0],
                ];
            }

            return [
                'prompt' => "Test output could not be parsed:\n```\n{$output}\n```",
                'summary' => ['valid' => false],
            ];
        }

        return $this->buildPromptFromFailures($failures, []);
    }

    /**
     * Build actionable prompt from failures.
     *
     * @param  array<array<string, mixed>>  $failures
     * @param  array<array<string, mixed>>  $errors
     * @return array{prompt: string, summary: array<string, mixed>}
     */
    private function buildPromptFromFailures(array $failures, array $errors): array
    {
        $total = count($failures) + count($errors);
        $prompt = "## Test Failures ({$total} total)\n\n";
        $prompt .= "Fix these failing tests:\n\n";

        $num = 1;

        foreach ($failures as $failure) {
            $prompt .= $this->formatFailure($num++, $failure, 'FAIL');
        }

        foreach ($errors as $error) {
            $prompt .= $this->formatFailure($num++, $error, 'ERROR');
        }

        return [
            'prompt' => $prompt,
            'summary' => [
                'passed' => false,
                'failures' => count($failures),
                'errors' => count($errors),
                'tests' => array_map(fn ($f) => $f['name'], array_merge($failures, $errors)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $failure
     */
    private function formatFailure(int $num, array $failure, string $type): string
    {
        $name = $failure['name'];
        $file = $failure['file'] ?: 'Unknown file';
        $line = $failure['line'] ?: '?';
        $message = $failure['message'];

        $out = "### {$num}. {$name}\n";
        $out .= "**{$type}** at `{$file}:{$line}`\n\n";

        // Extract and format the assertion message
        $cleanMessage = $this->cleanMessage($message);
        $out .= "```\n{$cleanMessage}\n```\n\n";

        // Add fix direction based on assertion type
        $fix = $this->getFixDirection($message);
        $out .= "**Fix:** {$fix}\n\n";

        return $out;
    }

    private function cleanMessage(string $message): string
    {
        // Remove ANSI color codes
        $clean = preg_replace('/\x1B\[[0-9;]*m/', '', $message) ?? $message;

        // Truncate if too long
        if (strlen($clean) > 500) {
            $clean = substr($clean, 0, 500) . '... (truncated)';
        }

        return trim($clean);
    }

    private function getFixDirection(string $message): string
    {
        $lower = strtolower($message);

        foreach (self::ASSERTION_FIXES as $assertion => $fix) {
            if (str_contains($lower, strtolower($assertion))) {
                return $fix;
            }
        }

        // Infer from message patterns
        if (str_contains($lower, 'failed asserting that') && str_contains($lower, 'matches expected')) {
            return 'Values don\'t match. Compare expected vs actual and trace the logic.';
        }

        if (str_contains($lower, 'exception') || str_contains($lower, 'error')) {
            return 'An exception was thrown. Check the stack trace for the root cause.';
        }

        if (str_contains($lower, 'null')) {
            return 'Unexpected null value. Check for missing data or uninitialized variables.';
        }

        if (str_contains($lower, 'database') || str_contains($lower, 'sql')) {
            return 'Database assertion failed. Check migrations, seeders, and query logic.';
        }

        return 'Review the test expectation vs actual behavior. Check the tested code logic.';
    }

    private function extractFileFromTrace(string $trace): string
    {
        // Match file path from stack trace
        if (preg_match('/([\/\w\-\.]+\.php)/', $trace, $match)) {
            return $match[1];
        }

        return '';
    }

    private function extractLineFromTrace(string $trace): int
    {
        // Match line number from "file.php:123" pattern
        if (preg_match('/:(\d+)/', $trace, $match)) {
            return (int) $match[1];
        }

        return 0;
    }
}
