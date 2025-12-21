<?php

declare(strict_types=1);

use App\Services\PestOutputParser;

describe('PestOutputParser', function () {
    describe('parseTestCount', function () {
        it('parses test count from output', function () {
            $parser = new PestOutputParser;
            $output = 'Tests:  42 passed (8 assertions)';
            expect($parser->parseTestCount($output))->toBe(42);
        });

        it('returns 0 when no test count found', function () {
            $parser = new PestOutputParser;
            $output = 'No tests found';
            expect($parser->parseTestCount($output))->toBe(0);
        });

        it('handles single digit test count', function () {
            $parser = new PestOutputParser;
            $output = 'Tests:  3 passed';
            expect($parser->parseTestCount($output))->toBe(3);
        });
    });

    describe('parseCoverage', function () {
        it('parses coverage percentage', function () {
            $parser = new PestOutputParser;
            $output = 'Total:  95.50%';
            expect($parser->parseCoverage($output))->toBe(95.5);
        });

        it('returns null when no coverage found', function () {
            $parser = new PestOutputParser;
            $output = 'Tests:  5 passed';
            expect($parser->parseCoverage($output))->toBeNull();
        });

        it('parses 100% coverage', function () {
            $parser = new PestOutputParser;
            $output = 'Total:  100.00%';
            expect($parser->parseCoverage($output))->toBe(100.0);
        });

        it('parses integer coverage', function () {
            $parser = new PestOutputParser;
            $output = 'Total:  80%';
            expect($parser->parseCoverage($output))->toBe(80.0);
        });
    });

    describe('parseFailures', function () {
        it('extracts failures with location and message', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it does something  0.5s
    Expected true to be false.
    at tests/Unit/FooTest.php:42

OUTPUT;
            $failures = $parser->parseFailures($output);
            expect($failures)->toHaveCount(1);
            expect($failures[0])->toContain('FooTest');
            expect($failures[0])->toContain('tests/Unit/FooTest.php:42');
            expect($failures[0])->toContain('Expected true to be false');
        });

        it('extracts multiple failures', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → first test  0.1s
    First assertion failed.
    at tests/Unit/FooTest.php:10

⨯ Tests\Unit\BarTest → second test  0.2s
    Second assertion failed.
    at tests/Unit/BarTest.php:20

OUTPUT;
            $failures = $parser->parseFailures($output);
            expect($failures)->toHaveCount(2);
            expect($failures[0])->toContain('FooTest');
            expect($failures[1])->toContain('BarTest');
        });

        it('handles failures without location', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it fails  0.5s
    Some error occurred.

OUTPUT;
            $failures = $parser->parseFailures($output);
            expect($failures)->toHaveCount(1);
            expect($failures[0])->toContain('FooTest');
            expect($failures[0])->toContain('Some error occurred');
        });

        it('handles failures without message', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it fails  0.5s
    at tests/Unit/FooTest.php:42

OUTPUT;
            $failures = $parser->parseFailures($output);
            expect($failures)->toHaveCount(1);
            expect($failures[0])->toContain('FooTest');
            expect($failures[0])->toContain('tests/Unit/FooTest.php:42');
        });

        it('strips timing from test name', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
⨯ Tests\Unit\FooTest → it does something  1.23s
    Error message.

OUTPUT;
            $failures = $parser->parseFailures($output);
            expect($failures[0])->not->toContain('1.23s');
        });

        it('returns empty array when no failures', function () {
            $parser = new PestOutputParser;
            $output = 'Tests:  5 passed';
            expect($parser->parseFailures($output))->toBe([]);
        });

        it('handles cross marker', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
✗ Tests\Unit\FooTest → it fails  0.5s
    Error message.

OUTPUT;
            $failures = $parser->parseFailures($output);
            expect($failures)->toHaveCount(1);
        });
    });

    describe('isCoverageBelowThreshold', function () {
        it('detects coverage below threshold', function () {
            $parser = new PestOutputParser;
            $output = 'FAIL  Code coverage below expected  100.0 %, currently  89.50 %.';
            expect($parser->isCoverageBelowThreshold($output))->toBe(89.5);
        });

        it('returns null when coverage meets threshold', function () {
            $parser = new PestOutputParser;
            $output = 'Total:  100.00%';
            expect($parser->isCoverageBelowThreshold($output))->toBeNull();
        });

        it('returns null when no coverage info', function () {
            $parser = new PestOutputParser;
            $output = 'Tests:  5 passed';
            expect($parser->isCoverageBelowThreshold($output))->toBeNull();
        });
    });

    describe('parseFileCoverage', function () {
        it('extracts files below threshold', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 85.0%
  App/Services/BarService .............. 92.5%
  App/Services/BazService .............. 100.0%
  Total ................................ 95.0%
OUTPUT;
            $uncovered = $parser->parseFileCoverage($output, 100.0);

            expect($uncovered)->toHaveCount(2);
            expect($uncovered['App/Services/FooService'])->toBe(85.0);
            expect($uncovered['App/Services/BarService'])->toBe(92.5);
        });

        it('excludes Total line', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 85.0%
  Total ................................ 85.0%
OUTPUT;
            $uncovered = $parser->parseFileCoverage($output, 100.0);

            expect($uncovered)->toHaveCount(1);
            expect($uncovered)->not->toHaveKey('Total');
        });

        it('returns empty array when all files meet threshold', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 100.0%
  App/Services/BarService .............. 100.0%
  Total ................................ 100.0%
OUTPUT;
            $uncovered = $parser->parseFileCoverage($output, 100.0);

            expect($uncovered)->toBeEmpty();
        });

        it('respects custom threshold', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 75.0%
  App/Services/BarService .............. 85.0%
  Total ................................ 80.0%
OUTPUT;
            $uncovered = $parser->parseFileCoverage($output, 80.0);

            expect($uncovered)->toHaveCount(1);
            expect($uncovered['App/Services/FooService'])->toBe(75.0);
        });

        it('returns empty array when no coverage output', function () {
            $parser = new PestOutputParser;
            $output = 'Tests:  5 passed';

            expect($parser->parseFileCoverage($output, 100.0))->toBeEmpty();
        });
    });

    describe('hasRoundingDiscrepancy', function () {
        it('detects rounding discrepancy when total is 100% but files are below threshold', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 99.5%
  App/Services/BarService .............. 99.8%
  Total:  100.0%
OUTPUT;
            expect($parser->hasRoundingDiscrepancy($output, 100.0))->toBeTrue();
        });

        it('returns false when all files meet threshold', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 100.0%
  App/Services/BarService .............. 100.0%
  Total:  100.0%
OUTPUT;
            expect($parser->hasRoundingDiscrepancy($output, 100.0))->toBeFalse();
        });

        it('returns false when threshold is not 100', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 89.5%
  App/Services/BarService .............. 89.8%
  Total:  90.0%
OUTPUT;
            expect($parser->hasRoundingDiscrepancy($output, 90.0))->toBeFalse();
        });

        it('returns false when total coverage is not 100%', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 95.5%
  App/Services/BarService .............. 95.8%
  Total:  95.0%
OUTPUT;
            expect($parser->hasRoundingDiscrepancy($output, 100.0))->toBeFalse();
        });

        it('returns false when no coverage output', function () {
            $parser = new PestOutputParser;
            $output = 'Tests:  5 passed';

            expect($parser->hasRoundingDiscrepancy($output, 100.0))->toBeFalse();
        });

        it('returns false when total coverage is null', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
Tests:  5 passed
  App/Services/FooService .............. 99.5%
OUTPUT;
            expect($parser->hasRoundingDiscrepancy($output, 100.0))->toBeFalse();
        });
    });

    describe('calculateActualCoverage', function () {
        it('calculates average coverage from file breakdown', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 99.5%
  App/Services/BarService .............. 99.8%
  Total:  100.0%
OUTPUT;
            // Average: (99.5 + 99.8) / 2 = 99.65, rounded to 99.65
            expect($parser->calculateActualCoverage($output))->toBe(99.65);
        });

        it('excludes Total line from calculation', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 90.0%
  App/Services/BarService .............. 80.0%
  Total:  85.0%
OUTPUT;
            // Average: (90.0 + 80.0) / 2 = 85.0 (not 255.0 / 3)
            expect($parser->calculateActualCoverage($output))->toBe(85.0);
        });

        it('returns null when no files found', function () {
            $parser = new PestOutputParser;
            $output = 'Tests:  5 passed';

            expect($parser->calculateActualCoverage($output))->toBeNull();
        });

        it('handles single file', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 95.5%
  Total:  95.5%
OUTPUT;
            expect($parser->calculateActualCoverage($output))->toBe(95.5);
        });

        it('rounds to 2 decimal places', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/Service1 .............. 99.1%
  App/Services/Service2 .............. 99.2%
  App/Services/Service3 .............. 99.3%
  Total:  99.2%
OUTPUT;
            // Average: (99.1 + 99.2 + 99.3) / 3 = 99.2
            expect($parser->calculateActualCoverage($output))->toBe(99.2);
        });

        it('excludes total line with different casing', function () {
            $parser = new PestOutputParser;
            $output = <<<'OUTPUT'
  App/Services/FooService .............. 90.0%
  App/Services/BarService .............. 80.0%
  total ................................ 85.0%
OUTPUT;
            // Average: (90.0 + 80.0) / 2 = 85.0 (not including lowercase 'total')
            expect($parser->calculateActualCoverage($output))->toBe(85.0);
        });
    });
});
