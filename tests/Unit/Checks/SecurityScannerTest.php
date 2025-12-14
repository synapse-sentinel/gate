<?php

declare(strict_types=1);

use App\Checks\SecurityScanner;

describe('SecurityScanner', function () {
    it('has a descriptive name', function () {
        $scanner = new SecurityScanner();
        expect($scanner->name())->toBe('Security Audit');
    });

    it('implements CheckInterface', function () {
        $scanner = new SecurityScanner();
        expect($scanner)->toBeInstanceOf(\App\Checks\CheckInterface::class);
    });
});
