<?php

declare(strict_types=1);

use App\Slack\SlackClient;
use App\Slack\SlackMessage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

describe('SlackClient', function () {
    describe('isAvailable', function () {
        it('returns true when webhook URL is configured', function () {
            $client = new SlackClient('https://hooks.slack.com/services/T00/B00/XXX');

            expect($client->isAvailable())->toBeTrue();
        });

        it('returns false when webhook URL is null', function () {
            // Ensure env var is not set
            $originalEnv = getenv('SLACK_WEBHOOK_URL');
            putenv('SLACK_WEBHOOK_URL=');

            $client = new SlackClient(null);

            expect($client->isAvailable())->toBeFalse();

            if ($originalEnv !== false) {
                putenv("SLACK_WEBHOOK_URL={$originalEnv}");
            }
        });

        it('returns false when webhook URL is empty string', function () {
            $client = new SlackClient('');

            expect($client->isAvailable())->toBeFalse();
        });

        it('reads webhook URL from environment when not provided', function () {
            putenv('SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T00/B00/XXX');

            $client = new SlackClient();

            expect($client->isAvailable())->toBeTrue();

            putenv('SLACK_WEBHOOK_URL=');
        });
    });

    describe('send', function () {
        it('returns false when not available', function () {
            $client = new SlackClient('');

            $message = SlackMessage::create()->fallback('Test');

            expect($client->send($message))->toBeFalse();
        });

        it('returns true on successful send', function () {
            $mock = new MockHandler([
                new Response(200, [], 'ok'),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient(
                'https://hooks.slack.com/services/T00/B00/XXX',
                $httpClient
            );

            $message = SlackMessage::create()
                ->fallback('Test message')
                ->header('Hello');

            expect($client->send($message))->toBeTrue();
        });

        it('returns false on API error', function () {
            $mock = new MockHandler([
                new RequestException('Connection error', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient(
                'https://hooks.slack.com/services/T00/B00/XXX',
                $httpClient
            );

            $message = SlackMessage::create()->fallback('Test');

            expect($client->send($message))->toBeFalse();
        });

        it('returns false on non-200 response', function () {
            $mock = new MockHandler([
                new Response(400, [], 'invalid_payload'),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient(
                'https://hooks.slack.com/services/T00/B00/XXX',
                $httpClient
            );

            $message = SlackMessage::create()->fallback('Test');

            expect($client->send($message))->toBeFalse();
        });

        it('outputs warning in GitHub Actions environment on error', function () {
            putenv('GITHUB_ACTIONS=true');

            $mock = new MockHandler([
                new RequestException('Network timeout', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient(
                'https://hooks.slack.com/services/T00/B00/XXX',
                $httpClient
            );

            $message = SlackMessage::create()->fallback('Test');

            ob_start();
            $client->send($message);
            $output = ob_get_clean();

            expect($output)->toContain('::warning::');
            expect($output)->toContain('Network timeout');

            putenv('GITHUB_ACTIONS=');
        });
    });

    describe('sendRaw', function () {
        it('returns false when not available', function () {
            $client = new SlackClient('');

            expect($client->sendRaw(['text' => 'Test']))->toBeFalse();
        });

        it('returns true on successful send', function () {
            $mock = new MockHandler([
                new Response(200, [], 'ok'),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient(
                'https://hooks.slack.com/services/T00/B00/XXX',
                $httpClient
            );

            expect($client->sendRaw(['text' => 'Raw message']))->toBeTrue();
        });

        it('returns false on API error', function () {
            $mock = new MockHandler([
                new RequestException('Connection error', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient(
                'https://hooks.slack.com/services/T00/B00/XXX',
                $httpClient
            );

            expect($client->sendRaw(['text' => 'Test']))->toBeFalse();
        });
    });

    describe('sendTo', function () {
        it('sends to specific webhook URL', function () {
            $mock = new MockHandler([
                new Response(200, [], 'ok'),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient(
                'https://hooks.slack.com/services/DEFAULT/XXX',
                $httpClient
            );

            $message = SlackMessage::create()->fallback('Test');

            expect($client->sendTo(
                'https://hooks.slack.com/services/OTHER/YYY',
                $message
            ))->toBeTrue();
        });

        it('works even when default webhook is not configured', function () {
            $mock = new MockHandler([
                new Response(200, [], 'ok'),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient('', $httpClient);

            $message = SlackMessage::create()->fallback('Test');

            expect($client->sendTo(
                'https://hooks.slack.com/services/T00/B00/XXX',
                $message
            ))->toBeTrue();
        });

        it('returns false on API error', function () {
            $mock = new MockHandler([
                new RequestException('Connection error', new Request('POST', 'test')),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new SlackClient('', $httpClient);

            $message = SlackMessage::create()->fallback('Test');

            expect($client->sendTo(
                'https://hooks.slack.com/services/T00/B00/XXX',
                $message
            ))->toBeFalse();
        });
    });
});
