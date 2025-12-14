<?php

declare(strict_types=1);

namespace App;

final class Branding
{
    // Check names (sorted by display order)
    public const TESTS = '① Tests & Coverage';
    public const SECURITY = '② Security Audit';
    public const SYNTAX = '③ Pest Syntax';
    public const CERTIFICATION = '🏆 Sentinel Certified';

    // Map internal names to branded names
    public static function checkName(string $internalName): string
    {
        return match ($internalName) {
            'Tests & Coverage' => self::TESTS,
            'Security Audit' => self::SECURITY,
            'Pest Syntax' => self::SYNTAX,
            default => $internalName,
        };
    }
}
