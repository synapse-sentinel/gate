<?php

declare(strict_types=1);

namespace App\Transformers;

use App\Contracts\PromptTransformerInterface;

/**
 * Transforms PHPStan JSON output into actionable Claude prompts.
 */
final class PhpStanPromptTransformer implements PromptTransformerInterface
{
    /**
     * Error identifiers mapped to fix directions.
     */
    private const FIX_DIRECTIONS = [
        // Argument/Parameter errors
        'argument.type' => 'Cast the input to the expected type, update the type annotation, or fix the caller.',
        'argument.named' => 'Check the parameter name spelling or remove the named argument syntax.',
        'argument.count' => 'Add missing arguments or remove extra arguments from the method call.',

        // Return type errors
        'return.type' => 'Fix the return statement to match the declared type, or update the return type annotation.',
        'return.void' => 'Remove the return value or change the return type from void.',
        'return.missing' => 'Add a return statement or change the return type to void.',

        // Property errors
        'property.notFound' => 'Define the missing property or fix the property name spelling.',
        'property.nonObject' => 'Add a null check before accessing properties.',
        'property.readonly' => 'Remove the assignment to readonly property.',

        // Method errors
        'method.notFound' => 'Define the method, fix spelling, or add @method PHPDoc.',
        'method.nonObject' => 'Add a null check before calling methods.',

        // Class/Type errors
        'class.notFound' => 'Add use statement or fix the namespace/class name.',
        'missingType.iterableValue' => 'Add generic type: array<int, Type> in PHPDoc.',
        'missingType.generics' => 'Add generic parameters: Collection<int, Model>.',

        // Variable errors
        'variable.undefined' => 'Define the variable before use or fix spelling.',
        'variable.certainty' => 'Add a null check or assertion before use.',
    ];

    public function transform(string $output, array $context = []): array
    {
        $data = $this->parseJson($output);

        if ($data === null) {
            return [
                'prompt' => 'PHPStan output could not be parsed.',
                'summary' => ['valid' => false],
            ];
        }

        if (($data['totals']['file_errors'] ?? 0) === 0) {
            return [
                'prompt' => 'PHPStan passed with no errors.',
                'summary' => ['passed' => true, 'errors' => 0],
            ];
        }

        return $this->buildPrompt($data);
    }

    public function canHandle(string $checkName): bool
    {
        return str_contains(strtolower($checkName), 'phpstan')
            || str_contains(strtolower($checkName), 'analyse')
            || str_contains(strtolower($checkName), 'static');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $output): ?array
    {
        // Extract JSON from potentially mixed output
        $start = strpos($output, '{');
        $end = strrpos($output, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($output, $start, $end - $start + 1);

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return isset($data['totals'], $data['files']) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{prompt: string, summary: array<string, mixed>}
     */
    private function buildPrompt(array $data): array
    {
        $files = $data['files'] ?? [];
        $totalErrors = $data['totals']['file_errors'] ?? 0;

        // Sort by error count descending
        uasort($files, fn ($a, $b) => ($b['errors'] ?? 0) <=> ($a['errors'] ?? 0));

        $prompt = "## PHPStan Errors ({$totalErrors} total)\n\n";
        $prompt .= "Fix these errors to pass static analysis:\n\n";

        foreach ($files as $filePath => $fileData) {
            $relativePath = $this->relativePath($filePath);
            $errorCount = $fileData['errors'] ?? 0;

            $prompt .= "### {$relativePath} ({$errorCount} error".($errorCount === 1 ? '' : 's').")\n\n";

            foreach ($fileData['messages'] ?? [] as $index => $message) {
                $prompt .= $this->formatError($index + 1, $message);
            }
        }

        return [
            'prompt' => $prompt,
            'summary' => [
                'passed' => false,
                'errors' => $totalErrors,
                'files' => count($files),
                'types' => $this->collectErrorTypes($files),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function formatError(int $num, array $error): string
    {
        $line = $error['line'] ?? '?';
        $msg = $error['message'] ?? 'Unknown error';
        $identifier = $error['identifier'] ?? '';
        $tip = $error['tip'] ?? null;

        $out = "{$num}. **Line {$line}**";
        if ($identifier !== '') {
            $out .= " `{$identifier}`";
        }
        $out .= "\n   {$msg}\n";

        if ($tip !== null) {
            $out .= "   💡 {$tip}\n";
        }

        $fix = $this->getFixDirection($identifier, $msg);
        $out .= "   **Fix:** {$fix}\n\n";

        return $out;
    }

    private function getFixDirection(string $identifier, string $message): string
    {
        // Exact match
        if (isset(self::FIX_DIRECTIONS[$identifier])) {
            return self::FIX_DIRECTIONS[$identifier];
        }

        // Infer from message
        return $this->inferFromMessage($message);
    }

    private function inferFromMessage(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'expects') && str_contains($lower, 'given')) {
            return 'Type mismatch. Cast the value or update the type annotation.';
        }

        if (str_contains($lower, 'should return') && str_contains($lower, 'but returns')) {
            return 'Return type mismatch. Fix the return statement or annotation.';
        }

        if (str_contains($lower, 'undefined method')) {
            return 'Method does not exist. Define it or fix the spelling.';
        }

        if (str_contains($lower, 'undefined variable')) {
            return 'Variable not defined. Initialize before use.';
        }

        return 'Review the error and ensure types match declarations.';
    }

    private function relativePath(string $path): string
    {
        $cwd = getcwd();
        if ($cwd !== false && str_starts_with($path, $cwd)) {
            return substr($path, strlen($cwd) + 1);
        }

        return $path;
    }

    /**
     * @param  array<string, array<string, mixed>>  $files
     * @return array<string, int>
     */
    private function collectErrorTypes(array $files): array
    {
        $types = [];
        foreach ($files as $fileData) {
            foreach ($fileData['messages'] ?? [] as $message) {
                $id = $message['identifier'] ?? 'unknown';
                $types[$id] = ($types[$id] ?? 0) + 1;
            }
        }
        arsort($types);

        return $types;
    }
}
