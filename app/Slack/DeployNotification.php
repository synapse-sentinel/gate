<?php

declare(strict_types=1);

namespace App\Slack;

/**
 * Beautiful deploy notifications using Slack Block Kit.
 *
 * Addresses common notification design issues:
 * - Color-coded sidebars for instant status recognition
 * - Emojis in body text (not headers) where they render properly
 * - Two-column layouts for compact metadata
 * - Slack-native timestamps for local time display
 * - Action buttons for quick navigation
 * - Rich fallback text for mobile notifications
 */
final class DeployNotification
{
    // Status colors (hex for Slack attachment sidebar)
    public const COLOR_SUCCESS = '#36a64f';

    public const COLOR_FAILURE = '#dc3545';

    public const COLOR_WARNING = '#ffcc00';

    public const COLOR_IN_PROGRESS = '#3498db';

    public const COLOR_PENDING = '#6c757d';

    // Status indicators (rendered properly in section text)
    public const INDICATOR_SUCCESS = ':large_green_circle:';

    public const INDICATOR_FAILURE = ':red_circle:';

    public const INDICATOR_WARNING = ':large_yellow_circle:';

    public const INDICATOR_IN_PROGRESS = ':arrows_counterclockwise:';

    public const INDICATOR_PENDING = ':white_circle:';

    private string $repo;

    private string $branch;

    private string $commitSha;

    private string $commitMessage;

    private string $author;

    private int $commitCount;

    private string $environment;

    private ?string $compareUrl = null;

    private ?string $commitUrl = null;

    private ?string $logsUrl = null;

    private ?string $retryUrl = null;

    private int $timestamp;

    public function __construct()
    {
        $this->timestamp = time();
    }

    public static function create(): self
    {
        return new self;
    }

    public function repo(string $repo): self
    {
        $this->repo = $repo;

        return $this;
    }

    public function branch(string $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    public function commit(string $sha, string $message): self
    {
        $this->commitSha = $sha;
        $this->commitMessage = $message;

        return $this;
    }

    public function author(string $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function commitCount(int $count): self
    {
        $this->commitCount = $count;

        return $this;
    }

    public function environment(string $env): self
    {
        $this->environment = $env;

        return $this;
    }

    public function compareUrl(string $url): self
    {
        $this->compareUrl = $url;

        return $this;
    }

    public function commitUrl(string $url): self
    {
        $this->commitUrl = $url;

        return $this;
    }

    public function logsUrl(string $url): self
    {
        $this->logsUrl = $url;

        return $this;
    }

    public function retryUrl(string $url): self
    {
        $this->retryUrl = $url;

        return $this;
    }

    public function timestamp(int $unixTimestamp): self
    {
        $this->timestamp = $unixTimestamp;

        return $this;
    }

    /**
     * Build a "deploy started" notification.
     */
    public function buildStarted(): SlackMessage
    {
        $shortSha = substr($this->commitSha, 0, 7);

        return SlackMessage::create()
            ->fallback("Deploy started: {$this->repo} → {$this->environment} ({$shortSha} by {$this->author})")
            ->color(self::COLOR_IN_PROGRESS)
            ->header('Deploy Started')
            ->section(self::INDICATOR_IN_PROGRESS." *Deploying to {$this->environment}*")
            ->fields([
                ['label' => 'Repository', 'value' => "<https://github.com/{$this->repo}|{$this->repo}>"],
                ['label' => 'Branch', 'value' => "`{$this->branch}`"],
                ['label' => 'Commit', 'value' => $this->formatCommitLink()],
                ['label' => 'Author', 'value' => $this->author],
            ])
            ->divider()
            ->section("_{$this->truncateMessage($this->commitMessage)}_")
            ->context([
                SlackMessage::timestamp($this->timestamp),
                "{$this->commitCount} ".($this->commitCount === 1 ? 'commit' : 'commits'),
            ])
            ->actions($this->buildActionButtons());
    }

    /**
     * Build a "deploy succeeded" notification.
     */
    public function buildSuccess(): SlackMessage
    {
        $shortSha = substr($this->commitSha, 0, 7);

        return SlackMessage::create()
            ->fallback("Deploy succeeded: {$this->repo} → {$this->environment} ({$shortSha} by {$this->author})")
            ->color(self::COLOR_SUCCESS)
            ->header('Deploy Succeeded')
            ->section(self::INDICATOR_SUCCESS." *Successfully deployed to {$this->environment}*")
            ->fields([
                ['label' => 'Repository', 'value' => "<https://github.com/{$this->repo}|{$this->repo}>"],
                ['label' => 'Branch', 'value' => "`{$this->branch}`"],
                ['label' => 'Commit', 'value' => $this->formatCommitLink()],
                ['label' => 'Author', 'value' => $this->author],
            ])
            ->divider()
            ->section("_{$this->truncateMessage($this->commitMessage)}_")
            ->context([
                SlackMessage::timestamp($this->timestamp),
                "{$this->commitCount} ".($this->commitCount === 1 ? 'commit' : 'commits'),
            ])
            ->actions($this->buildActionButtons());
    }

    /**
     * Build a "deploy failed" notification.
     */
    public function buildFailure(string $errorMessage = '', ?string $failedStage = null): SlackMessage
    {
        $shortSha = substr($this->commitSha, 0, 7);
        $stageInfo = $failedStage ? " at {$failedStage}" : '';

        $message = SlackMessage::create()
            ->fallback("Deploy FAILED{$stageInfo}: {$this->repo} → {$this->environment} ({$shortSha} by {$this->author})")
            ->color(self::COLOR_FAILURE)
            ->header('Deploy Failed')
            ->section(self::INDICATOR_FAILURE." *Failed to deploy to {$this->environment}*{$stageInfo}");

        if ($errorMessage !== '') {
            $message->section("```{$errorMessage}```");
        }

        return $message
            ->fields([
                ['label' => 'Repository', 'value' => "<https://github.com/{$this->repo}|{$this->repo}>"],
                ['label' => 'Branch', 'value' => "`{$this->branch}`"],
                ['label' => 'Commit', 'value' => $this->formatCommitLink()],
                ['label' => 'Author', 'value' => $this->author],
            ])
            ->divider()
            ->context([
                SlackMessage::timestamp($this->timestamp),
                "{$this->commitCount} ".($this->commitCount === 1 ? 'commit' : 'commits'),
            ])
            ->actions($this->buildActionButtons(includeRetry: true));
    }

    /**
     * Build a deploy progress notification with stage tracking.
     *
     * @param  array<int, array{name: string, status: string}>  $stages  Array of stages with 'name' and 'status' keys
     */
    public function buildProgress(array $stages, int $currentStage): SlackMessage
    {
        $shortSha = substr($this->commitSha, 0, 7);
        $totalStages = count($stages);

        $message = SlackMessage::create()
            ->fallback("Deploying {$this->repo}: Stage {$currentStage}/{$totalStages} ({$shortSha})")
            ->color(self::COLOR_IN_PROGRESS)
            ->header('Deploy in Progress');

        // Build stage list with status indicators
        $stageText = '';
        foreach ($stages as $index => $stage) {
            $indicator = match ($stage['status']) {
                'completed' => self::INDICATOR_SUCCESS,
                'in_progress' => self::INDICATOR_IN_PROGRESS,
                'failed' => self::INDICATOR_FAILURE,
                default => self::INDICATOR_PENDING,
            };
            $stageText .= "{$indicator} {$stage['name']}\n";
        }

        return $message
            ->section("*Deploying to {$this->environment}*\n\n{$stageText}")
            ->fields([
                ['label' => 'Repository', 'value' => "<https://github.com/{$this->repo}|{$this->repo}>"],
                ['label' => 'Progress', 'value' => "{$currentStage} of {$totalStages} stages"],
            ])
            ->context([
                SlackMessage::timestamp($this->timestamp),
                "Commit: `{$shortSha}`",
            ]);
    }

    /**
     * Build a certification result notification (for Gate).
     *
     * @param  array<int, array{name: string, passed: bool, message?: string}>  $checks
     */
    public function buildCertification(bool $passed, array $checks): SlackMessage
    {
        $shortSha = substr($this->commitSha, 0, 7);
        $status = $passed ? 'CERTIFIED' : 'REJECTED';
        $color = $passed ? self::COLOR_SUCCESS : self::COLOR_FAILURE;
        $indicator = $passed ? self::INDICATOR_SUCCESS : self::INDICATOR_FAILURE;
        $emoji = $passed ? ':trophy:' : ':x:';

        $message = SlackMessage::create()
            ->fallback("Gate {$status}: {$this->repo} ({$shortSha})")
            ->color($color)
            ->header("Gate {$status}");

        // Build check results
        $checkText = '';
        foreach ($checks as $check) {
            $checkIndicator = $check['passed'] ? self::INDICATOR_SUCCESS : self::INDICATOR_FAILURE;
            $checkText .= "{$checkIndicator} *{$check['name']}*";
            if (isset($check['message']) && $check['message'] !== '') {
                $checkText .= " - {$check['message']}";
            }
            $checkText .= "\n";
        }

        return $message
            ->section("{$emoji} {$indicator} *{$this->repo}* quality gate {$status}")
            ->section($checkText)
            ->fields([
                ['label' => 'Branch', 'value' => "`{$this->branch}`"],
                ['label' => 'Commit', 'value' => $this->formatCommitLink()],
            ])
            ->context([
                SlackMessage::timestamp($this->timestamp),
                "Triggered by {$this->author}",
            ])
            ->actions($this->buildActionButtons());
    }

    private function formatCommitLink(): string
    {
        $shortSha = substr($this->commitSha, 0, 7);

        if ($this->commitUrl !== null) {
            return "<{$this->commitUrl}|`{$shortSha}`>";
        }

        return "`{$shortSha}`";
    }

    private function truncateMessage(string $message, int $maxLength = 100): string
    {
        // Take first line only
        $firstLine = explode("\n", $message)[0];

        if (strlen($firstLine) <= $maxLength) {
            return $firstLine;
        }

        return substr($firstLine, 0, $maxLength - 3).'...';
    }

    /**
     * @return array<int, array{text: string, url?: string, style?: string}>
     */
    private function buildActionButtons(bool $includeRetry = false): array
    {
        $buttons = [];

        if ($this->commitUrl !== null) {
            $buttons[] = [
                'text' => 'View Commit',
                'url' => $this->commitUrl,
            ];
        }

        if ($this->compareUrl !== null) {
            $buttons[] = [
                'text' => 'View Diff',
                'url' => $this->compareUrl,
            ];
        }

        if ($this->logsUrl !== null) {
            $buttons[] = [
                'text' => 'View Logs',
                'url' => $this->logsUrl,
            ];
        }

        if ($includeRetry && $this->retryUrl !== null) {
            $buttons[] = [
                'text' => 'Retry Deploy',
                'url' => $this->retryUrl,
                'style' => 'primary',
            ];
        }

        return $buttons;
    }
}
