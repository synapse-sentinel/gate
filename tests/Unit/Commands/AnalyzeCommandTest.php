<?php

declare(strict_types=1);

use App\Commands\AnalyzeCommand;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->failuresFile = sys_get_temp_dir().'/gate_test_failures_'.uniqid().'.json';

    $this->createCommand = function (?Client $httpClient = null, ?string $repo = null, ?string $sha = null) {
        $command = new AnalyzeCommand;
        $command->withMocks($httpClient, $repo ?? 'owner/repo', $sha ?? 'abc123');
        app()->singleton(AnalyzeCommand::class, fn () => $command);
    };
});

afterEach(function () {
    @unlink($this->failuresFile);
    putenv('PREFRONTAL_API_TOKEN');
    putenv('PREFRONTAL_API_URL');
});

describe('AnalyzeCommand', function () {
    describe('validation', function () {
        it('fails when no API token is provided', function () {
            putenv('PREFRONTAL_API_TOKEN');

            $this->artisan('analyze')
                ->expectsOutputToContain('API token required')
                ->assertFailed();
        });

        it('fails when no failures file is provided', function () {
            $this->artisan('analyze', ['--api-token' => 'test-token'])
                ->expectsOutputToContain('Failures file required')
                ->assertFailed();
        });

        it('fails when failures file does not exist', function () {
            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => '/tmp/nonexistent_file_'.uniqid().'.json',
            ])
                ->expectsOutputToContain('Failures file required')
                ->assertFailed();
        });

        it('fails when failures file contains invalid JSON', function () {
            file_put_contents($this->failuresFile, 'not valid json');

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->expectsOutputToContain('Invalid JSON')
                ->assertFailed();
        });

        it('fails when failures file contains empty JSON array', function () {
            file_put_contents($this->failuresFile, '[]');

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->expectsOutputToContain('Invalid JSON')
                ->assertFailed();
        });
    });

    describe('API interaction', function () {
        it('sends failures to API and displays fixes', function () {
            $failures = [['test' => 'TestFoo', 'message' => 'Failed assertion']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $responseData = [
                'fixes' => [
                    ['type' => 'test', 'file' => 'tests/FooTest.php', 'suggestion' => 'Fix the assertion'],
                ],
                'minimal_report' => 'One fix suggested.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($responseData)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->expectsOutputToContain('Sending failures to Prefrontal Cortex')
                ->expectsOutputToContain('Test: tests/FooTest.php')
                ->expectsOutputToContain('Fix the assertion')
                ->expectsOutputToContain('One fix suggested.')
                ->assertSuccessful();
        });

        it('handles response with empty fixes array', function () {
            $failures = [['test' => 'TestFoo', 'message' => 'Failed assertion']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $responseData = [
                'fixes' => [],
                'minimal_report' => 'No fixes needed.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($responseData)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->expectsOutputToContain('No fixes needed.')
                ->assertSuccessful();
        });

        it('handles fix without suggestion', function () {
            $failures = [['test' => 'TestFoo', 'message' => 'Failed']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $responseData = [
                'fixes' => [
                    ['type' => 'style', 'file' => 'src/Foo.php'],
                ],
                'minimal_report' => 'Done.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($responseData)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->expectsOutputToContain('Style: src/Foo.php')
                ->assertSuccessful();
        });

        it('handles fix with missing type and file', function () {
            $failures = [['test' => 'TestFoo', 'message' => 'Failed']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $responseData = [
                'fixes' => [
                    ['suggestion' => 'Try something'],
                ],
                'minimal_report' => 'Done.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($responseData)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->expectsOutputToContain('Unknown: unknown')
                ->assertSuccessful();
        });

        it('handles response without minimal_report', function () {
            $failures = [['test' => 'TestFoo', 'message' => 'Failed']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $responseData = [
                'fixes' => [],
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($responseData)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->assertSuccessful();
        });

        it('fails when API returns invalid response body', function () {
            $failures = [['test' => 'TestFoo', 'message' => 'Failed']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $mock = new MockHandler([
                new Response(200, [], 'not json'),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->expectsOutputToContain('Invalid response from API')
                ->assertFailed();
        });

        it('fails when Guzzle throws an exception', function () {
            $failures = [['test' => 'TestFoo', 'message' => 'Failed']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $mock = new MockHandler([
                new RequestException('Connection timed out', new Request('POST', '/api/gate/analyze')),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->expectsOutputToContain('Request failed: Connection timed out')
                ->assertFailed();
        });
    });

    describe('configuration', function () {
        it('uses API token from environment variable', function () {
            putenv('PREFRONTAL_API_TOKEN=env-token');

            $failures = [['test' => 'TestFoo', 'message' => 'Failed']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $responseData = ['fixes' => [], 'minimal_report' => 'OK'];

            $mock = new MockHandler([
                new Response(200, [], json_encode($responseData)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--failures' => $this->failuresFile,
            ])
                ->assertSuccessful();
        });

        it('uses API URL from option over environment', function () {
            putenv('PREFRONTAL_API_URL=https://env-url.example.com');

            $failures = [['test' => 'TestFoo', 'message' => 'Failed']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $responseData = ['fixes' => [], 'minimal_report' => 'OK'];

            $mock = new MockHandler([
                new Response(200, [], json_encode($responseData)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--api-url' => 'https://custom-url.example.com',
                '--failures' => $this->failuresFile,
            ])
                ->assertSuccessful();
        });
    });

    describe('detectRepo', function () {
        it('uses mock repo when provided via withMocks', function () {
            $failures = [['test' => 'TestFoo', 'message' => 'Failed']];
            file_put_contents($this->failuresFile, json_encode($failures));

            $responseData = ['fixes' => [], 'minimal_report' => 'OK'];

            $mock = new MockHandler([
                new Response(200, [], json_encode($responseData)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient, 'custom/repo', 'custom-sha');

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])
                ->assertSuccessful();
        });
    });
});
