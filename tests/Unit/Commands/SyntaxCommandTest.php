<?php

declare(strict_types=1);

use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Commands\SyntaxCommand;
use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

describe('SyntaxCommand', function () {
    describe('handle', function () {
        it('returns success when all tests use describe/it', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('All tests use describe/it'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            app()->singleton(SyntaxCommand::class, fn () => new SyntaxCommand($check, $checksClient));

            $this->artisan('syntax')
                ->assertSuccessful();
        });

        it('returns failure when test() functions found', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('5 files using test() instead of describe/it', [
                    'tests/Unit/FooTest.php',
                    'tests/Unit/BarTest.php',
                ]));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            app()->singleton(SyntaxCommand::class, fn () => new SyntaxCommand($check, $checksClient));

            $this->artisan('syntax')
                ->assertFailed();
        });

        it('displays files table when violations present', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Files using test()', ['tests/SomeTest.php']));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            app()->singleton(SyntaxCommand::class, fn () => new SyntaxCommand($check, $checksClient));

            $this->artisan('syntax')
                ->assertFailed();
        });

        it('uses token from option', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('All tests valid'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('custom-token', $httpClient, 'owner/repo', 'sha123');

            app()->singleton(SyntaxCommand::class, fn () => new SyntaxCommand($check, $checksClient));

            $this->artisan('syntax', ['--token' => 'custom-token'])
                ->assertSuccessful();
        });
    });
});
