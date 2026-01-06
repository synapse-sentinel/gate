<?php

declare(strict_types=1);

use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Commands\AttributionCheckCommand;
use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->createCommand = function (CheckInterface $check, ChecksClient $checksClient) {
        $command = new AttributionCheckCommand;
        $command->withMocks($check, $checksClient);
        app()->singleton(AttributionCheckCommand::class, fn () => $command);
    };
});

describe('AttributionCheckCommand', function () {
    describe('handle', function () {
        it('returns success when no attribution found', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('No Claude attribution found'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:attribution')
                ->assertSuccessful();
        });

        it('returns failure when attribution detected', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Claude Code attribution detected in commit', [
                    '🤖 Generated wth \[Claude Code\]',
                    'Co-Authored-By: Claude',
                ]));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:attribution')
                ->assertFailed();
        });

        it('displays attribution patterns when found', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Attribution found', ['Pattern 1', 'Pattern 2']));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:attribution')
                ->assertFailed();
        });

        it('uses token from option', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('No attribution'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('custom-token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:attribution', ['--token' => 'custom-token'])
                ->assertSuccessful();
        });
    });
});
