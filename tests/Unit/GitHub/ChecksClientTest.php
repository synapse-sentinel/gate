<?php

declare(strict_types=1);

use App\GitHub\ChecksClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

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

        it('returns null on API error', function () {
            $mock = new MockHandler([
                new RequestException('Error', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->createCheck('Test Check'))->toBeNull();
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

        it('returns false on API error', function () {
            $mock = new MockHandler([
                new RequestException('Error', new Request('PATCH', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new ChecksClient(
                token: 'test-token',
                client: $httpClient,
                repo: 'owner/repo',
                sha: 'abc123',
            );

            expect($client->completeCheck(123, false, 'Failed', 'Bad'))->toBeFalse();
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

        it('returns false and outputs warning on API error', function () {
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
            expect($output)->toContain('::warning::');
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

        it('returns false on API error', function () {
            $mock = new MockHandler([
                new RequestException('Error', new Request('POST', 'test')),
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

            expect($client->postCertificationComment(['Test' => 'Passed']))->toBeFalse();
        });
    });
});
