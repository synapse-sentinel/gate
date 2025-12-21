<?php

declare(strict_types=1);

use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Commands\TestsCommand;
use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->createCommand = function (CheckInterface $check, ChecksClient $checksClient) {
        $command = new TestsCommand;
        $command->withMocks($check, $checksClient);
        app()->singleton(TestsCommand::class, fn () => $command);
    };
});

describe('TestsCommand', function () {
    describe('handle', function () {
        it('returns success when tests pass', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('42 tests, 100% coverage'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('tests')
                ->assertSuccessful();
        });

        it('returns failure when tests fail', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('2 test(s) failed', ['Test A failed', 'Test B failed']));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('tests')
                ->assertFailed();
        });

        it('displays failure details when present', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Tests failed', ['FailedTest::testSomething']));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('tests')
                ->assertFailed();
        });

        it('uses token from option', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('All tests passed'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('custom-token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('tests', ['--token' => 'custom-token'])
                ->assertSuccessful();
        });

        it('accepts coverage option', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('All tests passed'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('tests', ['--coverage' => '90'])
                ->assertSuccessful();
        });
    });
});
