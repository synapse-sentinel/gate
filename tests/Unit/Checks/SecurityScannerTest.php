<?php

declare(strict_types=1);

use App\Checks\SecurityScanner;
use App\Contracts\ProcessResult;
use App\Contracts\ProcessRunner;

describe('SecurityScanner', function () {
    it('has a descriptive name', function () {
        $scanner = new SecurityScanner();
        expect($scanner->name())->toBe('Security Audit');
    });

    it('implements CheckInterface', function () {
        $scanner = new SecurityScanner();
        expect($scanner)->toBeInstanceOf(\App\Checks\CheckInterface::class);
    });

    it('returns pass when no vulnerabilities found', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: true,
                output: json_encode(['advisories' => []]),
            ));

        $scanner = new SecurityScanner(processRunner: $mockRunner);
        $result = $scanner->run('/tmp');

        expect($result->passed)->toBeTrue();
        expect($result->message)->toBe('No security vulnerabilities found');
    });

    it('returns fail when vulnerabilities found', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: json_encode([
                    'advisories' => [
                        'vendor/package' => [
                            ['title' => 'SQL Injection', 'cve' => 'CVE-2024-1234'],
                        ],
                    ],
                ]),
            ));

        $scanner = new SecurityScanner(processRunner: $mockRunner);
        $result = $scanner->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->message)->toBe('Security vulnerabilities found');
        expect($result->details)->toContain('vendor/package: SQL Injection (CVE-2024-1234)');
    });

    it('handles multiple vulnerabilities in same package', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: json_encode([
                    'advisories' => [
                        'vendor/package' => [
                            ['title' => 'XSS Vulnerability', 'cve' => 'CVE-2024-0001'],
                            ['title' => 'CSRF Vulnerability', 'cve' => 'CVE-2024-0002'],
                        ],
                    ],
                ]),
            ));

        $scanner = new SecurityScanner(processRunner: $mockRunner);
        $result = $scanner->run('/tmp');

        expect($result->details)->toHaveCount(2);
    });

    it('handles vulnerabilities without CVE', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: json_encode([
                    'advisories' => [
                        'vendor/package' => [
                            ['title' => 'Security Issue'],
                        ],
                    ],
                ]),
            ));

        $scanner = new SecurityScanner(processRunner: $mockRunner);
        $result = $scanner->run('/tmp');

        expect($result->details)->toContain('vendor/package: Security Issue (N/A)');
    });

    it('handles invalid JSON output', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn(new ProcessResult(
                successful: false,
                output: 'not valid json',
            ));

        $scanner = new SecurityScanner(processRunner: $mockRunner);
        $result = $scanner->run('/tmp');

        expect($result->passed)->toBeFalse();
        expect($result->details)->toBeEmpty();
    });

    it('passes correct command to process runner', function () {
        $mockRunner = mock(ProcessRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->with(
                ['composer', 'audit', '--format=json'],
                '/some/path',
                Mockery::any()
            )
            ->andReturn(new ProcessResult(
                successful: true,
                output: json_encode(['advisories' => []]),
            ));

        $scanner = new SecurityScanner(processRunner: $mockRunner);
        $scanner->run('/some/path');
    });
});
