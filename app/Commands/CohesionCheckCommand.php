<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class CohesionCheckCommand extends Command
{
    protected $signature = 'check:cohesion
                            {--base=master : Base branch to compare against}
                            {--model=llama3.2:3b : Ollama model to use}';

    protected $description = 'Analyze cross-file relationships and PR cohesion using AI';

    public function handle(): int
    {
        if (! $this->isOllamaAvailable()) {
            $this->warn('Ollama not installed - skipping cohesion check');

            return self::SUCCESS;
        }

        $base = $this->option('base');
        $model = $this->option('model');

        $this->info('🔗 PR Cohesion Analysis');
        $this->line(str_repeat('=', 50));
        $this->newLine();

        // Get all changed files in the PR/branch
        $changedFiles = $this->getChangedFiles($base);

        if (empty($changedFiles)) {
            $this->warn('No changed files to analyze');

            return self::SUCCESS;
        }

        $this->info('Analyzing '.count($changedFiles).' changed files:');
        foreach (array_slice($changedFiles, 0, 10) as $file) {
            $this->line("  • {$file}");
        }
        if (count($changedFiles) > 10) {
            $this->line('  ... and '.(count($changedFiles) - 10).' more');
        }
        $this->newLine();

        // Categorize files
        $categories = $this->categorizeFiles($changedFiles);
        $this->displayCategories($categories);

        // Get PR diff
        $diff = $this->getPRDiff($base);

        // Analyze with AI
        $this->task('Running AI cohesion analysis', function () use ($model, $changedFiles, $categories, $diff) {
            $this->ensureModelAvailable($model);

            return true;
        });

        $analysis = $this->analyzeCohesion($model, $changedFiles, $categories, $diff);

        if (! $analysis) {
            $this->error('Analysis failed');

            return self::FAILURE;
        }

        $result = $this->parseAnalysis($analysis);
        $this->displayAnalysisResult($result);

        return $result['cohesive'] ? self::SUCCESS : self::FAILURE;
    }

    protected function isOllamaAvailable(): bool
    {
        $result = Process::run('which ollama');

        return $result->successful();
    }

    protected function getChangedFiles(string $base): array
    {
        $result = Process::run("git diff --name-only origin/{$base}...HEAD");

        if (! $result->successful()) {
            return [];
        }

        return array_filter(explode("\n", trim($result->output())));
    }

    protected function getPRDiff(string $base): string
    {
        $result = Process::run("git diff origin/{$base}...HEAD");

        return $result->successful() ? $result->output() : '';
    }

    protected function categorizeFiles(array $files): array
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

    protected function displayCategories(array $categories): void
    {
        $this->info('File categories:');
        foreach ($categories as $category => $files) {
            if (! empty($files)) {
                $this->line("  {$category}: ".count($files).' files');
            }
        }
        $this->newLine();
    }

    protected function ensureModelAvailable(string $model): void
    {
        $result = Process::run("ollama list | grep {$model}");

        if (! $result->successful()) {
            Process::run("ollama pull {$model}");
        }
    }

    protected function analyzeCohesion(string $model, array $files, array $categories, string $diff): ?string
    {
        $prompt = $this->buildCohesionPrompt($files, $categories, $diff);

        $result = Process::timeout(60)
            ->run(['ollama', 'run', $model, $prompt]);

        return $result->successful() ? $result->output() : null;
    }

    protected function buildCohesionPrompt(array $files, array $categories, string $diff): string
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

    protected function parseAnalysis(string $analysis): array
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
            'raw' => $analysis,
        ];
    }

    protected function displayAnalysisResult(array $result): void
    {
        $this->newLine();
        $this->info('Analysis Result:');
        $this->line(str_repeat('=', 50));
        $this->newLine();

        if ($result['cohesive']) {
            $this->info('✓ PR is cohesive - all changes are related');
        } else {
            $this->error('✗ PR lacks cohesion - mixing unrelated changes');
        }

        $this->newLine();
        $this->line("Purpose: {$result['purpose']}");
        $this->newLine();

        if ($result['missing_files'] !== 'none') {
            $this->warn('⚠ Missing files detected:');
            $this->line("  {$result['missing_files']}");
            $this->newLine();
        }

        if ($result['dependency_issues'] !== 'none') {
            $this->warn('⚠ Cross-file dependency issues:');
            $this->line("  {$result['dependency_issues']}");
            $this->newLine();
        }

        if ($result['concerns'] !== 'none') {
            $this->warn('⚠ Additional concerns:');
            $this->line("  {$result['concerns']}");
            $this->newLine();
        }

        $this->line(str_repeat('=', 50));

        if (! $result['cohesive']) {
            $this->error('✗ COHESION CHECK FAILED');
            $this->newLine();
            $this->info('Consider:');
            $this->line('  1. Split this PR into focused, single-purpose PRs');
            $this->line('  2. Add missing files (tests, migrations, etc.)');
            $this->line('  3. Ensure all changes support the same feature/fix');
            $this->newLine();
        } else {
            $this->info('✓ COHESION CHECK PASSED');
            $this->newLine();
        }
    }

    public function schedule(Schedule $schedule): void
    {
        // No scheduled tasks
    }
}
