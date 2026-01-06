<?php

declare(strict_types=1);

namespace App\Checks;

use App\Contracts\ProcessRunner;
use App\Services\SymfonyProcessRunner;

final class LogicCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner,
        private readonly string $model = 'llama3.2:3b',
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return 'Logic & Atomicity';
    }

    public function run(string $workingDirectory): CheckResult
    {
        // Check if Ollama is available
        if (! $this->isOllamaAvailable($workingDirectory)) {
            return CheckResult::pass('Ollama not installed - skipping logic validation');
        }

        if (! $this->isOllamaRunning($workingDirectory)) {
            return CheckResult::pass('Ollama not running - skipping logic validation');
        }

        // Get staged files
        $stagedFiles = $this->getStagedFiles($workingDirectory);
        if (empty($stagedFiles)) {
            return CheckResult::pass('No staged files to validate');
        }

        // Get staged diff
        $diff = $this->getStagedDiff($workingDirectory);
        if (empty($diff)) {
            return CheckResult::pass('No changes to validate');
        }

        // Ensure model is available
        $this->ensureModelAvailable($workingDirectory);

        // Analyze with Ollama
        $analysis = $this->analyzeWithOllama($workingDirectory, $stagedFiles, $diff);
        if (! $analysis) {
            return CheckResult::fail('Analysis failed');
        }

        // Parse result
        $result = $this->parseAnalysis($analysis);

        if ($result['atomic'] && $result['related']) {
            return CheckResult::pass($result['purpose']);
        }

        $issues = [];
        if (! $result['atomic']) {
            $issues[] = 'Commit is NOT atomic - mixes multiple concerns';
        }
        if (! $result['related']) {
            $issues[] = 'Changes are NOT all related';
        }
        if ($result['issues'] !== 'none') {
            $issues[] = $result['issues'];
        }

        return CheckResult::fail(
            'Commit atomicity validation failed',
            $issues
        );
    }

    private function isOllamaAvailable(string $workingDirectory): bool
    {
        $result = $this->processRunner->run(['which', 'ollama'], $workingDirectory, timeout: 5);

        return $result->successful;
    }

    private function isOllamaRunning(string $workingDirectory): bool
    {
        $result = $this->processRunner->run(['ollama', 'list'], $workingDirectory, timeout: 5);

        return $result->successful;
    }

    private function getStagedFiles(string $workingDirectory): array
    {
        $result = $this->processRunner->run(
            ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACM'],
            $workingDirectory,
            timeout: 5
        );

        if (! $result->successful) {
            return [];
        }

        return array_filter(explode("\n", trim($result->output)));
    }

    private function getStagedDiff(string $workingDirectory): string
    {
        $result = $this->processRunner->run(
            ['git', 'diff', '--cached'],
            $workingDirectory,
            timeout: 10
        );

        return $result->successful ? $result->output : '';
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

    private function analyzeWithOllama(string $workingDirectory, array $files, string $diff): ?string
    {
        $prompt = $this->buildAnalysisPrompt($files, $diff);

        $result = $this->processRunner->run(
            ['ollama', 'run', $this->model, $prompt],
            $workingDirectory,
            timeout: $this->timeout
        );

        return $result->successful ? $result->output : null;
    }

    private function buildAnalysisPrompt(array $files, string $diff): string
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

    private function parseAnalysis(string $analysis): array
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
        ];
    }
}
