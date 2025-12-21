<?php

declare(strict_types=1);

namespace App\Services;

use SimpleXMLElement;

class CoverageReporter
{
    public function __construct(
        private readonly int $threshold = 100,
    ) {}

    public function generatePRComment(string $cloverPath, ?string $baseCloverPath = null): string
    {
        $current = $this->parseClover($cloverPath);
        $base = $baseCloverPath ? $this->parseClover($baseCloverPath) : null;

        return $this->formatMarkdown($current, $base);
    }

    public function parseClover(string $path): array
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("Coverage file not found: {$path}");
        }

        $xml = @simplexml_load_file($path);
        if ($xml === false) {
            throw new \RuntimeException("Failed to parse coverage file: {$path}");
        }

        $metrics = $xml->project->metrics ?? null;
        if ($metrics === null) {
            throw new \RuntimeException('Invalid clover format: missing project metrics');
        }

        $files = [];
        foreach ($xml->project->package ?? [] as $package) {
            foreach ($package->file ?? [] as $file) {
                $files[] = $this->parseFile($file);
            }
        }

        return [
            'total' => $this->extractMetrics($metrics),
            'files' => $files,
        ];
    }

    private function parseFile(SimpleXMLElement $file): array
    {
        $metrics = $file->metrics ?? null;
        if ($metrics === null) {
            return [];
        }

        $fileName = (string) $file['name'];
        $fileName = $this->stripWorkspacePath($fileName);

        $fileMetrics = $this->extractMetrics($metrics);

        // Extract uncovered lines
        $uncoveredLines = [];
        foreach ($file->line ?? [] as $line) {
            if ((int) $line['count'] === 0 && (string) $line['type'] === 'stmt') {
                $uncoveredLines[] = (int) $line['num'];
            }
        }

        return [
            'name' => $fileName,
            'metrics' => $fileMetrics,
            'uncovered_lines' => $uncoveredLines,
        ];
    }

    private function stripWorkspacePath(string $fileName): string
    {
        // Try common CI workspace patterns
        $patterns = [
            '/home/runner/work/',  // GitHub Actions
            '/workspace/',         // Generic CI
            getcwd().'/',        // Current working directory
        ];

        foreach ($patterns as $pattern) {
            if (str_starts_with($fileName, $pattern)) {
                $fileName = substr($fileName, strlen($pattern));

                // Handle duplicate repo/project name pattern (e.g., owner/repo/owner/repo/)
                // This is common in GitHub Actions where workspace is /home/runner/work/repo-name/repo-name/
                $parts = explode('/', $fileName, 3);
                if (count($parts) >= 3 && $parts[0] === $parts[1]) {
                    $fileName = $parts[2]; // Skip duplicate repo path
                }

                break;
            }
        }

        // Handle deeper duplicate patterns in monorepos (e.g., packages/service/packages/service/)
        // Look for any pattern where a path segment repeats consecutively
        $fileName = $this->removeDuplicatePathSegments($fileName);

        return $fileName;
    }

    private function removeDuplicatePathSegments(string $path): string
    {
        $parts = explode('/', $path);

        // Try to detect and remove repeating path patterns
        // For example: packages/my-service/packages/my-service/src -> packages/my-service/src
        for ($patternLength = 1; $patternLength <= count($parts) / 2; $patternLength++) {
            $totalParts = count($parts);

            // Check each possible starting position
            for ($start = 0; $start < $totalParts - $patternLength; $start++) {
                $pattern = array_slice($parts, $start, $patternLength);
                $nextSegment = array_slice($parts, $start + $patternLength, $patternLength);

                // If we found a repeating pattern
                if ($pattern === $nextSegment) {
                    // Remove the first occurrence of the pattern
                    $before = array_slice($parts, 0, $start);
                    $after = array_slice($parts, $start + $patternLength);
                    $parts = array_merge($before, $after);

                    // Recursively check for more duplicates
                    return $this->removeDuplicatePathSegments(implode('/', $parts));
                }
            }
        }

        return implode('/', $parts);
    }

    private function extractMetrics(SimpleXMLElement $metrics): array
    {
        $statements = (int) ($metrics['statements'] ?? 0);
        $coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);
        $elements = (int) ($metrics['elements'] ?? 0);
        $coveredElements = (int) ($metrics['coveredelements'] ?? 0);

        return [
            'statements' => $statements,
            'covered_statements' => $coveredStatements,
            'elements' => $elements,
            'covered_elements' => $coveredElements,
            'coverage_percent' => $statements > 0 ? round(($coveredStatements / $statements) * 100, 1) : 0.0,
        ];
    }

    private function formatMarkdown(array $current, ?array $base): string
    {
        $markdown = "## 📊 Coverage Report\n\n";

        // Overall summary
        $currentCoverage = $current['total']['coverage_percent'];
        $status = $currentCoverage >= $this->threshold ? '✅' : '❌';

        $markdown .= "| Metric | Coverage | Threshold | Status |\n";
        $markdown .= "|--------|----------|-----------|--------|\n";
        $markdown .= "| Lines | {$currentCoverage}% | {$this->threshold}% | {$status} |\n\n";

        // Files below threshold
        $filesBelowThreshold = array_filter(
            $current['files'],
            fn ($file) => isset($file['metrics']['coverage_percent']) && $file['metrics']['coverage_percent'] < $this->threshold
        );

        if (count($filesBelowThreshold) > 0) {
            $markdown .= "### Files Below Threshold\n\n";
            $markdown .= "| File | Coverage | Uncovered Lines |\n";
            $markdown .= "|------|----------|-----------------|\n";

            // Sort by coverage ascending (worst first)
            usort($filesBelowThreshold, fn ($a, $b) => $a['metrics']['coverage_percent'] <=> $b['metrics']['coverage_percent']);

            foreach (array_slice($filesBelowThreshold, 0, 10) as $file) {
                $coverage = $file['metrics']['coverage_percent'];
                $fileName = $file['name'];
                $uncoveredCount = count($file['uncovered_lines']);
                $uncoveredPreview = $uncoveredCount > 0 ? implode(', ', array_slice($file['uncovered_lines'], 0, 5)) : 'None';
                if ($uncoveredCount > 5) {
                    $uncoveredPreview .= '... (+'.($uncoveredCount - 5).' more)';
                }
                $markdown .= "| `{$fileName}` | {$coverage}% | {$uncoveredPreview} |\n";
            }

            $markdown .= "\n";
        } else {
            $markdown .= "🎉 All files meet or exceed the {$this->threshold}% threshold!\n\n";
        }

        $markdown .= "---\n";
        $markdown .= "*🏆 Synapse Sentinel Gate*\n";

        return $markdown;
    }
}
