<?php

declare(strict_types=1);

use App\Services\CloverParser;

describe('CloverParser', function () {
    it('parses clover.xml with full coverage', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1234567890">
  <project timestamp="1234567890">
    <metrics files="2" loc="100" ncloc="80" classes="2" methods="10" coveredmethods="10"
             statements="50" coveredstatements="50" elements="60" coveredelements="60"/>
    <file name="/path/to/File1.php">
      <metrics loc="50" ncloc="40" classes="1" methods="5" coveredmethods="5"
               statements="25" coveredstatements="25"/>
    </file>
    <file name="/path/to/File2.php">
      <metrics loc="50" ncloc="40" classes="1" methods="5" coveredmethods="5"
               statements="25" coveredstatements="25"/>
    </file>
  </project>
</coverage>
XML;

        $tempFile = sys_get_temp_dir().'/clover_full_'.uniqid().'.xml';
        file_put_contents($tempFile, $xml);

        $parser = new CloverParser($tempFile);
        $result = $parser->parse();

        expect($result['percent'])->toBe(100.0)
            ->and($result['files'])->toHaveCount(2)
            ->and($result['files'][0]['path'])->toBe('/path/to/File1.php')
            ->and($result['files'][0]['statements']['percent'])->toBe(100.0)
            ->and($result['files'][0]['methods']['percent'])->toBe(100.0);

        unlink($tempFile);
    });

    it('parses clover.xml with partial coverage', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1234567890">
  <project timestamp="1234567890">
    <metrics files="1" loc="100" ncloc="80" classes="1" methods="10" coveredmethods="7"
             statements="50" coveredstatements="40" elements="60" coveredelements="47"/>
    <file name="/path/to/PartialFile.php">
      <metrics loc="100" ncloc="80" classes="1" methods="10" coveredmethods="7"
               statements="50" coveredstatements="40"/>
    </file>
  </project>
</coverage>
XML;

        $tempFile = sys_get_temp_dir().'/clover_partial_'.uniqid().'.xml';
        file_put_contents($tempFile, $xml);

        $parser = new CloverParser($tempFile);
        $result = $parser->parse();

        expect($result['percent'])->toBe(78.33)
            ->and($result['files'])->toHaveCount(1)
            ->and($result['files'][0]['statements']['covered'])->toBe(40)
            ->and($result['files'][0]['statements']['total'])->toBe(50)
            ->and($result['files'][0]['statements']['percent'])->toBe(80.0)
            ->and($result['files'][0]['methods']['covered'])->toBe(7)
            ->and($result['files'][0]['methods']['total'])->toBe(10)
            ->and($result['files'][0]['methods']['percent'])->toBe(70.0);

        unlink($tempFile);
    });

    it('handles missing clover.xml file', function () {
        $parser = new CloverParser('/nonexistent/path/clover.xml');
        $result = $parser->parse();

        expect($result['percent'])->toBe(0.0)
            ->and($result['files'])->toBeEmpty();
    });

    it('handles invalid XML', function () {
        $tempFile = sys_get_temp_dir().'/clover_invalid_'.uniqid().'.xml';
        file_put_contents($tempFile, 'not valid xml');

        $parser = new CloverParser($tempFile);
        $result = $parser->parse();

        expect($result['percent'])->toBe(0.0)
            ->and($result['files'])->toBeEmpty();

        unlink($tempFile);
    });

    it('handles empty metrics', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1234567890">
  <project timestamp="1234567890">
    <metrics files="0" loc="0" ncloc="0" classes="0" methods="0" coveredmethods="0"
             statements="0" coveredstatements="0" elements="0" coveredelements="0"/>
  </project>
</coverage>
XML;

        $tempFile = sys_get_temp_dir().'/clover_empty_'.uniqid().'.xml';
        file_put_contents($tempFile, $xml);

        $parser = new CloverParser($tempFile);
        $result = $parser->parse();

        expect($result['percent'])->toBe(0.0)
            ->and($result['files'])->toBeEmpty();

        unlink($tempFile);
    });

    it('handles project without metrics element', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1234567890">
  <project timestamp="1234567890">
  </project>
</coverage>
XML;

        $tempFile = sys_get_temp_dir().'/clover_no_metrics_'.uniqid().'.xml';
        file_put_contents($tempFile, $xml);

        $parser = new CloverParser($tempFile);
        $result = $parser->parse();

        expect($result['percent'])->toBe(0.0);

        unlink($tempFile);
    });

    it('handles files without metrics element', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1234567890">
  <project timestamp="1234567890">
    <metrics files="1" loc="100" ncloc="80" classes="1" methods="5" coveredmethods="5"
             statements="50" coveredstatements="50" elements="60" coveredelements="60"/>
    <file name="/path/to/NoMetrics.php">
    </file>
  </project>
</coverage>
XML;

        $tempFile = sys_get_temp_dir().'/clover_no_file_metrics_'.uniqid().'.xml';
        file_put_contents($tempFile, $xml);

        $parser = new CloverParser($tempFile);
        $result = $parser->parse();

        expect($result['percent'])->toBe(100.0)
            ->and($result['files'])->toBeEmpty();

        unlink($tempFile);
    });

    it('handles files with zero statements and methods', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1234567890">
  <project timestamp="1234567890">
    <metrics files="1" loc="0" ncloc="0" classes="1" methods="0" coveredmethods="0"
             statements="0" coveredstatements="0" elements="0" coveredelements="0"/>
    <file name="/path/to/EmptyFile.php">
      <metrics loc="0" ncloc="0" classes="1" methods="0" coveredmethods="0"
               statements="0" coveredstatements="0"/>
    </file>
  </project>
</coverage>
XML;

        $tempFile = sys_get_temp_dir().'/clover_zero_counts_'.uniqid().'.xml';
        file_put_contents($tempFile, $xml);

        $parser = new CloverParser($tempFile);
        $result = $parser->parse();

        expect($result['files'])->toHaveCount(1)
            ->and($result['files'][0]['statements']['percent'])->toBe(100.0)
            ->and($result['files'][0]['methods']['percent'])->toBe(100.0);

        unlink($tempFile);
    });

    it('calculates correct percentages for multiple files', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1234567890">
  <project timestamp="1234567890">
    <metrics files="3" loc="150" ncloc="120" classes="3" methods="15" coveredmethods="12"
             statements="75" coveredstatements="60" elements="90" coveredelements="72"/>
    <file name="/path/to/FullCoverage.php">
      <metrics loc="50" ncloc="40" classes="1" methods="5" coveredmethods="5"
               statements="25" coveredstatements="25"/>
    </file>
    <file name="/path/to/PartialCoverage.php">
      <metrics loc="50" ncloc="40" classes="1" methods="5" coveredmethods="3"
               statements="25" coveredstatements="20"/>
    </file>
    <file name="/path/to/LowCoverage.php">
      <metrics loc="50" ncloc="40" classes="1" methods="5" coveredmethods="4"
               statements="25" coveredstatements="15"/>
    </file>
  </project>
</coverage>
XML;

        $tempFile = sys_get_temp_dir().'/clover_multi_'.uniqid().'.xml';
        file_put_contents($tempFile, $xml);

        $parser = new CloverParser($tempFile);
        $result = $parser->parse();

        expect($result['percent'])->toBe(80.0)
            ->and($result['files'])->toHaveCount(3)
            ->and($result['files'][0]['statements']['percent'])->toBe(100.0)
            ->and($result['files'][1]['statements']['percent'])->toBe(80.0)
            ->and($result['files'][2]['statements']['percent'])->toBe(60.0);

        unlink($tempFile);
    });
});
