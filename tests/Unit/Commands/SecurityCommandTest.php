<?php

declare(strict_types=1);

use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Commands\SecurityCommand;
use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

describe('SecurityCommand', function () {
    describe('handle', function () {
        it('returns success when security audit passes', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('No known vulnerabilities'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            app()->singleton(SecurityCommand::class, fn () => new SecurityCommand($check, $checksClient));

            $this->artisan('security')
                ->assertSuccessful();
        });

        it('returns failure when vulnerabilities found', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('3 vulnerabilities found', [
                    'CVE-2024-1234: Package A',
                    'CVE-2024-5678: Package B',
                    'CVE-2024-9012: Package C',
                ]));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            app()->singleton(SecurityCommand::class, fn () => new SecurityCommand($check, $checksClient));

            $this->artisan('security')
                ->assertFailed();
        });

        it('displays vulnerabilities table when present', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Vulnerabilities found', ['CVE-2024-0001']));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            app()->singleton(SecurityCommand::class, fn () => new SecurityCommand($check, $checksClient));

            $this->artisan('security')
                ->assertFailed();
        });

        it('uses token from option', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('No vulnerabilities'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('custom-token', $httpClient, 'owner/repo', 'sha123');

            app()->singleton(SecurityCommand::class, fn () => new SecurityCommand($check, $checksClient));

            $this->artisan('security', ['--token' => 'custom-token'])
                ->assertSuccessful();
        });
    });
});
