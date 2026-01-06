<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class AttributionCheckCommand extends Command
{
    protected $signature = 'check:attribution
                            {--fix : Automatically remove attribution and amend commit}';

    protected $description = 'Check for and optionally remove Claude Code attribution from commits';

    protected array $attributionPatterns = [
        '/🤖 Generated with \[Claude Code\]/i',
        '/Generated with Claude Code/i',
        '/Co-Authored-By: Claude/i',
        '/Co-authored-by: Claude/i',
        '/noreply@anthropic\.com/i',
    ];

    public function handle(): int
    {
        $commitMessage = $this->getLastCommitMessage();

        if (empty($commitMessage)) {
            $this->warn('No commit found to check');

            return self::SUCCESS;
        }

        $this->info('🤖 Checking for Claude Code attribution...');
        $this->newLine();

        $hasAttribution = $this->hasAttribution($commitMessage);

        if (! $hasAttribution) {
            $this->info('✓ No Claude attribution found');

            return self::SUCCESS;
        }

        $this->warn('⚠ Claude Code attribution detected');
        $this->newLine();

        if ($this->option('fix')) {
            return $this->removeAttribution($commitMessage);
        }

        $this->line('Found attribution patterns:');
        foreach ($this->attributionPatterns as $pattern) {
            if (preg_match($pattern, $commitMessage)) {
                $this->line("  • ".str_replace(['/', 'i'], '', $pattern));
            }
        }

        $this->newLine();
        $this->info('Run with --fix to automatically remove:');
        $this->line('  gate check:attribution --fix');

        return self::FAILURE;
    }

    protected function getLastCommitMessage(): string
    {
        $result = Process::run('git log -1 --pretty=%B');

        return $result->successful() ? trim($result->output()) : '';
    }

    protected function hasAttribution(string $message): bool
    {
        foreach ($this->attributionPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    protected function removeAttribution(string $message): int
    {
        $this->info('→ Removing Claude Code attribution...');

        // Remove lines matching attribution patterns
        $lines = explode("\n", $message);
        $cleanLines = [];

        foreach ($lines as $line) {
            $shouldKeep = true;

            foreach ($this->attributionPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $shouldKeep = false;
                    break;
                }
            }

            if ($shouldKeep && trim($line) !== '') {
                $cleanLines[] = $line;
            }
        }

        $cleanMessage = implode("\n", $cleanLines);

        // Remove trailing empty lines
        $cleanMessage = rtrim($cleanMessage);

        if ($cleanMessage === trim($message)) {
            $this->warn('No attribution to remove');

            return self::SUCCESS;
        }

        // Create temporary file for commit message
        $tmpFile = sys_get_temp_dir().'/gate_clean_message_'.uniqid();
        file_put_contents($tmpFile, $cleanMessage);

        // Amend commit with clean message
        $result = Process::run(['git', 'commit', '--amend', '--no-verify', '-F', $tmpFile]);

        unlink($tmpFile);

        if (! $result->successful()) {
            $this->error('Failed to amend commit');
            $this->line($result->errorOutput());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Attribution removed and commit amended');
        $this->newLine();

        return self::SUCCESS;
    }

    public function schedule(Schedule $schedule): void
    {
        // No scheduled tasks
    }
}
