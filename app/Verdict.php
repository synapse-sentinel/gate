<?php

declare(strict_types=1);

namespace App;

final readonly class Verdict
{
    /**
     * @param  array<int, string>  $failures
     */
    private function __construct(
        private string $status,
        private string $reason,
        private array $failures = []
    ) {}

    public static function approved(string $reason): self
    {
        return new self('approved', $reason);
    }

    public static function rejected(string $reason, array $failures = []): self
    {
        return new self('rejected', $reason, $failures);
    }

    public static function escalate(string $reason): self
    {
        return new self('escalate', $reason);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<int, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isEscalated(): bool
    {
        return $this->status === 'escalate';
    }

    public function exitCode(): int
    {
        return $this->isApproved() ? 0 : 1;
    }

    public function toMarkdown(): string
    {
        $icon = match ($this->status) {
            'approved' => '✅',
            'rejected' => '❌',
            'escalate' => '⚠️',
        };

        $title = match ($this->status) {
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'escalate' => 'Escalate',
        };

        $markdown = "## {$icon} Gate: {$title}\n\n";
        $markdown .= "{$this->reason}\n";

        if ($this->failures !== []) {
            $markdown .= "\n### Failures\n\n";
            foreach ($this->failures as $failure) {
                $markdown .= "- {$failure}\n";
            }
        }

        return $markdown;
    }

    public function toAnnotations(): string
    {
        $annotations = [];

        foreach ($this->failures as $failure) {
            $annotations[] = "::error::{$failure}";
        }

        return implode("\n", $annotations);
    }
}
