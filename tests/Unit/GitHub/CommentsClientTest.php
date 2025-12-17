<?php

declare(strict_types=1);

use App\GitHub\CommentsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

describe('CommentsClient', function () {
    beforeEach(function () {
        // Set up environment
        putenv('GITHUB_REPOSITORY=owner/repo');
        putenv('GITHUB_EVENT_NAME=pull_request');
    });

    afterEach(function () {
        putenv('GITHUB_REPOSITORY');
        putenv('GITHUB_EVENT_NAME');
        putenv('GITHUB_EVENT_PATH');
    });

    describe('isAvailable', function () {
        it('returns false when token is null', function () {
            $client = new CommentsClient(null);
            expect($client->isAvailable())->toBeFalse();
        });

        it('returns false when token is empty', function () {
            $client = new CommentsClient('');
            expect($client->isAvailable())->toBeFalse();
        });

        it('returns false when repo is empty', function () {
            $client = new CommentsClient('token', null, '');
            expect($client->isAvailable())->toBeFalse();
        });

        it('returns false when prNumber is null', function () {
            putenv('GITHUB_EVENT_NAME=push');
            $client = new CommentsClient('token', null, 'owner/repo');
            expect($client->isAvailable())->toBeFalse();
        });

        it('returns true when all conditions are met', function () {
            $client = new CommentsClient('token', null, 'owner/repo', 123);
            expect($client->isAvailable())->toBeTrue();
        });
    });

    describe('extractPRNumber', function () {
        it('returns null when event name is not pull_request', function () {
            putenv('GITHUB_EVENT_NAME=push');
            $client = new CommentsClient('token', null, 'owner/repo');
            expect($client->isAvailable())->toBeFalse();
        });

        it('returns null when event path does not exist', function () {
            putenv('GITHUB_EVENT_PATH=/nonexistent/path');
            $client = new CommentsClient('token', null, 'owner/repo');
            expect($client->isAvailable())->toBeFalse();
        });

        it('returns null when event JSON has no PR number', function () {
            $tempFile = sys_get_temp_dir() . '/event_no_pr_' . uniqid() . '.json';
            file_put_contents($tempFile, json_encode(['action' => 'opened']));
            putenv("GITHUB_EVENT_PATH={$tempFile}");

            $client = new CommentsClient('token', null, 'owner/repo');
            expect($client->isAvailable())->toBeFalse();

            unlink($tempFile);
        });

        it('extracts PR number from event JSON', function () {
            $tempFile = sys_get_temp_dir() . '/event_' . uniqid() . '.json';
            file_put_contents($tempFile, json_encode(['pull_request' => ['number' => 456]]));
            putenv("GITHUB_EVENT_PATH={$tempFile}");

            $client = new CommentsClient('token', null, 'owner/repo');
            expect($client->isAvailable())->toBeTrue();

            unlink($tempFile);
        });
    });

    describe('postOrUpdateComment', function () {
        it('returns false when not available', function () {
            $client = new CommentsClient(null);
            expect($client->postOrUpdateComment('test body'))->toBeFalse();
        });

        it('creates new comment when none exists', function () {
            $mock = new MockHandler([
                new Response(200, [], json_encode([])), // No existing comments
                new Response(201), // Create comment
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            $client = new CommentsClient('token', $httpClient, 'owner/repo', 123);
            $result = $client->postOrUpdateComment('Test comment body');

            expect($result)->toBeTrue();
        });

        it('updates existing comment when found', function () {
            $mock = new MockHandler([
                new Response(200, [], json_encode([
                    ['id' => 456, 'body' => 'Previous comment with 🏆 Synapse Sentinel Gate'],
                ])),
                new Response(200), // Update comment
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            $client = new CommentsClient('token', $httpClient, 'owner/repo', 123);
            $result = $client->postOrUpdateComment('Updated comment body');

            expect($result)->toBeTrue();
        });

        it('returns false on API error when creating', function () {
            $mock = new MockHandler([
                new Response(200, [], json_encode([])), // No existing comments
                new Response(403, [], 'Forbidden'), // Create fails
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            $client = new CommentsClient('token', $httpClient, 'owner/repo', 123);

            ob_start();
            $result = $client->postOrUpdateComment('Test comment body');
            $output = ob_get_clean();

            expect($result)->toBeFalse()
                ->and($output)->toContain('::warning::');
        });

        it('returns false on API error when updating', function () {
            $mock = new MockHandler([
                new Response(200, [], json_encode([
                    ['id' => 456, 'body' => 'Previous comment with 🏆 Synapse Sentinel Gate'],
                ])),
                new Response(403, [], 'Forbidden'), // Update fails
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            $client = new CommentsClient('token', $httpClient, 'owner/repo', 123);

            ob_start();
            $result = $client->postOrUpdateComment('Updated comment body');
            $output = ob_get_clean();

            expect($result)->toBeFalse()
                ->and($output)->toContain('::warning::');
        });

        it('returns null from findExistingComment on API error', function () {
            $mock = new MockHandler([
                new Response(403, [], 'Forbidden'), // Get comments fails
                new Response(201), // Still creates new comment
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            $client = new CommentsClient('token', $httpClient, 'owner/repo', 123);
            $result = $client->postOrUpdateComment('Test comment body');

            expect($result)->toBeTrue();
        });

        it('uses custom signature for finding comments', function () {
            $mock = new MockHandler([
                new Response(200, [], json_encode([
                    ['id' => 789, 'body' => 'Comment with custom-signature-here'],
                ])),
                new Response(200), // Update comment
            ]);
            $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

            $client = new CommentsClient('token', $httpClient, 'owner/repo', 123);
            $result = $client->postOrUpdateComment('Updated body', 'custom-signature-here');

            expect($result)->toBeTrue();
        });
    });
});
