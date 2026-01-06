<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class LogicCheckCommand extends Command
{
    protected $signature = 'check:logic
                            {--model=llama3.2:3b : Ollama model to use}
                            {--timeout=30 : Max seconds for analysis}';

    protected $description = 'Validate commit atomicity and logic coherence using Ollama AI';

    public function handle(): int
    {
        if (! $this->isOllamaAvailable()) {
            $this->warn('Ollama not installed - skipping logic validation');
            $this->info('Install: curl -fsSL https://ollama.com/install.sh | sh');

            return self::SUCCESS;
        }

        if (! $this->isOllamaRunning()) {
            $this->warn('Ollama service not running - skipping logic validation');
            $this->info('Start: ollama serve');

            return self::SUCCESS;
        }

        $stagedFiles = $this->getStagedFiles();

        if (empty($stagedFiles)) {
            $this->warn('No files staged for commit');

            return self::SUCCESS;
        }

        $diff = $this->getStagedDiff();

        if (empty($diff)) {
            $this->warn('No changes to validate');

            return self::SUCCESS;
        }

        $fileCount = count($stagedFiles);
        $stats = $this->getDiffStats($diff);

        $this->info('🧠 Ollama Logic Validation');
        $this->line(str_repeat('=', 50));
        $this->info("Analyzing commit:");
        $this->line("  Files: {$fileCount}");
        $this->line("  Lines added: {$stats['added']}");
        $this->line("  Lines removed: {$stats['removed']}");
        $this->newLine();

        $model = $this->option('model');

        // Ensure model is available
        $this->ensureModelAvailable($model);

        $this->info("Analyzing with Ollama ({$model})...");
        $this->newLine();

        $analysis = $this->analyzeWithOllama($model, $stagedFiles, $diff);

        if (! $analysis) {
            $this->error('Analysis failed');

            return self::FAILURE;
        }

        // Parse and display results
        $result = $this->parseAnalysis($analysis);

        $this->displayAnalysisResult($result);

        // Determine if commit should be blocked
        if (! $result['atomic'] || ! $result['related']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function isOllamaAvailable(): bool
    {
        $result = Process::run('which ollama');

        return $result->successful();
    }

    protected function isOllamaRunning(): bool
    {
        $result = Process::run('ollama list');

        return $result->successful();
    }

    protected function getStagedFiles(): array
    {
        $result = Process::run('git diff --cached --name-only --diff-filter=ACM');

        if (! $result->successful()) {
            return [];
        }

        return array_filter(explode("\n", trim($result->output())));
    }

    protected function getStagedDiff(): string
    {
        $result = Process::run('git diff --cached');

        return $result->successful() ? $result->output() : '';
    }

    protected function getDiffStats(string $diff): array
    {
        $added = substr_count($diff, "\n+") - substr_count($diff, "\n+++");
        $removed = substr_count($diff, "\n-") - substr_count($diff, "\n---");

        return ['added' => $added, 'removed' => $removed];
    }

    protected function ensureModelAvailable(string $model): void
    {
        $result = Process::run("ollama list | grep {$model}");

        if (! $result->successful()) {
            $this->task("Pulling {$model} model", function () use ($model) {
                $result = Process::run("ollama pull {$model}");

                return $result->successful();
            });
        }
    }

    protected function analyzeWithOllama(string $model, array $files, string $diff): ?string
    {
        $prompt = $this->buildAnalysisPrompt($files, $diff);

        $result = Process::timeout($this->option('timeout'))
            ->run(['ollama', 'run', $model, $prompt]);

        return $result->successful() ? $result->output() : null;
    }

    protected function buildAnalysisPrompt(array $files, string $diff): string
    {
        $fileList = implode("\n", $files);

        // Limit diff to 500 lines to avoid token limits
        $diffLines = explode("\n", $diff);
        $limitedDiff = implode("\n", array_slice($diffLines, 0, 500));

        return <<<PROMPT
You are a code review expert analyzing a git commit for atomicity and logical coherence.

Analyze this commit and answer these specific questions:

1. **Is this commit atomic?** (Does it change ONE thing only?)
   - YES if it's a single feature, bug fix, or refactor
   - NO if it mixes multiple unrelated changes

2. **Are all changes related?**
   - YES if all files/changes serve the same purpose
   - NO if there are unrelated changes mixed in

3. **Does the logic make sense?**
   - Check for obvious bugs, contradictions, or incomplete implementations
   - Flag any logic that seems broken or incomplete

4. **What is the single purpose?**
   - Describe in one sentence what this commit does

Respond in this EXACT format:
ATOMIC: [YES/NO]
RELATED: [YES/NO]
LOGIC_SOUND: [YES/NO]
PURPOSE: [one sentence description]
ISSUES: [list any problems, or "none"]

Files changed:
{$fileList}

Changes:
```diff
{$limitedDiff}
```
PROMPT;
    }

    protected function parseAnalysis(string $analysis): array
    {
        $atomic = stripos($analysis, 'ATOMIC: YES') !== false;
        $related = stripos($analysis, 'RELATED: YES') !== false;
        $logicSound = stripos($analysis, 'LOGIC_SOUND: YES') !== false;

        // Extract purpose
        preg_match('/PURPOSE:\s*(.+)$/im', $analysis, $purposeMatch);
        $purpose = $purposeMatch[1] ?? 'Unknown';

        // Extract issues
        preg_match('/ISSUES:\s*(.+)$/im', $analysis, $issuesMatch);
        $issues = $issuesMatch[1] ?? 'none';

        return [
            'atomic' => $atomic,
            'related' => $related,
            'logic_sound' => $logicSound,
            'purpose' => trim($purpose),
            'issues' => trim($issues),
            'raw' => $analysis,
        ];
    }

    protected function displayAnalysisResult(array $result): void
    {
        $this->info('Analysis Result:');
        $this->line(str_repeat('=', 50));
        $this->line($result['raw']);
        $this->newLine();

        if ($result['atomic']) {
            $this->info('✓ Commit is atomic');
        } else {
            $this->error('✗ Commit is NOT atomic');
            $this->warn('   Split into multiple commits, each with a single purpose');
        }

        if ($result['related']) {
            $this->info('✓ All changes are related');
        } else {
            $this->error('✗ Changes are NOT all related');
            $this->warn('   Remove unrelated changes or split into separate commits');
        }

        if ($result['logic_sound']) {
            $this->info('✓ Logic appears sound');
        } else {
            $this->warn('⚠ Logic concerns detected');
            if ($result['issues'] !== 'none') {
                $this->line("   Issues: {$result['issues']}");
            }
        }

        $this->newLine();
        $this->line("Commit purpose: {$result['purpose']}");
        $this->newLine();
        $this->line(str_repeat('=', 50));

        if (! $result['atomic'] || ! $result['related']) {
            $this->error('✗ LOGIC CHECK FAILED');
            $this->newLine();
            $this->info('Fix atomicity issues:');
            $this->line('  1. Use \'git reset HEAD <file>\' to unstage unrelated files');
            $this->line('  2. Commit related changes separately');
            $this->line('  3. Keep commits focused on one thing');
            $this->newLine();
        } else {
            $this->info('✓ LOGIC CHECK PASSED');
            $this->newLine();
        }
    }

    public function schedule(Schedule $schedule): void
    {
        // No scheduled tasks
    }
}
