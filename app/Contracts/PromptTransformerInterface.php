<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for transforming check output into actionable Claude prompts.
 */
interface PromptTransformerInterface
{
    /**
     * Transform raw check output into an actionable prompt.
     *
     * @param  string  $output  Raw output from the check (JSON, text, etc.)
     * @param  array<string, mixed>  $context  Additional context (file paths, etc.)
     * @return array{prompt: string, summary: array<string, mixed>}
     */
    public function transform(string $output, array $context = []): array;

    /**
     * Check if this transformer can handle the given output.
     */
    public function canHandle(string $checkName): bool;
}
