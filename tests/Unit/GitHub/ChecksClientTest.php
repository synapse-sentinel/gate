<?php

declare(strict_types=1);

use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

describe('ChecksClient', function () {
    describe('extractPRNumber', function () {
        it('extracts PR number from GITHUB_REF_NAME', function () {
            putenv('GITHUB_REF_NAME=42/merge');
            putenv('GITHUB_REF=');

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            // The PR number should be extracted and comment should work
            expect($client->postCertificationComment(['Test' => 'Pass']))->toBeTrue();

            putenv('GITHUB_REF_NAME=');
        });

        it('extracts PR number from GITHUB_REF', function () {
            putenv('GITHUB_REF_NAME=');
            putenv('GITHUB_REF=refs/pull/99/merge');

            $mock = new MockHandler([new Response(201)]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->postCertificationComment(['Test' => 'Pass']))->toBeTrue();

            putenv('GITHUB_REF=');
        });
    });

    describe('isAvailable', function () {
        it('returns true when all required values are present', function () {
            $client = new ChecksClient(
                token: 'test-token',
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->isAvailable())->toBeTrue();
        });

        it('returns false when token is null', function () {
            $client = new ChecksClient(
                token: null,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->isAvailable())->toBeFalse();
        });

        it('returns false when token is empty', function () {
            $client = new ChecksClient(
                token: '',
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->isAvailable())->toBeFalse();
        });

        it('returns false when repo is empty', function () {
            $client = new ChecksClient(
                token: 'test-token',
                repo: '',
                sha: 'abc123',
            );

            expect($client->isAvailable())->toBeFalse();
        });

        it('returns false when sha is empty', function () {
            $client = new ChecksClient(
                token: 'test-token',
                repo: 'owner/repo',
                sha: '',
            );

            expect($client->isAvailable())->toBeFalse();
        });
    });

    describe('createCheck', function () {
        it('returns null when not available', function () {
            $client = new ChecksClient(token: null);

            expect($client->createCheck('Test'))->toBeNull();
        });

        it('creates check and returns id on success', function () {
            $mock = new MockHandler([
                new Response(201, [], json_encode(['id' => 12345])),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->createCheck('Test Check'))->toBe(12345);
        });

        it('returns null and outputs error on API error', function () {
            $mock = new MockHandler([
                new RequestException('Network error', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            ob_start();
            $result = $client->createCheck('Test Check');
            $output = ob_get_clean();

            expect($result)->toBeNull();
            expect($output)->toContain('::error::');
            expect($output)->toContain('Network error');
        });

        it('outputs specific error for 403 permission denied', function () {
            $mock = new MockHandler([
                new RequestException(
                    'Forbidden',
                    new Request('POST', 'test'),
                    new Response(403, [], json_encode(['message' => 'Resource not accessible']))
                ),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            ob_start();
            $client->createCheck('Test Check');
            $output = ob_get_clean();

            expect($output)->toContain('::error::');
            expect($output)->toContain('Permission denied');
            expect($output)->toContain('checks:write');
        });

        it('outputs specific error for 429 rate limit', function () {
            $mock = new MockHandler([
                new RequestException(
                    'Too Many Requests',
                    new Request('POST', 'test'),
                    new Response(429, [], json_encode(['message' => 'API rate limit exceeded']))
                ),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            ob_start();
            $client->createCheck('Test Check');
            $output = ob_get_clean();

            expect($output)->toContain('::error::');
            expect($output)->toContain('Rate limit exceeded');
        });
    });

    describe('completeCheck', function () {
        it('returns false when not available', function () {
            $client = new ChecksClient(token: null);

            expect($client->completeCheck(123, true, 'Title', 'Summary'))->toBeFalse();
        });

        it('returns true on success', function () {
            $mock = new MockHandler([
                new Response(200),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->completeCheck(123, true, 'Passed', 'All good'))->toBeTrue();
        });

        it('returns false and outputs error on API error', function () {
            $mock = new MockHandler([
                new RequestException('Connection timeout', new Request('PATCH', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            ob_start();
            $result = $client->completeCheck(123, false, 'Failed', 'Bad');
            $output = ob_get_clean();

            expect($result)->toBeFalse();
            expect($output)->toContain('::error::');
            expect($output)->toContain('Connection timeout');
        });

        it('outputs specific error for 403 permission denied', function () {
            $mock = new MockHandler([
                new RequestException(
                    'Forbidden',
                    new Request('PATCH', 'test'),
                    new Response(403)
                ),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            ob_start();
            $client->completeCheck(123, false, 'Failed', 'Bad');
            $output = ob_get_clean();

            expect($output)->toContain('::error::');
            expect($output)->toContain('Permission denied');
        });

        it('outputs specific error for 429 rate limit', function () {
            $mock = new MockHandler([
                new RequestException(
                    'Too Many Requests',
                    new Request('PATCH', 'test'),
                    new Response(429)
                ),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            ob_start();
            $client->completeCheck(123, true, 'Passed', 'Good');
            $output = ob_get_clean();

            expect($output)->toContain('::error::');
            expect($output)->toContain('Rate limit exceeded');
        });
    });

    describe('reportCheck', function () {
        it('returns false and outputs warning when not available', function () {
            $client = new ChecksClient(
                token: null,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            ob_start();
            $result = $client->reportCheck('Test', true, 'Title', 'Summary');
            $output = ob_get_clean();

            expect($result)->toBeFalse();
            expect($output)->toContain('::warning::');
        });

        it('returns true on success', function () {
            $mock = new MockHandler([
                new Response(201),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->reportCheck('Test', true, 'Passed', 'Good'))->toBeTrue();
        });

        it('returns false and outputs error on API error', function () {
            $mock = new MockHandler([
                new RequestException('Network error', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            ob_start();
            $result = $client->reportCheck('Test', false, 'Failed', 'Bad');
            $output = ob_get_clean();

            expect($result)->toBeFalse();
            expect($output)->toContain('::error::');
            expect($output)->toContain('Network error');
        });
    });

    describe('getRepo', function () {
        it('returns the repository string', function () {
            $client = new ChecksClient(
                token: 'test-token',
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->getRepo())->toBe('owner/repo');
        });
    });

    describe('postCertificationComment', function () {
        it('returns false when not available', function () {
            $client = new ChecksClient(token: null);

            expect($client->postCertificationComment(['Test' => 'Passed']))->toBeFalse();
        });

        it('returns false when no PR number', function () {
            $client = new ChecksClient(
                token: 'test-token',
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: null,
            );

            expect($client->postCertificationComment(['Test' => 'Passed']))->toBeFalse();
        });

        it('posts comment and returns true on success', function () {
            $mock = new MockHandler([
                new Response(201),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: 42,
            );

            expect($client->postCertificationComment([
                'Tests & Coverage' => '10 tests, 100% coverage',
                'Security Audit' => 'No vulnerabilities found',
            ]))->toBeTrue();
        });

        it('returns false and outputs error on API error', function () {
            $mock = new MockHandler([
                new RequestException('API Error', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: 42,
            );

            ob_start();
            $result = $client->postCertificationComment(['Test' => 'Passed']);
            $output = ob_get_clean();

            expect($result)->toBeFalse();
            expect($output)->toContain('::error::');
            expect($output)->toContain('API Error');
        });

        it('outputs specific error for 403 permission denied', function () {
            $mock = new MockHandler([
                new RequestException(
                    'Forbidden',
                    new Request('POST', 'test'),
                    new Response(403)
                ),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: 42,
            );

            ob_start();
            $client->postCertificationComment(['Test' => 'Passed']);
            $output = ob_get_clean();

            expect($output)->toContain('::error::');
            expect($output)->toContain('Permission denied');
        });

        it('outputs specific error for 429 rate limit', function () {
            $mock = new MockHandler([
                new RequestException(
                    'Too Many Requests',
                    new Request('POST', 'test'),
                    new Response(429)
                ),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: 42,
            );

            ob_start();
            $client->postCertificationComment(['Test' => 'Passed']);
            $output = ob_get_clean();

            expect($output)->toContain('::error::');
            expect($output)->toContain('Rate limit exceeded');
        });
    });

    describe('postActionablePrompt', function () {
        it('returns false when not available', function () {
            $client = new ChecksClient(token: null);

            expect($client->postActionablePrompt('Some prompt'))->toBeFalse();
        });

        it('returns false when no PR number', function () {
            $client = new ChecksClient(
                token: 'test-token',
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: null,
            );

            expect($client->postActionablePrompt('Some prompt'))->toBeFalse();
        });

        it('returns true when prompt is empty', function () {
            $client = new ChecksClient(
                token: 'test-token',
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: 42,
            );

            // Empty prompt should return true without making HTTP request
            expect($client->postActionablePrompt(''))->toBeTrue();
        });

        it('posts comment and returns true on success', function () {
            $mock = new MockHandler([
                new Response(201),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: 42,
            );

            expect($client->postActionablePrompt('## Fix Required\n\nPlease fix the type error.'))->toBeTrue();
        });

        it('returns false and outputs error on API error', function () {
            $mock = new MockHandler([
                new RequestException('API Error', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
                prNumber: 42,
            );

            ob_start();
            $result = $client->postActionablePrompt('Fix this error');
            $output = ob_get_clean();

            expect($result)->toBeFalse();
            expect($output)->toContain('::error::');
            expect($output)->toContain('API Error');
        });
    });
});
