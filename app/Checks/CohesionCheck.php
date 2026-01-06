<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;

final class CohesionCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
        private readonly string $model = 'llama3.2:3b',
        private readonly string $base = 'main',
        private readonly int $timeout = 60,
    ) {}

    public function name(): string
    {
        return 'PR Cohesion';
    }

    public function run(string $workingDirectory): CheckResult
    {
        // Check if Ollama is available
        if (! $this->isOllamaAvailable($workingDirectory)) {
            return CheckResult::pass('Ollama not installed - skipping cohesion check');
        }

        // Get changed files compared to base
        $changedFiles = $this->getChangedFiles($workingDirectory);
        if (empty($changedFiles)) {
            return CheckResult::pass('No changed files to analyze');
        }

        // Categorize files
        $categories = $this->categorizeFiles($changedFiles);

        // Get diff
        $diff = $this->getPRDiff($workingDirectory);

        // Ensure model is available
        $this->ensureModelAvailable($workingDirectory);

        // Analyze cohesion
        $analysis = $this->analyzeCohesion($workingDirectory, $changedFiles, $categories, $diff);
        if (! $analysis) {
            return CheckResult::fail('Cohesion analysis failed');
        }

        // Parse result
        $result = $this->parseAnalysis($analysis);

        if ($result['cohesive']) {
            return CheckResult::pass($result['purpose']);
        }

        $issues = [];
        if (! $result['cohesive']) {
            $issues[] = 'PR lacks cohesion - mixing unrelated changes';
        }
        if ($result['missing_files'] !== 'none') {
            $issues[] = 'Missing files: '.$result['missing_files'];
        }
        if ($result['dependency_issues'] !== 'none') {
            $issues[] = 'Cross-file issues: '.$result['dependency_issues'];
        }
        if ($result['concerns'] !== 'none') {
            $issues[] = 'Concerns: '.$result['concerns'];
        }

        return CheckResult::fail(
            'PR cohesion validation failed',
            $issues
        );
    }

    private function isOllamaAvailable(string $workingDirectory): bool
    {
        $result = $this->processRunner->run(['which', 'ollama'], $workingDirectory, timeout: 5);

        return $result->successful;
    }

    private function getChangedFiles(string $workingDirectory): array
    {
        // Try to get base branch from different common names
        $baseBranches = [$this->base, 'master', 'main', 'develop'];
        $changedFiles = [];

        foreach ($baseBranches as $base) {
            $result = $this->processRunner->run(
                ['git', 'diff', '--name-only', "origin/{$base}...HEAD"],
                $workingDirectory,
                timeout: 5
            );

            if ($result->successful && ! empty(trim($result->output))) {
                $changedFiles = array_filter(explode("\n", trim($result->output)));
                break;
            }
        }

        return $changedFiles;
    }

    private function getPRDiff(string $workingDirectory): string
    {
        $baseBranches = [$this->base, 'master', 'main', 'develop'];

        foreach ($baseBranches as $base) {
            $result = $this->processRunner->run(
                ['git', 'diff', "origin/{$base}...HEAD"],
                $workingDirectory,
                timeout: 10
            );

            if ($result->successful && ! empty(trim($result->output))) {
                return $result->output;
            }
        }

        return '';
    }

    private function categorizeFiles(array $files): array
    {
        $categories = [
            'models' => [],
            'controllers' => [],
            'views' => [],
            'tests' => [],
            'migrations' => [],
            'config' => [],
            'routes' => [],
            'services' => [],
            'other' => [],
        ];

        foreach ($files as $file) {
            if (str_contains($file, '/Models/') || str_contains($file, '/model/')) {
                $categories['models'][] = $file;
            } elseif (str_contains($file, '/Controllers/') || str_contains($file, '/controller/')) {
                $categories['controllers'][] = $file;
            } elseif (str_contains($file, '/views/') || str_contains($file, '/View/')) {
                $categories['views'][] = $file;
            } elseif (str_contains($file, '/tests/') || str_contains($file, '/Test/')) {
                $categories['tests'][] = $file;
            } elseif (str_contains($file, '/migrations/')) {
                $categories['migrations'][] = $file;
            } elseif (str_contains($file, '/config/')) {
                $categories['config'][] = $file;
            } elseif (str_contains($file, '/routes/')) {
                $categories['routes'][] = $file;
            } elseif (str_contains($file, '/Services/') || str_contains($file, '/service/')) {
                $categories['services'][] = $file;
            } else {
                $categories['other'][] = $file;
            }
        }

        return array_filter($categories);
    }

    private function ensureModelAvailable(string $workingDirectory): void
    {
        $result = $this->processRunner->run(
            ['sh', '-c', "ollama list | grep {$this->model}"],
            $workingDirectory,
            timeout: 5
        );

        if (! $result->successful) {
            $this->processRunner->run(
                ['ollama', 'pull', $this->model],
                $workingDirectory,
                timeout: 300
            );
        }
    }

    private function analyzeCohesion(string $workingDirectory, array $files, array $categories, string $diff): ?string
    {
        $prompt = $this->buildCohesionPrompt($files, $categories, $diff);

        $result = $this->processRunner->run(
            ['ollama', 'run', $this->model, $prompt],
            $workingDirectory,
            timeout: $this->timeout
        );

        return $result->successful ? $result->output : null;
    }

    private function buildCohesionPrompt(array $files, array $categories, string $diff): string
    {
        $fileList = implode("\n", $files);
        $categoryBreakdown = '';
        foreach ($categories as $category => $categoryFiles) {
            $categoryBreakdown .= "\n{$category}: ".count($categoryFiles);
        }

        // Limit diff to avoid token limits
        $diffLines = explode("\n", $diff);
        $limitedDiff = implode("\n", array_slice($diffLines, 0, 300));

        return <<<PROMPT
You are a senior code reviewer analyzing a Pull Request for cohesion and completeness.

Analyze this PR holistically across all files and answer:

1. **Are all changes cohesive?** (Do they belong together?)
   - YES if all changes serve one feature/fix/refactor
   - NO if mixing unrelated concerns

2. **Are there missing files?**
   - Check for: tests for new features, migrations for model changes, etc.
   - List any files that SHOULD be included but aren't

3. **Do cross-file dependencies make sense?**
   - If model changed, does controller/service reflect it?
   - If API endpoint added, are tests present?
   - Do changes follow MVC/architecture patterns?

4. **What is this PR actually doing?**
   - Describe in 1-2 sentences

Respond in this EXACT format:
COHESIVE: [YES/NO]
MISSING_FILES: [list files that should be included, or "none"]
DEPENDENCY_ISSUES: [list any cross-file problems, or "none"]
PURPOSE: [1-2 sentence description]
CONCERNS: [any other issues, or "none"]

Changed files by category:
{$categoryBreakdown}

All changed files:
{$fileList}

Code changes (sample):
```diff
{$limitedDiff}
```
PROMPT;
    }

    private function parseAnalysis(string $analysis): array
    {
        $cohesive = stripos($analysis, 'COHESIVE: YES') !== false;

        preg_match('/MISSING_FILES:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $analysis, $missingMatch);
        $missing = isset($missingMatch[1]) ? trim($missingMatch[1]) : 'none';

        preg_match('/DEPENDENCY_ISSUES:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $analysis, $depsMatch);
        $dependencies = isset($depsMatch[1]) ? trim($depsMatch[1]) : 'none';

        preg_match('/PURPOSE:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $analysis, $purposeMatch);
        $purpose = isset($purposeMatch[1]) ? trim($purposeMatch[1]) : 'Unknown';

        preg_match('/CONCERNS:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $analysis, $concernsMatch);
        $concerns = isset($concernsMatch[1]) ? trim($concernsMatch[1]) : 'none';

        return [
            'cohesive' => $cohesive,
            'missing_files' => $missing,
            'dependency_issues' => $dependencies,
            'purpose' => $purpose,
            'concerns' => $concerns,
        ];
    }
}
