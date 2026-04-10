<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PromptTransformerInterface;
use App\Transformers\PhpStanPromptTransformer;
use App\Transformers\TestFailurePromptTransformer;

/**
 * Assembles actionable prompts from check results.
 *
 * Takes raw check outputs and transforms them into Claude-ready prompts
 * that provide specific fix directions rather than just "FAIL".
 */
final class PromptAssembler
{
    /** @var array<PromptTransformerInterface> */
    private array $transformers;

    public function __construct()
    {
        $this->transformers = [
            new PhpStanPromptTransformer,
            new TestFailurePromptTransformer,
        ];
    }

    /**
     * Assemble a complete prompt from multiple check results.
     *
     * @param  array<string, array{passed: bool, output: string}>  $checkResults
     * @return array{prompt: string, sections: array<string, array<string, mixed>>}
     */
    public function assemble(array $checkResults): array
    {
        $sections = [];
        $failedChecks = [];

        foreach ($checkResults as $checkName => $result) {
            if ($result['passed']) {
                continue;
            }

            $transformed = $this->transform($checkName, $result['output']);
            $sections[$checkName] = $transformed;

            if ($transformed['prompt'] !== '') {
                $failedChecks[$checkName] = $transformed['prompt'];
            }
        }

        if (empty($failedChecks)) {
            return [
                'prompt' => '',
                'sections' => [],
            ];
        }

        $prompt = $this->buildCombinedPrompt($failedChecks);

        return [
            'prompt' => $prompt,
            'sections' => $sections,
        ];
    }

    /**
     * Transform a single check's output.
     *
     * @return array{prompt: string, summary: array<string, mixed>}
     */
    public function transform(string $checkName, string $output): array
    {
        foreach ($this->transformers as $transformer) {
            if ($transformer->canHandle($checkName)) {
                return $transformer->transform($output);
            }
        }

        // Default: return raw output with generic guidance
        return [
            'prompt' => "## {$checkName}\n\n```\n{$this->truncate($output)}\n```\n\nReview the output and fix any issues.\n",
            'summary' => ['raw' => true],
        ];
    }

    /**
     * Build combined prompt for PR comment.
     *
     * @param  array<string, string>  $failedChecks
     */
    private function buildCombinedPrompt(array $failedChecks): string
    {
        $count = count($failedChecks);
        $prompt = "# 🔧 Synapse Sentinel: {$count} check".($count === 1 ? '' : 's')." need attention\n\n";
        $prompt .= "The following issues must be resolved before this PR can be merged:\n\n";

        foreach ($failedChecks as $checkName => $section) {
            $prompt .= "---\n\n";
            $prompt .= $section;
        }

        $prompt .= "---\n\n";
        $prompt .= "**Quick Reference:**\n";
        $prompt .= "- PHPStan errors → Fix type mismatches first, then missing types\n";
        $prompt .= "- Test failures → Read the assertion message, trace expected vs actual\n";
        $prompt .= "- Style issues → Run `composer format` to auto-fix\n";

        return $prompt;
    }

    private function truncate(string $text, int $maxLength = 2000): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength)."\n... (truncated)";
    }
}
