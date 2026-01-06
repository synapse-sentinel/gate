<?php

declare(strict_types=1);

use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Commands\LogicCheckCommand;
use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->createCommand = function (CheckInterface $check, ChecksClient $checksClient) {
        $command = new LogicCheckCommand;
        $command->withMocks($check, $checksClient);
        app()->singleton(LogicCheckCommand::class, fn () => $command);
    };
});

describe('LogicCheckCommand', function () {
    describe('handle', function () {
        it('returns success when commit is atomic', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('Add user authentication feature'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:logic')
                ->assertSuccessful();
        });

        it('returns failure when commit is not atomic', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Commit atomicity validation failed', [
                    'Commit is NOT atomic - mixes multiple concerns',
                    'Changes are NOT all related',
                ]));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:logic')
                ->assertFailed();
        });

        it('displays details when validation fails', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Validation failed', ['Issue 1', 'Issue 2']));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:logic')
                ->assertFailed();
        });

        it('uses token from option', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('Atomic commit'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('custom-token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:logic', ['--token' => 'custom-token'])
                ->assertSuccessful();
        });
    });
});
