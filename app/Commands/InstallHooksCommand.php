<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class InstallHooksCommand extends Command
{
    protected $signature = 'install
                            {--force : Overwrite existing hooks}';

    protected $description = 'Install Gate git hooks in the current repository';

    public function handle(): int
    {
        if (! $this->isGitRepository()) {
            $this->error('Not in a git repository');
            $this->info('Run this command from the root of your git project');

            return self::FAILURE;
        }

        $repoRoot = $this->getGitRoot();

        $this->info('🚪 Gate - Installing Git Hooks');
        $this->newLine();
        $this->info("Repository: {$repoRoot}");
        $this->newLine();

        // Create .gate directory
        $this->task('Creating .gate directory', function () use ($repoRoot) {
            $gateDir = "{$repoRoot}/.gate";
            if (! is_dir($gateDir)) {
                mkdir($gateDir, 0755, true);
            }

            return true;
        });

        // Create config file
        $configFile = "{$repoRoot}/.gate/config.php";
        if (! file_exists($configFile) || $this->option('force')) {
            $this->task('Creating config file', function () use ($configFile) {
                file_put_contents($configFile, $this->getConfigTemplate());

                return true;
            });
        } else {
            $this->warn('Config already exists, skipping (use --force to overwrite)');
        }

        // Install pre-commit hook
        $preCommitHook = "{$repoRoot}/.git/hooks/pre-commit";
        if (file_exists($preCommitHook) && ! $this->option('force')) {
            $this->warn('Pre-commit hook already exists');
            if ($this->confirm('Backup and replace?', true)) {
                copy($preCommitHook, "{$preCommitHook}.backup");
                $this->installPreCommitHook($preCommitHook);
            }
        } else {
            $this->task('Installing pre-commit hook', function () use ($preCommitHook) {
                $this->installPreCommitHook($preCommitHook);

                return true;
            });
        }

        // Install post-commit hook
        $postCommitHook = "{$repoRoot}/.git/hooks/post-commit";
        if (file_exists($postCommitHook) && ! $this->option('force')) {
            $this->warn('Post-commit hook already exists');
            if ($this->confirm('Backup and replace?', true)) {
                copy($postCommitHook, "{$postCommitHook}.backup");
                $this->installPostCommitHook($postCommitHook);
            }
        } else {
            $this->task('Installing post-commit hook', function () use ($postCommitHook) {
                $this->installPostCommitHook($postCommitHook);

                return true;
            });
        }

        $this->newLine();
        $this->info('✓ Gate installed successfully!');
        $this->newLine();

        $this->info('Test it now:');
        $this->line('  1. Make a change: echo "test" >> README.md');
        $this->line('  2. Stage it:      git add README.md');
        $this->line('  3. Commit it:     git commit -m "test: validate gate"');
        $this->line('  4. Watch:         Gate runs automatically!');

        $this->newLine();
        $this->info('Configuration:');
        $this->line("  Edit {$repoRoot}/.gate/config.php to customize checks");

        return self::SUCCESS;
    }

    protected function isGitRepository(): bool
    {
        return is_dir(getcwd().'/.git');
    }

    protected function getGitRoot(): string
    {
        return trim(shell_exec('git rev-parse --show-toplevel'));
    }

    protected function installPreCommitHook(string $path): void
    {
        $gateBinary = $this->getGateBinaryPath();

        $content = <<<BASH
#!/bin/bash
# Gate Pre-Commit Hook
# Auto-installed by synapse-sentinel/gate

{$gateBinary} certify

BASH;

        file_put_contents($path, $content);
        chmod($path, 0755);
    }

    protected function installPostCommitHook(string $path): void
    {
        $gateBinary = $this->getGateBinaryPath();

        $content = <<<BASH
#!/bin/bash
# Gate Post-Commit Hook
# Auto-installed by synapse-sentinel/gate

{$gateBinary} check:attribution --fix

BASH;

        file_put_contents($path, $content);
        chmod($path, 0755);
    }

    protected function getGateBinaryPath(): string
    {
        // Try to find gate in PATH
        $which = trim(shell_exec('which gate'));
        if ($which && file_exists($which)) {
            return $which;
        }

        // Fall back to vendor/bin/gate if installed locally
        if (file_exists(getcwd().'/vendor/bin/gate')) {
            return './vendor/bin/gate';
        }

        // Default to gate in PATH
        return 'gate';
    }

    protected function getConfigTemplate(): string
    {
        return <<<'PHP'
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Layer 1: Pre-Commit Checks
    |--------------------------------------------------------------------------
    |
    | These checks run before the commit is created (< 10s total)
    |
    */
    'pre_commit' => [
        'syntax' => true,      // Validate syntax (PHP, JS)
        'secrets' => true,     // Detect hardcoded secrets
        'static' => true,      // Static analysis (PHPStan)
        'lint' => false,       // Code style linting (slower)
        'tests' => false,      // Quick unit tests
        'logic' => true,       // Ollama atomicity validation
    ],

    /*
    |--------------------------------------------------------------------------
    | Layer 2: Post-Commit Processing
    |--------------------------------------------------------------------------
    |
    | These actions run after commit succeeds
    |
    */
    'post_commit' => [
        'attribution' => true,     // Remove Claude Code attribution
        'format' => true,          // Auto-format code
        'fix_message' => true,     // Fix commit message format
    ],

    /*
    |--------------------------------------------------------------------------
    | Layer 3: CI/CD Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for GitHub Actions / CI pipelines
    |
    */
    'ci' => [
        'coverage_min' => 80,      // Minimum coverage percentage
        'ai_review' => false,      // Enable Ollama AI review
    ],

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for local Ollama AI validation
    |
    */
    'ollama' => [
        'model' => 'llama3.2:3b',  // Model for logic validation
        'timeout' => 30,            // Max seconds for analysis
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity Routing
    |--------------------------------------------------------------------------
    |
    | How to handle different severity levels
    |
    */
    'severity' => [
        'block_on_critical' => true,   // Stop on critical issues
        'block_on_high' => true,       // Stop on high severity
        'warn_on_medium' => true,      // Warn on medium (continue)
        'audit_on_low' => true,        // Log low severity issues
    ],
];

PHP;
    }

    public function schedule(Schedule $schedule): void
    {
        // No scheduled tasks for this command
    }
}
