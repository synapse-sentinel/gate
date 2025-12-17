<?php

declare(strict_types=1);

namespace App\Services;

use SimpleXMLElement;

final class CloverParser
{
    public function __construct(
        private readonly string $cloverPath,
    ) {}

    public function parse(): array
    {
        if (! file_exists($this->cloverPath)) {
            return [
                'percent' => 0.0,
                'files' => [],
            ];
        }

        $xml = @simplexml_load_file($this->cloverPath);
        if ($xml === false) {
            return [
                'percent' => 0.0,
                'files' => [],
            ];
        }

        return [
            'percent' => $this->calculateTotalCoverage($xml),
            'files' => $this->extractFileCoverage($xml),
        ];
    }

    private function calculateTotalCoverage(SimpleXMLElement $xml): float
    {
        $metrics = $xml->project->metrics;
        if (! $metrics) {
            return 0.0;
        }

        $coveredElements = (int) ($metrics['coveredelements'] ?? 0);
        $totalElements = (int) ($metrics['elements'] ?? 0);

        if ($totalElements === 0) {
            return 0.0;
        }

        return round(($coveredElements / $totalElements) * 100, 2);
    }

    private function extractFileCoverage(SimpleXMLElement $xml): array
    {
        $files = [];

        foreach ($xml->xpath('//file') as $file) {
            $path = (string) $file['name'];
            $metrics = $file->metrics;

            if (! $metrics) {
                continue;
            }

            $coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);
            $totalStatements = (int) ($metrics['statements'] ?? 0);
            $coveredMethods = (int) ($metrics['coveredmethods'] ?? 0);
            $totalMethods = (int) ($metrics['methods'] ?? 0);

            $statementCoverage = $totalStatements > 0
                ? round(($coveredStatements / $totalStatements) * 100, 2)
                : 100.0;

            $methodCoverage = $totalMethods > 0
                ? round(($coveredMethods / $totalMethods) * 100, 2)
                : 100.0;

            $files[] = [
                'path' => $path,
                'statements' => [
                    'covered' => $coveredStatements,
                    'total' => $totalStatements,
                    'percent' => $statementCoverage,
                ],
                'methods' => [
                    'covered' => $coveredMethods,
                    'total' => $totalMethods,
                    'percent' => $methodCoverage,
                ],
            ];
        }

        return $files;
    }
}
