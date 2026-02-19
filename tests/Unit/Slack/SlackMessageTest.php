<?php

declare(strict_types=1);

use App\Slack\SlackMessage;

describe('SlackMessage', function () {
    describe('create', function () {
        it('returns a new instance', function () {
            $message = SlackMessage::create();

            expect($message)->toBeInstanceOf(SlackMessage::class);
        });
    });

    describe('fallback', function () {
        it('sets the fallback text', function () {
            $message = SlackMessage::create()
                ->fallback('Deploy started: myrepo');

            $payload = $message->toArray();

            expect($payload['text'])->toBe('Deploy started: myrepo');
        });
    });

    describe('header', function () {
        it('adds a header block with plain text', function () {
            $message = SlackMessage::create()
                ->header('Deploy Started');

            $blocks = $message->getBlocks();

            expect($blocks)->toHaveCount(1);
            expect($blocks[0]['type'])->toBe('header');
            expect($blocks[0]['text']['type'])->toBe('plain_text');
            expect($blocks[0]['text']['text'])->toBe('Deploy Started');
            expect($blocks[0]['text']['emoji'])->toBeTrue();
        });
    });

    describe('section', function () {
        it('adds a section block with markdown', function () {
            $message = SlackMessage::create()
                ->section('*Bold text* and _italic_');

            $blocks = $message->getBlocks();

            expect($blocks)->toHaveCount(1);
            expect($blocks[0]['type'])->toBe('section');
            expect($blocks[0]['text']['type'])->toBe('mrkdwn');
            expect($blocks[0]['text']['text'])->toBe('*Bold text* and _italic_');
        });
    });

    describe('sectionWithAccessory', function () {
        it('adds a section block with an accessory', function () {
            $accessory = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Click'],
            ];

            $message = SlackMessage::create()
                ->sectionWithAccessory('Click the button', $accessory);

            $blocks = $message->getBlocks();

            expect($blocks)->toHaveCount(1);
            expect($blocks[0]['type'])->toBe('section');
            expect($blocks[0]['accessory'])->toBe($accessory);
        });
    });

    describe('fields', function () {
        it('adds a section with formatted fields', function () {
            $message = SlackMessage::create()
                ->fields([
                    ['label' => 'Repository', 'value' => 'owner/repo'],
                    ['label' => 'Branch', 'value' => 'main'],
                ]);

            $blocks = $message->getBlocks();

            expect($blocks)->toHaveCount(1);
            expect($blocks[0]['type'])->toBe('section');
            expect($blocks[0]['fields'])->toHaveCount(2);
            expect($blocks[0]['fields'][0]['type'])->toBe('mrkdwn');
            expect($blocks[0]['fields'][0]['text'])->toBe("*Repository*\nowner/repo");
            expect($blocks[0]['fields'][1]['text'])->toBe("*Branch*\nmain");
        });
    });

    describe('divider', function () {
        it('adds a divider block', function () {
            $message = SlackMessage::create()->divider();

            $blocks = $message->getBlocks();

            expect($blocks)->toHaveCount(1);
            expect($blocks[0]['type'])->toBe('divider');
        });
    });

    describe('context', function () {
        it('adds a context block with markdown elements', function () {
            $message = SlackMessage::create()
                ->context(['Posted at 10:00', 'By user']);

            $blocks = $message->getBlocks();

            expect($blocks)->toHaveCount(1);
            expect($blocks[0]['type'])->toBe('context');
            expect($blocks[0]['elements'])->toHaveCount(2);
            expect($blocks[0]['elements'][0]['type'])->toBe('mrkdwn');
            expect($blocks[0]['elements'][0]['text'])->toBe('Posted at 10:00');
        });
    });

    describe('actions', function () {
        it('adds action buttons', function () {
            $message = SlackMessage::create()
                ->actions([
                    ['text' => 'View Commit', 'url' => 'https://github.com/commit/123'],
                    ['text' => 'Retry', 'style' => 'primary'],
                ]);

            $blocks = $message->getBlocks();

            expect($blocks)->toHaveCount(1);
            expect($blocks[0]['type'])->toBe('actions');
            expect($blocks[0]['elements'])->toHaveCount(2);
            expect($blocks[0]['elements'][0]['type'])->toBe('button');
            expect($blocks[0]['elements'][0]['text']['text'])->toBe('View Commit');
            expect($blocks[0]['elements'][0]['url'])->toBe('https://github.com/commit/123');
            expect($blocks[0]['elements'][1]['style'])->toBe('primary');
        });

        it('generates action_id if not provided', function () {
            $message = SlackMessage::create()
                ->actions([
                    ['text' => 'Click Me'],
                ]);

            $blocks = $message->getBlocks();

            expect($blocks[0]['elements'][0]['action_id'])->toStartWith('action_');
        });

        it('uses provided action_id', function () {
            $message = SlackMessage::create()
                ->actions([
                    ['text' => 'Click Me', 'action_id' => 'custom_action'],
                ]);

            $blocks = $message->getBlocks();

            expect($blocks[0]['elements'][0]['action_id'])->toBe('custom_action');
        });

        it('includes value when provided', function () {
            $message = SlackMessage::create()
                ->actions([
                    ['text' => 'Approve', 'value' => 'deploy_123'],
                ]);

            $blocks = $message->getBlocks();

            expect($blocks[0]['elements'][0]['value'])->toBe('deploy_123');
        });
    });

    describe('color', function () {
        it('wraps blocks in attachment with color', function () {
            $message = SlackMessage::create()
                ->color('#36a64f')
                ->header('Success')
                ->section('All good');

            $payload = $message->toArray();

            expect($payload)->toHaveKey('attachments');
            expect($payload['attachments'])->toHaveCount(1);
            expect($payload['attachments'][0]['color'])->toBe('#36a64f');
            expect($payload['attachments'][0]['blocks'])->toHaveCount(2);
        });

        it('puts blocks at root when no color', function () {
            $message = SlackMessage::create()
                ->header('Plain')
                ->section('No color');

            $payload = $message->toArray();

            expect($payload)->toHaveKey('blocks');
            expect($payload)->not->toHaveKey('attachments');
            expect($payload['blocks'])->toHaveCount(2);
        });
    });

    describe('timestamp', function () {
        it('formats timestamp with Slack date syntax', function () {
            $ts = 1708300800; // Feb 19, 2024 00:00:00 UTC

            $result = SlackMessage::timestamp($ts);

            expect($result)->toContain('<!date^1708300800^');
            expect($result)->toContain('{date_short_pretty} at {time}');
        });

        it('uses custom format', function () {
            $ts = 1708300800;

            $result = SlackMessage::timestamp($ts, '{date_long}');

            expect($result)->toContain('<!date^1708300800^{date_long}|');
        });

        it('uses custom fallback', function () {
            $ts = 1708300800;

            $result = SlackMessage::timestamp($ts, '{time}', 'Custom Fallback');

            expect($result)->toContain('|Custom Fallback>');
        });
    });

    describe('toJson', function () {
        it('returns valid JSON string', function () {
            $message = SlackMessage::create()
                ->fallback('Test')
                ->header('Hello');

            $json = $message->toJson();

            expect($json)->toBeString();
            $decoded = json_decode($json, true);
            expect($decoded)->not->toBeNull();
            expect($decoded['text'])->toBe('Test');
        });
    });

    describe('chaining', function () {
        it('supports fluent chaining of all methods', function () {
            $message = SlackMessage::create()
                ->fallback('Deploy notification')
                ->color('#36a64f')
                ->header('Deploy Succeeded')
                ->section(':white_check_mark: *Successfully deployed*')
                ->fields([
                    ['label' => 'Repo', 'value' => 'owner/repo'],
                    ['label' => 'Branch', 'value' => 'main'],
                ])
                ->divider()
                ->context(['Deployed just now'])
                ->actions([
                    ['text' => 'View', 'url' => 'https://example.com'],
                ]);

            $payload = $message->toArray();

            expect($payload['text'])->toBe('Deploy notification');
            expect($payload['attachments'][0]['color'])->toBe('#36a64f');
            expect($payload['attachments'][0]['blocks'])->toHaveCount(6);
        });
    });
});
