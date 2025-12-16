<?php

declare(strict_types=1);

use App\Branding;

describe('Branding', function () {
    describe('constants', function () {
        it('has tests check name', function () {
            expect(Branding::TESTS)->toBe('① Tests & Coverage');
        });

        it('has security check name', function () {
            expect(Branding::SECURITY)->toBe('② Security Audit');
        });

        it('has syntax check name', function () {
            expect(Branding::SYNTAX)->toBe('③ Pest Syntax');
        });

        it('has certification name', function () {
            expect(Branding::CERTIFICATION)->toBe('🏆 Sentinel Certified');
        });
    });

    describe('checkName', function () {
        it('maps Tests & Coverage to branded name', function () {
            expect(Branding::checkName('Tests & Coverage'))->toBe('① Tests & Coverage');
        });

        it('maps Security Audit to branded name', function () {
            expect(Branding::checkName('Security Audit'))->toBe('② Security Audit');
        });

        it('maps Pest Syntax to branded name', function () {
            expect(Branding::checkName('Pest Syntax'))->toBe('③ Pest Syntax');
        });

        it('returns unknown names unchanged', function () {
            expect(Branding::checkName('Unknown Check'))->toBe('Unknown Check');
        });
    });
});
