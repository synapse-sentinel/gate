<?php

declare(strict_types=1);

namespace App\Services;

final class PestOutputParser
{
    public function parseTestCount(string $output): int
    {
        return preg_match('/Tests:\s*(\d+)\s*passed/', $output, $m) ? (int) $m[1] : 0;
    }

    public function parseCoverage(string $output): ?float
    {
        return preg_match('/Total:\s*([\d.]+)%/', $output, $m) ? (float) $m[1] : null;
    }

    public function parseFailures(string $output): array
    {
        // Match failure marker, test name, then indented lines (using [ \t]+ for horizontal whitespace only)
        preg_match_all('/[⨯✗]\s+([^\n]+)\n((?:[ \t]+[^\n]+\n)*)/u', $output, $matches, PREG_SET_ORDER);

        return array_map(fn ($m) => $this->formatFailure($m[1], $m[2] ?? ''), $matches);
    }

    public function isCoverageBelowThreshold(string $output): ?float
    {
        // Pest format: "FAIL  Code coverage below expected  X %, currently  Y %."
        return preg_match('/Code coverage below expected.*?currently\s+([\d.]+)\s*%/i', $output, $m) ? (float) $m[1] : null;
    }

    private function formatFailure(string $name, string $body): string
    {
        $name = preg_replace('/\s+[\d.]+s$/', '', trim($name));
        $location = preg_match('/at\s+(\S+:\d+)/', $body, $m) ? " ({$m[1]})" : '';
        $message = $this->extractAssertionMessage($body);

        return $name . $location . $message;
    }

    private function extractAssertionMessage(string $body): string
    {
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, 'at ')) {
                return ": {$line}";
            }
        }

        return '';
    }
}
