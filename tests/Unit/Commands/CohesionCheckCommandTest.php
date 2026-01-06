<?php

declare(strict_types=1);

use App\Checks\CheckInterface;
use App\Checks\CheckResult;
use App\Commands\CohesionCheckCommand;
use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->createCommand = function (CheckInterface $check, ChecksClient $checksClient) {
        $command = new CohesionCheckCommand;
        $command->withMocks($check, $checksClient);
        app()->singleton(CohesionCheckCommand::class, fn () => $command);
    };
});

describe('CohesionCheckCommand', function () {
    describe('handle', function () {
        it('returns success when PR is cohesive', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('Add user management feature with tests'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:cohesion')
                ->assertSuccessful();
        });

        it('returns failure when PR lacks cohesion', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('PR cohesion validation failed', [
                    'PR lacks cohesion - mixing unrelated changes',
                    'Missing files: tests for User and Payment changes',
                    'Cross-file issues: User model changed but no migration',
                ]));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:cohesion')
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

            $this->artisan('check:cohesion')
                ->assertFailed();
        });

        it('uses token from option', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::pass('Cohesive PR'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('custom-token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:cohesion', ['--token' => 'custom-token'])
                ->assertSuccessful();
        });

        it('handles failure without details', function () {
            $check = Mockery::mock(CheckInterface::class);
            $check->shouldReceive('run')
                ->once()
                ->andReturn(CheckResult::fail('Validation failed'));

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
            $checksClient = new ChecksClient('token', $httpClient, 'owner/repo', 'sha123');

            ($this->createCommand)($check, $checksClient);

            $this->artisan('check:cohesion')
                ->assertFailed();
        });
    });
});
