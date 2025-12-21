<?php

declare(strict_types=1);

use App\Services\CoverageReporter;

describe('CoverageReporter', function () {
    describe('parseClover', function () {
        it('throws exception when file not found', function () {
            $reporter = new CoverageReporter;
            $reporter->parseClover('/nonexistent/path.xml');
        })->throws(RuntimeException::class, 'Coverage file not found');

        it('throws exception when file contains invalid XML', function () {
            $tempFile = sys_get_temp_dir().'/invalid_xml_'.uniqid().'.xml';
            file_put_contents($tempFile, 'not valid xml');

            $reporter = new CoverageReporter;
            try {
                $reporter->parseClover($tempFile);
            } finally {
                unlink($tempFile);
            }
        })->throws(RuntimeException::class, 'Failed to parse coverage file');

        it('throws exception when clover format is missing project metrics', function () {
            $xml = '<?xml version="1.0"?><coverage><project></project></coverage>';
            $tempFile = sys_get_temp_dir().'/no_metrics_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter;
            try {
                $reporter->parseClover($tempFile);
            } finally {
                unlink($tempFile);
            }
        })->throws(RuntimeException::class, 'Invalid clover format');

        it('parses valid clover file with packages', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="80" elements="120" coveredelements="96"/>
    <package name="App">
      <file name="/path/to/File.php">
        <metrics statements="50" coveredstatements="40" elements="60" coveredelements="48"/>
        <line num="10" type="stmt" count="1"/>
        <line num="11" type="stmt" count="0"/>
        <line num="12" type="stmt" count="1"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/valid_clover_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter;
            $result = $reporter->parseClover($tempFile);

            expect($result['total']['statements'])->toBe(100)
                ->and($result['total']['covered_statements'])->toBe(80)
                ->and($result['total']['coverage_percent'])->toBe(80.0)
                ->and($result['files'])->toHaveCount(1)
                ->and($result['files'][0]['name'])->toBe('/path/to/File.php')
                ->and($result['files'][0]['uncovered_lines'])->toContain(11);

            unlink($tempFile);
        });

        it('skips files without metrics entirely', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="80" elements="120" coveredelements="96"/>
    <package name="App">
      <file name="/path/to/NoMetrics.php">
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/no_file_metrics_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter;
            $result = $reporter->parseClover($tempFile);

            expect($result['files'])->toBeEmpty();

            unlink($tempFile);
        });

        it('includes only files with metrics when mixed with files without', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="80" elements="120" coveredelements="96"/>
    <package name="App">
      <file name="/path/to/NoMetrics.php">
      </file>
      <file name="/path/to/WithMetrics.php">
        <metrics statements="50" coveredstatements="40" elements="60" coveredelements="48"/>
        <line num="10" type="stmt" count="1"/>
      </file>
      <file name="/path/to/AnotherNoMetrics.php">
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/mixed_metrics_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter;
            $result = $reporter->parseClover($tempFile);

            expect($result['files'])->toHaveCount(1)
                ->and($result['files'][0]['name'])->toBe('/path/to/WithMetrics.php')
                ->and($result['files'][0]['metrics']['statements'])->toBe(50);

            unlink($tempFile);
        });

        it('handles zero statements', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="0" coveredstatements="0" elements="0" coveredelements="0"/>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/zero_stmts_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter;
            $result = $reporter->parseClover($tempFile);

            expect($result['total']['coverage_percent'])->toBe(0.0);

            unlink($tempFile);
        });
    });

    describe('generatePRComment', function () {
        it('generates markdown with threshold status', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/full_coverage_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(100);
            $comment = $reporter->generatePRComment($tempFile);

            expect($comment)->toContain('📊 Coverage Report')
                ->and($comment)->toContain('100%')
                ->and($comment)->toContain('✅')
                ->and($comment)->toContain('🎉 All files meet')
                ->and($comment)->toContain('🏆 Synapse Sentinel Gate');

            unlink($tempFile);
        });

        it('shows files below threshold', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="50" elements="100" coveredelements="50"/>
    <package name="App">
      <file name="/workspace/packages/gate/app/LowCoverage.php">
        <metrics statements="50" coveredstatements="25" elements="50" coveredelements="25"/>
        <line num="1" type="stmt" count="0"/>
        <line num="2" type="stmt" count="0"/>
        <line num="3" type="stmt" count="0"/>
        <line num="4" type="stmt" count="0"/>
        <line num="5" type="stmt" count="0"/>
        <line num="6" type="stmt" count="0"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/low_coverage_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(80);
            $comment = $reporter->generatePRComment($tempFile);

            expect($comment)->toContain('Files Below Threshold')
                ->and($comment)->toContain('❌')
                ->and($comment)->toContain('LowCoverage.php')
                ->and($comment)->toContain('(+1 more)');

            unlink($tempFile);
        });

        it('handles base coverage for comparison', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
  </project>
</coverage>
XML;

            $currentFile = sys_get_temp_dir().'/current_'.uniqid().'.xml';
            $baseFile = sys_get_temp_dir().'/base_'.uniqid().'.xml';
            file_put_contents($currentFile, $xml);
            file_put_contents($baseFile, $xml);

            $reporter = new CoverageReporter(100);
            $comment = $reporter->generatePRComment($currentFile, $baseFile);

            expect($comment)->toContain('📊 Coverage Report');

            unlink($currentFile);
            unlink($baseFile);
        });
    });

    describe('path stripping', function () {
        it('strips GitHub Actions workspace path', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
    <package name="App">
      <file name="/home/runner/work/my-app/my-app/app/Models/User.php">
        <metrics statements="10" coveredstatements="10" elements="10" coveredelements="10"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/github_actions_path_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(100);
            $result = $reporter->parseClover($tempFile);

            expect($result['files'][0]['name'])->toBe('app/Models/User.php');

            unlink($tempFile);
        });

        it('strips generic workspace path', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
    <package name="App">
      <file name="/workspace/my-service/src/Service.php">
        <metrics statements="10" coveredstatements="10" elements="10" coveredelements="10"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/workspace_path_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(100);
            $result = $reporter->parseClover($tempFile);

            expect($result['files'][0]['name'])->toBe('my-service/src/Service.php');

            unlink($tempFile);
        });

        it('strips monorepo duplicate path segments', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
    <package name="App">
      <file name="/workspace/packages/my-service/packages/my-service/src/Service.php">
        <metrics statements="10" coveredstatements="10" elements="10" coveredelements="10"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/monorepo_dup_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(100);
            $result = $reporter->parseClover($tempFile);

            expect($result['files'][0]['name'])->toBe('packages/my-service/src/Service.php');

            unlink($tempFile);
        });

        it('handles relative paths without workspace prefix', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
    <package name="App">
      <file name="app/Services/MyService.php">
        <metrics statements="10" coveredstatements="10" elements="10" coveredelements="10"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/relative_path_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(100);
            $result = $reporter->parseClover($tempFile);

            expect($result['files'][0]['name'])->toBe('app/Services/MyService.php');

            unlink($tempFile);
        });

        it('strips current working directory path when present', function () {
            $currentDir = getcwd();
            $xml = <<<XML
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
    <package name="App">
      <file name="{$currentDir}/app/Test.php">
        <metrics statements="10" coveredstatements="10" elements="10" coveredelements="10"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/cwd_path_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(100);
            $result = $reporter->parseClover($tempFile);

            expect($result['files'][0]['name'])->toBe('app/Test.php');

            unlink($tempFile);
        });

        it('handles complex GitHub Actions duplicate repo name scenario', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
    <package name="App">
      <file name="/home/runner/work/repo-name/repo-name/src/File.php">
        <metrics statements="10" coveredstatements="10" elements="10" coveredelements="10"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/github_dup_repo_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(100);
            $result = $reporter->parseClover($tempFile);

            expect($result['files'][0]['name'])->toBe('src/File.php');

            unlink($tempFile);
        });

        it('preserves paths that do not match any workspace pattern', function () {
            $xml = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <project timestamp="1234567890">
    <metrics statements="100" coveredstatements="100" elements="100" coveredelements="100"/>
    <package name="App">
      <file name="/custom/unusual/path/app/File.php">
        <metrics statements="10" coveredstatements="10" elements="10" coveredelements="10"/>
      </file>
    </package>
  </project>
</coverage>
XML;

            $tempFile = sys_get_temp_dir().'/custom_path_'.uniqid().'.xml';
            file_put_contents($tempFile, $xml);

            $reporter = new CoverageReporter(100);
            $result = $reporter->parseClover($tempFile);

            expect($result['files'][0]['name'])->toBe('/custom/unusual/path/app/File.php');

            unlink($tempFile);
        });
    });
});
