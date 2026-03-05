<?php

declare(strict_types=1);

use App\Commands\AnalyzeCommand;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->createCommand = function (Client $httpClient, ?string $repo = null, ?string $sha = null) {
        $command = new AnalyzeCommand;
        $command->withMocks($httpClient, $repo ?? 'owner/repo', $sha ?? 'abc123');
        app()->singleton(AnalyzeCommand::class, fn () => $command);
    };

    $this->failuresFile = tempnam(sys_get_temp_dir(), 'gate_test_failures_');
    file_put_contents($this->failuresFile, json_encode([
        ['test' => 'SomeTest::it_works', 'message' => 'Failed assertion'],
    ]));
});

afterEach(function () {
    if (file_exists($this->failuresFile)) {
        @unlink($this->failuresFile);
    }
});

describe('AnalyzeCommand', function () {
    describe('handle', function () {
        it('returns failure when api token is missing', function () {
            putenv('PREFRONTAL_API_TOKEN');

            $mock = new MockHandler([]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--failures' => $this->failuresFile,
            ])->assertFailed();
        });

        it('returns failure when failures file is not provided', function () {
            $mock = new MockHandler([]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
            ])->assertFailed();
        });

        it('returns failure when failures file does not exist', function () {
            $mock = new MockHandler([]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => '/nonexistent/path/failures.json',
            ])->assertFailed();
        });

        it('returns failure when failures file contains invalid json', function () {
            file_put_contents($this->failuresFile, 'not valid json{{{');

            $mock = new MockHandler([]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])->assertFailed();
        });

        it('returns success when api responds with fixes', function () {
            $apiResponse = [
                'fixes' => [
                    ['type' => 'bug', 'file' => 'src/Foo.php', 'suggestion' => 'Fix the null check'],
                    ['type' => 'style', 'file' => 'src/Bar.php'],
                ],
                'minimal_report' => 'Found 2 issues to fix.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($apiResponse)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])->assertSuccessful();
        });

        it('returns success when api responds with empty fixes', function () {
            $apiResponse = [
                'fixes' => [],
                'minimal_report' => 'No actionable fixes found.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($apiResponse)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])->assertSuccessful();
        });

        it('returns success when api responds without fixes key', function () {
            $apiResponse = [
                'minimal_report' => 'Analysis complete.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($apiResponse)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])->assertSuccessful();
        });

        it('returns failure when api responds with null body', function () {
            $mock = new MockHandler([
                new Response(200, [], 'null'),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])->assertFailed();
        });

        it('returns failure when guzzle throws exception', function () {
            $mock = new MockHandler([
                new ConnectException('Connection refused', new Request('POST', '/api/gate/analyze')),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])->assertFailed();
        });

        it('uses api-url option when provided', function () {
            $apiResponse = [
                'fixes' => [],
                'minimal_report' => 'OK',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($apiResponse)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--api-url' => 'https://custom-api.example.com',
                '--failures' => $this->failuresFile,
            ])->assertSuccessful();
        });

        it('uses api token from environment variable', function () {
            putenv('PREFRONTAL_API_TOKEN=env-test-token');

            $apiResponse = [
                'fixes' => [],
                'minimal_report' => 'OK',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($apiResponse)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--failures' => $this->failuresFile,
            ])->assertSuccessful();

            putenv('PREFRONTAL_API_TOKEN');
        });

        it('outputs fix suggestions when present', function () {
            $apiResponse = [
                'fixes' => [
                    ['type' => 'bug', 'file' => 'src/Foo.php', 'suggestion' => 'Add null check on line 42'],
                ],
                'minimal_report' => 'Found 1 issue.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($apiResponse)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])->assertSuccessful();
        });

        it('handles fixes with missing type and file gracefully', function () {
            $apiResponse = [
                'fixes' => [
                    [],
                ],
                'minimal_report' => 'Done.',
            ];

            $mock = new MockHandler([
                new Response(200, [], json_encode($apiResponse)),
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            ($this->createCommand)($httpClient);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $this->failuresFile,
            ])->assertSuccessful();
        });
    });
});
