<?php

declare(strict_types=1);

namespace App\Checks;

final readonly class CheckResult
{
    public function __construct(
        public bool $passed,
        public string $message,
        public array $details = [],
    ) {}

    public static function pass(string $message): self
    {
        return new self(true, $message);
    }

    public static function fail(string $message, array $details = []): self
    {
        return new self(false, $message, $details);
    }
}
