<?php

declare(strict_types=1);

use App\Commands\AnalyzeCommand;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

describe('AnalyzeCommand', function () {
    beforeEach(function () {
        // Create temp dir for test files
        $this->tempDir = sys_get_temp_dir().'/gate-test-'.uniqid();
        mkdir($this->tempDir);
    });

    afterEach(function () {
        // Clean up temp files
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir.'/*') ?: []);
            rmdir($this->tempDir);
        }
    });

    describe('signature', function () {
        it('has the correct signature', function () {
            $command = new AnalyzeCommand;

            expect($command->getName())->toBe('analyze');
        });

        it('has failures option', function () {
            $command = new AnalyzeCommand;
            $definition = $command->getDefinition();

            expect($definition->hasOption('failures'))->toBeTrue();
        });

        it('has api-url option', function () {
            $command = new AnalyzeCommand;
            $definition = $command->getDefinition();

            expect($definition->hasOption('api-url'))->toBeTrue();
        });

        it('has api-token option', function () {
            $command = new AnalyzeCommand;
            $definition = $command->getDefinition();

            expect($definition->hasOption('api-token'))->toBeTrue();
        });
    });

    describe('handle', function () {
        it('fails without api token', function () {
            // Ensure env is not set
            putenv('PREFRONTAL_API_TOKEN=');

            $this->artisan('analyze')
                ->assertFailed()
                ->expectsOutputToContain('API token required');
        });

        it('fails without failures file', function () {
            $this->artisan('analyze', ['--api-token' => 'test-token'])
                ->assertFailed()
                ->expectsOutputToContain('Failures file required');
        });

        it('fails with non-existent failures file', function () {
            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => '/nonexistent/file.json',
            ])
                ->assertFailed()
                ->expectsOutputToContain('Failures file required');
        });

        it('fails with invalid json in failures file', function () {
            $failuresFile = $this->tempDir.'/failures.json';
            file_put_contents($failuresFile, 'not valid json');

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $failuresFile,
            ])
                ->assertFailed()
                ->expectsOutputToContain('Invalid JSON');
        });

        it('fails with empty json in failures file', function () {
            $failuresFile = $this->tempDir.'/failures.json';
            file_put_contents($failuresFile, '');

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $failuresFile,
            ])
                ->assertFailed()
                ->expectsOutputToContain('Invalid JSON');
        });

        it('uses token from option', function () {
            $failuresFile = $this->tempDir.'/failures.json';
            file_put_contents($failuresFile, json_encode(['test' => 'failure']));

            // This will fail at the HTTP request stage but verifies token path
            $this->artisan('analyze', [
                '--api-token' => 'custom-token',
                '--failures' => $failuresFile,
                '--api-url' => 'http://127.0.0.1:1',  // Non-routable to fail fast
            ])
                ->assertFailed()
                ->expectsOutputToContain('Request failed');
        });

        it('uses token from environment', function () {
            putenv('PREFRONTAL_API_TOKEN=env-token');

            $failuresFile = $this->tempDir.'/failures.json';
            file_put_contents($failuresFile, json_encode(['test' => 'failure']));

            $this->artisan('analyze', [
                '--failures' => $failuresFile,
                '--api-url' => 'http://127.0.0.1:1',
            ])
                ->assertFailed()
                ->expectsOutputToContain('Request failed');

            putenv('PREFRONTAL_API_TOKEN=');
        });

        it('fails when file cannot be read', function () {
            $failuresFile = $this->tempDir.'/failures.json';
            file_put_contents($failuresFile, json_encode(['test' => 'failure']));

            $command = new AnalyzeCommand;
            $command->withFileReader(fn ($path) => false);
            app()->singleton(AnalyzeCommand::class, fn () => $command);

            $this->artisan('analyze', [
                '--api-token' => 'test-token',
                '--failures' => $failuresFile,
            ])
                ->assertFailed()
                ->expectsOutputToContain('Could not read failures file');
        });
    });
});

describe('AnalyzeCommand with mocked HTTP', function () {
    beforeEach(function () {
        $this->tempDir = sys_get_temp_dir().'/gate-test-'.uniqid();
        mkdir($this->tempDir);

        $this->createCommand = function (Client $httpClient) {
            $command = new AnalyzeCommand;
            $command->withMocks($httpClient);
            app()->singleton(AnalyzeCommand::class, fn () => $command);
        };
    });

    afterEach(function () {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir.'/*') ?: []);
            rmdir($this->tempDir);
        }
    });

    it('sends failures to api and displays fixes', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode([
            ['type' => 'test', 'message' => 'Test failed'],
        ]));

        $mockResponse = new Response(200, [], json_encode([
            'fixes' => [
                ['type' => 'test', 'file' => 'Test.php', 'suggestion' => 'Fix the assertion'],
            ],
            'minimal_report' => 'Analysis complete',
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        ($this->createCommand)($client);

        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
        ])
            ->assertSuccessful();
    });

    it('displays fixes with suggestion', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode(['test' => 'data']));

        $mockResponse = new Response(200, [], json_encode([
            'fixes' => [
                ['type' => 'phpstan', 'file' => 'Service.php', 'suggestion' => 'Add return type annotation'],
            ],
            'minimal_report' => 'Done',
        ]));

        $mock = new MockHandler([$mockResponse]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        ($this->createCommand)($client);

        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
        ])
            ->assertSuccessful();
    });

    it('handles fixes without suggestion', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode(['test' => 'data']));

        $mockResponse = new Response(200, [], json_encode([
            'fixes' => [
                ['type' => 'security', 'file' => 'Config.php'],
            ],
            'minimal_report' => 'Fixed',
        ]));

        $mock = new MockHandler([$mockResponse]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        ($this->createCommand)($client);

        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
        ])
            ->assertSuccessful();
    });

    it('handles fixes with missing type and file', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode(['test' => 'data']));

        $mockResponse = new Response(200, [], json_encode([
            'fixes' => [
                ['suggestion' => 'Generic fix'],
            ],
            'minimal_report' => 'OK',
        ]));

        $mock = new MockHandler([$mockResponse]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        ($this->createCommand)($client);

        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
        ])
            ->assertSuccessful();
    });

    it('handles empty fixes array', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode(['test' => 'data']));

        $mockResponse = new Response(200, [], json_encode([
            'fixes' => [],
            'minimal_report' => 'No fixes needed',
        ]));

        $mock = new MockHandler([$mockResponse]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        ($this->createCommand)($client);

        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('No fixes needed');
    });

    it('handles response without fixes key', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode(['test' => 'data']));

        $mockResponse = new Response(200, [], json_encode([
            'minimal_report' => 'Analysis done',
        ]));

        $mock = new MockHandler([$mockResponse]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        ($this->createCommand)($client);

        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Analysis done');
    });

    it('handles response without minimal_report key', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode(['test' => 'data']));

        $mockResponse = new Response(200, [], json_encode([
            'fixes' => [],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        ($this->createCommand)($client);

        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
        ])
            ->assertSuccessful();
    });

    it('fails with invalid api response', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode(['test' => 'data']));

        // Response with invalid JSON returns null on decode
        $mockResponse = new Response(200, [], 'not json');

        $mock = new MockHandler([$mockResponse]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        ($this->createCommand)($client);

        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
        ])
            ->assertFailed()
            ->expectsOutputToContain('Invalid response from API');
    });

    it('handles api error response', function () {
        $failuresFile = $this->tempDir.'/failures.json';
        file_put_contents($failuresFile, json_encode(['test' => 'data']));

        // Command will fail trying to connect to invalid URL
        $this->artisan('analyze', [
            '--api-token' => 'test-token',
            '--failures' => $failuresFile,
            '--api-url' => 'http://127.0.0.1:1',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Request failed');
    });
});

describe('AnalyzeCommand protected methods', function () {
    it('detects repo from github remote', function () {
        $command = new AnalyzeCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectRepo');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'git@github.com:owner/repo.git');

        expect($result)->toBe('owner/repo');
    });

    it('detects repo from github https remote', function () {
        $command = new AnalyzeCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectRepo');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'https://github.com/myorg/myrepo.git');

        expect($result)->toBe('myorg/myrepo');
    });

    it('falls back to cwd basename for non-github remote', function () {
        $command = new AnalyzeCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectRepo');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'git@gitlab.com:owner/repo.git');

        // Should fall back to basename of current directory
        expect($result)->toBe(basename(getcwd()));
    });

    it('falls back to cwd basename for empty remote', function () {
        $command = new AnalyzeCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectRepo');
        $method->setAccessible(true);

        $result = $method->invoke($command, '');

        expect($result)->toBe(basename(getcwd()));
    });

    it('detects sha from git', function () {
        $command = new AnalyzeCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectSha');
        $method->setAccessible(true);

        $result = $method->invoke($command);

        // In a git repo, this returns a sha; outside, empty string
        expect($result)->toBeString();
    });
});
