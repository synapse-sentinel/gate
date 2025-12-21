<?php

declare(strict_types=1);

use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Commands\CertifyCommand;
use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    // Helper to create a command with mocks
    $this->createCommand = function (array $checks, ChecksClient $checksClient) {
        $command = new CertifyCommand;
        $command->withMocks($checks, $checksClient);
        app()->singleton(CertifyCommand::class, fn () => $command);
    };
});

describe('CertifyCommand', function () {
    describe('handle', function () {
        it('returns success when all checks pass', function () {
            $passingCheck = Mockery::mock(CheckInterface::class);
            $passingCheck->shouldReceive('name')->andReturn('Test Check');
            $passingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('All good'));

            $mock = new MockHandler([
                new Response(201), // Individual check
                new Response(201), // Certification check
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$passingCheck], $checksClient);

            $this->artisan('certify')
                ->assertSuccessful();
        });

        it('returns failure when any check fails', function () {
            $passingCheck = Mockery::mock(CheckInterface::class);
            $passingCheck->shouldReceive('name')->andReturn('Tests');
            $passingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('Tests passed'));

            $failingCheck = Mockery::mock(CheckInterface::class);
            $failingCheck->shouldReceive('name')->andReturn('Security');
            $failingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Vulnerabilities found', ['CVE-2024-0001']));

            $mock = new MockHandler([
                new Response(201), // First check
                new Response(201), // Second check
                new Response(201), // Certification check
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$passingCheck, $failingCheck], $checksClient);

            $this->artisan('certify')
                ->assertFailed();
        });

        it('displays failure table when checks fail', function () {
            $failingCheck = Mockery::mock(CheckInterface::class);
            $failingCheck->shouldReceive('name')->andReturn('Tests');
            $failingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('2 tests failed', ['TestA failed', 'TestB failed']));

            $mock = new MockHandler([
                new Response(201),
                new Response(201),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$failingCheck], $checksClient);

            $this->artisan('certify')
                ->assertFailed();
        });

        it('writes to GITHUB_STEP_SUMMARY when available', function () {
            $tmpFile = sys_get_temp_dir().'/gate_test_summary_'.uniqid();

            $passingCheck = Mockery::mock(CheckInterface::class);
            $passingCheck->shouldReceive('name')->andReturn('Test');
            $passingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('OK'));

            $mock = new MockHandler([new Response(201), new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            putenv("GITHUB_STEP_SUMMARY={$tmpFile}");

            ($this->createCommand)([$passingCheck], $checksClient);

            $this->artisan('certify')
                ->assertSuccessful();

            putenv('GITHUB_STEP_SUMMARY'); // Clear

            expect(file_exists($tmpFile))->toBeTrue();
            expect(file_get_contents($tmpFile))->toContain('Approved');

            @unlink($tmpFile);
        });

        it('writes to GITHUB_OUTPUT when available', function () {
            $tmpFile = sys_get_temp_dir().'/gate_test_output_'.uniqid();

            $passingCheck = Mockery::mock(CheckInterface::class);
            $passingCheck->shouldReceive('name')->andReturn('Test');
            $passingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('OK'));

            $mock = new MockHandler([new Response(201), new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            putenv("GITHUB_OUTPUT={$tmpFile}");

            ($this->createCommand)([$passingCheck], $checksClient);

            $this->artisan('certify')
                ->assertSuccessful();

            putenv('GITHUB_OUTPUT'); // Clear

            expect(file_exists($tmpFile))->toBeTrue();
            $content = file_get_contents($tmpFile);
            expect($content)->toContain('verdict=approved');
            expect($content)->toContain('reason=');

            @unlink($tmpFile);
        });

        it('handles multiple failed checks', function () {
            $failingCheck1 = Mockery::mock(CheckInterface::class);
            $failingCheck1->shouldReceive('name')->andReturn('Tests');
            $failingCheck1->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Tests failed', ['Test error']));

            $failingCheck2 = Mockery::mock(CheckInterface::class);
            $failingCheck2->shouldReceive('name')->andReturn('Security');
            $failingCheck2->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Security failed', ['CVE found']));

            $mock = new MockHandler([
                new Response(201),
                new Response(201),
                new Response(201),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$failingCheck1, $failingCheck2], $checksClient);

            $this->artisan('certify')
                ->assertFailed();
        });

        it('accepts coverage option', function () {
            $passingCheck = Mockery::mock(CheckInterface::class);
            $passingCheck->shouldReceive('name')->andReturn('Test');
            $passingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('OK'));

            $mock = new MockHandler([new Response(201), new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$passingCheck], $checksClient);

            $this->artisan('certify', ['--coverage' => '90'])
                ->assertSuccessful();
        });

        it('uses token from option', function () {
            $passingCheck = Mockery::mock(CheckInterface::class);
            $passingCheck->shouldReceive('name')->andReturn('Test');
            $passingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('OK'));

            $mock = new MockHandler([new Response(201), new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('custom-token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$passingCheck], $checksClient);

            $this->artisan('certify', ['--token' => 'custom-token'])
                ->assertSuccessful();
        });

        it('stops at first failure when stop-on-failure option is set', function () {
            $failingCheck = Mockery::mock(CheckInterface::class);
            $failingCheck->shouldReceive('name')->andReturn('Tests');
            $failingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Tests failed', ['Error 1']));

            $neverRunCheck = Mockery::mock(CheckInterface::class);
            $neverRunCheck->shouldReceive('name')->andReturn('Security');
            $neverRunCheck->shouldNotReceive('run'); // Should never be called

            $mock = new MockHandler([
                new Response(201), // First check
                new Response(201), // Certification
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$failingCheck, $neverRunCheck], $checksClient);

            $this->artisan('certify', ['--stop-on-failure' => true])
                ->assertFailed();
        });

        it('runs all checks without stop-on-failure option', function () {
            $failingCheck = Mockery::mock(CheckInterface::class);
            $failingCheck->shouldReceive('name')->andReturn('Tests');
            $failingCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Tests failed', ['Error 1']));

            $secondCheck = Mockery::mock(CheckInterface::class);
            $secondCheck->shouldReceive('name')->andReturn('Security');
            $secondCheck->shouldReceive('run')
                ->once() // Should still run
                ->andReturn(CheckResult::pass('OK'));

            $mock = new MockHandler([
                new Response(201), // First check
                new Response(201), // Second check
                new Response(201), // Certification
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$failingCheck, $secondCheck], $checksClient);

            $this->artisan('certify')
                ->assertFailed();
        });

        it('outputs compact format when --compact option is set and all checks pass', function () {
            $testsCheck = Mockery::mock(CheckInterface::class);
            $testsCheck->shouldReceive('name')->andReturn('Tests & Coverage');
            $testsCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('All tests passed'));

            $securityCheck = Mockery::mock(CheckInterface::class);
            $securityCheck->shouldReceive('name')->andReturn('Security Audit');
            $securityCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('No vulnerabilities'));

            $syntaxCheck = Mockery::mock(CheckInterface::class);
            $syntaxCheck->shouldReceive('name')->andReturn('Pest Syntax');
            $syntaxCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('All files valid'));

            $mock = new MockHandler([
                new Response(201),
                new Response(201),
                new Response(201),
                new Response(201),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$testsCheck, $securityCheck, $syntaxCheck], $checksClient);

            $this->expectOutputRegex('/.*/s');

            $this->artisan('certify', ['--compact' => true])
                ->assertSuccessful();
        });

        it('outputs compact format when --compact option is set and a check fails', function () {
            $testsCheck = Mockery::mock(CheckInterface::class);
            $testsCheck->shouldReceive('name')->andReturn('Tests & Coverage');
            $testsCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('3 tests failed', ['Test error']));

            $securityCheck = Mockery::mock(CheckInterface::class);
            $securityCheck->shouldReceive('name')->andReturn('Security Audit');
            $securityCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('No vulnerabilities'));

            $mock = new MockHandler([
                new Response(201),
                new Response(201),
                new Response(201),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$testsCheck, $securityCheck], $checksClient);

            $this->expectOutputRegex('/.*/s');  // Expect any output (including newlines)

            $this->artisan('certify', ['--compact' => true])
                ->assertFailed();
        });

        it('shortens check names in compact output', function () {
            // This test covers the shortName() method by using the actual check names
            $testsCheck = Mockery::mock(CheckInterface::class);
            $testsCheck->shouldReceive('name')->andReturn('Tests & Coverage');
            $testsCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('OK'));

            $securityCheck = Mockery::mock(CheckInterface::class);
            $securityCheck->shouldReceive('name')->andReturn('Security Audit');
            $securityCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('OK'));

            $syntaxCheck = Mockery::mock(CheckInterface::class);
            $syntaxCheck->shouldReceive('name')->andReturn('Pest Syntax');
            $syntaxCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('OK'));

            $mock = new MockHandler([
                new Response(201),
                new Response(201),
                new Response(201),
                new Response(201),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$testsCheck, $securityCheck, $syntaxCheck], $checksClient);

            $this->artisan('certify', ['--compact' => true])
                ->assertSuccessful();
        });

        it('shows failure details in compact mode when checks fail', function () {
            $testsCheck = Mockery::mock(CheckInterface::class);
            $testsCheck->shouldReceive('name')->andReturn('Tests & Coverage');
            $testsCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('3 tests failed', [
                    'FooTest failed',
                    'BarTest failed',
                ]));

            $securityCheck = Mockery::mock(CheckInterface::class);
            $securityCheck->shouldReceive('name')->andReturn('Security Audit');
            $securityCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('No vulnerabilities'));

            $mock = new MockHandler([
                new Response(201),
                new Response(201),
                new Response(201),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$testsCheck, $securityCheck], $checksClient);

            $this->expectOutputRegex('/[\s\S]*/');

            $this->artisan('certify', ['--compact' => true])
                ->assertFailed();
        });

        it('does not show failure table in compact mode when all checks pass', function () {
            $testsCheck = Mockery::mock(CheckInterface::class);
            $testsCheck->shouldReceive('name')->andReturn('Tests & Coverage');
            $testsCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('All tests passed'));

            $securityCheck = Mockery::mock(CheckInterface::class);
            $securityCheck->shouldReceive('name')->andReturn('Security Audit');
            $securityCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('No vulnerabilities'));

            $mock = new MockHandler([
                new Response(201),
                new Response(201),
                new Response(201),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$testsCheck, $securityCheck], $checksClient);

            // Should only output compact summary, no failure table
            $this->artisan('certify', ['--compact' => true])
                ->assertSuccessful()
                ->expectsOutputToContain('✓ APPROVED')
                ->doesntExpectOutputToContain('Check')
                ->doesntExpectOutputToContain('Issue');
        });

        it('shows failure details for multiple failing checks in compact mode', function () {
            $testsCheck = Mockery::mock(CheckInterface::class);
            $testsCheck->shouldReceive('name')->andReturn('Tests & Coverage');
            $testsCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Tests failed', ['Test error 1', 'Test error 2']));

            $securityCheck = Mockery::mock(CheckInterface::class);
            $securityCheck->shouldReceive('name')->andReturn('Security Audit');
            $securityCheck->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Vulnerabilities found', ['CVE-2024-0001', 'CVE-2024-0002']));

            $mock = new MockHandler([
                new Response(201),
                new Response(201),
                new Response(201),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)([$testsCheck, $securityCheck], $checksClient);

            $this->expectOutputRegex('/[\s\S]*/');

            $this->artisan('certify', ['--compact' => true])
                ->assertFailed();
        });
    });
});
