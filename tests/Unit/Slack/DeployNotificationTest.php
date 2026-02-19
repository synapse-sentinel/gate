<?php

declare(strict_types=1);

use App\Slack\DeployNotification;
use App\Slack\SlackMessage;

describe('DeployNotification', function () {
    describe('create', function () {
        it('returns a new instance', function () {
            $notification = DeployNotification::create();

            expect($notification)->toBeInstanceOf(DeployNotification::class);
        });
    });

    describe('fluent setters', function () {
        it('supports fluent chaining', function () {
            $notification = DeployNotification::create()
                ->repo('owner/repo')
                ->branch('main')
                ->commit('abc1234567890', 'Fix bug')
                ->author('developer')
                ->commitCount(3)
                ->environment('production')
                ->commitUrl('https://github.com/owner/repo/commit/abc123')
                ->compareUrl('https://github.com/owner/repo/compare/main...abc123')
                ->logsUrl('https://github.com/owner/repo/actions/runs/123')
                ->retryUrl('https://github.com/owner/repo/actions/runs/123/rerun')
                ->timestamp(1708300800);

            expect($notification)->toBeInstanceOf(DeployNotification::class);
        });
    });

    describe('buildStarted', function () {
        it('returns a SlackMessage', function () {
            $notification = createTestNotification();

            $message = $notification->buildStarted();

            expect($message)->toBeInstanceOf(SlackMessage::class);
        });

        it('uses in-progress color', function () {
            $notification = createTestNotification();

            $message = $notification->buildStarted();
            $payload = $message->toArray();

            expect($payload['attachments'][0]['color'])->toBe(DeployNotification::COLOR_IN_PROGRESS);
        });

        it('includes repository and environment in fallback', function () {
            $notification = createTestNotification();

            $message = $notification->buildStarted();
            $payload = $message->toArray();

            expect($payload['text'])->toContain('owner/repo');
            expect($payload['text'])->toContain('production');
            expect($payload['text'])->toContain('abc1234');
            expect($payload['text'])->toContain('developer');
        });

        it('includes header with Deploy Started', function () {
            $notification = createTestNotification();

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $headerBlock = collect($blocks)->firstWhere('type', 'header');
            expect($headerBlock['text']['text'])->toBe('Deploy Started');
        });

        it('includes status indicator in body', function () {
            $notification = createTestNotification();

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $hasIndicator = $sectionBlocks->contains(function ($block) {
                return str_contains($block['text']['text'] ?? '', DeployNotification::INDICATOR_IN_PROGRESS);
            });

            expect($hasIndicator)->toBeTrue();
        });

        it('includes action buttons', function () {
            $notification = createTestNotification();

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $actionsBlock = collect($blocks)->firstWhere('type', 'actions');
            expect($actionsBlock)->not->toBeNull();
            expect($actionsBlock['elements'])->toHaveCount(3); // View Commit, View Diff, View Logs
        });
    });

    describe('buildSuccess', function () {
        it('uses success color', function () {
            $notification = createTestNotification();

            $message = $notification->buildSuccess();
            $payload = $message->toArray();

            expect($payload['attachments'][0]['color'])->toBe(DeployNotification::COLOR_SUCCESS);
        });

        it('includes success indicator', function () {
            $notification = createTestNotification();

            $message = $notification->buildSuccess();
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $hasIndicator = $sectionBlocks->contains(function ($block) {
                return str_contains($block['text']['text'] ?? '', DeployNotification::INDICATOR_SUCCESS);
            });

            expect($hasIndicator)->toBeTrue();
        });

        it('includes succeeded in fallback', function () {
            $notification = createTestNotification();

            $message = $notification->buildSuccess();
            $payload = $message->toArray();

            expect($payload['text'])->toContain('succeeded');
        });
    });

    describe('buildFailure', function () {
        it('uses failure color', function () {
            $notification = createTestNotification();

            $message = $notification->buildFailure();
            $payload = $message->toArray();

            expect($payload['attachments'][0]['color'])->toBe(DeployNotification::COLOR_FAILURE);
        });

        it('includes failure indicator', function () {
            $notification = createTestNotification();

            $message = $notification->buildFailure();
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $hasIndicator = $sectionBlocks->contains(function ($block) {
                return str_contains($block['text']['text'] ?? '', DeployNotification::INDICATOR_FAILURE);
            });

            expect($hasIndicator)->toBeTrue();
        });

        it('includes error message when provided', function () {
            $notification = createTestNotification();

            $message = $notification->buildFailure('Connection timeout', 'Build');
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $hasError = $sectionBlocks->contains(function ($block) {
                return str_contains($block['text']['text'] ?? '', 'Connection timeout');
            });

            expect($hasError)->toBeTrue();
        });

        it('includes failed stage in fallback when provided', function () {
            $notification = createTestNotification();

            $message = $notification->buildFailure('', 'Build');
            $payload = $message->toArray();

            expect($payload['text'])->toContain('at Build');
        });

        it('includes retry button when retryUrl is set', function () {
            $notification = createTestNotification();

            $message = $notification->buildFailure();
            $blocks = $message->getBlocks();

            $actionsBlock = collect($blocks)->firstWhere('type', 'actions');
            expect($actionsBlock['elements'])->toHaveCount(4); // View Commit, View Diff, View Logs, Retry
            $retryButton = collect($actionsBlock['elements'])->firstWhere('text.text', 'Retry Deploy');
            expect($retryButton['style'])->toBe('primary');
        });
    });

    describe('buildProgress', function () {
        it('shows stage status indicators', function () {
            $notification = createTestNotification();

            $stages = [
                ['name' => 'Build', 'status' => 'completed'],
                ['name' => 'Test', 'status' => 'in_progress'],
                ['name' => 'Deploy', 'status' => 'pending'],
            ];

            $message = $notification->buildProgress($stages, 2);
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $stageSection = $sectionBlocks->first(function ($block) {
                return str_contains($block['text']['text'] ?? '', 'Build');
            });

            expect($stageSection['text']['text'])->toContain(DeployNotification::INDICATOR_SUCCESS);
            expect($stageSection['text']['text'])->toContain(DeployNotification::INDICATOR_IN_PROGRESS);
            expect($stageSection['text']['text'])->toContain(DeployNotification::INDICATOR_PENDING);
        });

        it('shows progress in fallback', function () {
            $notification = createTestNotification();

            $stages = [
                ['name' => 'Build', 'status' => 'completed'],
                ['name' => 'Test', 'status' => 'in_progress'],
            ];

            $message = $notification->buildProgress($stages, 2);
            $payload = $message->toArray();

            expect($payload['text'])->toContain('Stage 2/2');
        });

        it('handles failed stage status', function () {
            $notification = createTestNotification();

            $stages = [
                ['name' => 'Build', 'status' => 'failed'],
            ];

            $message = $notification->buildProgress($stages, 1);
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $stageSection = $sectionBlocks->first(function ($block) {
                return str_contains($block['text']['text'] ?? '', 'Build');
            });

            expect($stageSection['text']['text'])->toContain(DeployNotification::INDICATOR_FAILURE);
        });
    });

    describe('buildCertification', function () {
        it('shows certified status when passed', function () {
            $notification = createTestNotification();

            $checks = [
                ['name' => 'Tests', 'passed' => true, 'message' => '100% coverage'],
                ['name' => 'Security', 'passed' => true],
            ];

            $message = $notification->buildCertification(true, $checks);
            $payload = $message->toArray();

            expect($payload['text'])->toContain('CERTIFIED');
            expect($payload['attachments'][0]['color'])->toBe(DeployNotification::COLOR_SUCCESS);
        });

        it('shows rejected status when failed', function () {
            $notification = createTestNotification();

            $checks = [
                ['name' => 'Tests', 'passed' => false, 'message' => '80% coverage'],
            ];

            $message = $notification->buildCertification(false, $checks);
            $payload = $message->toArray();

            expect($payload['text'])->toContain('REJECTED');
            expect($payload['attachments'][0]['color'])->toBe(DeployNotification::COLOR_FAILURE);
        });

        it('shows check results with indicators', function () {
            $notification = createTestNotification();

            $checks = [
                ['name' => 'Tests', 'passed' => true, 'message' => '100% coverage'],
                ['name' => 'Security', 'passed' => false, 'message' => '2 vulnerabilities'],
            ];

            $message = $notification->buildCertification(false, $checks);
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $checksSection = $sectionBlocks->first(function ($block) {
                return str_contains($block['text']['text'] ?? '', 'Tests');
            });

            expect($checksSection['text']['text'])->toContain(DeployNotification::INDICATOR_SUCCESS);
            expect($checksSection['text']['text'])->toContain(DeployNotification::INDICATOR_FAILURE);
            expect($checksSection['text']['text'])->toContain('100% coverage');
            expect($checksSection['text']['text'])->toContain('2 vulnerabilities');
        });
    });

    describe('commit message truncation', function () {
        it('truncates long commit messages', function () {
            $notification = DeployNotification::create()
                ->repo('owner/repo')
                ->branch('main')
                ->commit('abc1234567890', str_repeat('a', 150))
                ->author('developer')
                ->commitCount(1)
                ->environment('production');

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $commitSection = $sectionBlocks->first(function ($block) {
                return str_contains($block['text']['text'] ?? '', 'aaa');
            });

            expect(strlen($commitSection['text']['text']))->toBeLessThan(150);
            expect($commitSection['text']['text'])->toContain('...');
        });

        it('uses first line of multi-line commit message', function () {
            $notification = DeployNotification::create()
                ->repo('owner/repo')
                ->branch('main')
                ->commit('abc1234567890', "First line\n\nSecond line\nThird line")
                ->author('developer')
                ->commitCount(1)
                ->environment('production');

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $sectionBlocks = collect($blocks)->where('type', 'section');
            $commitSection = $sectionBlocks->first(function ($block) {
                return str_contains($block['text']['text'] ?? '', 'First line');
            });

            expect($commitSection['text']['text'])->not->toContain('Second line');
        });
    });

    describe('commit link formatting', function () {
        it('formats commit as link when URL is provided', function () {
            $notification = createTestNotification();

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $fieldsBlock = collect($blocks)->firstWhere('fields');
            $commitField = collect($fieldsBlock['fields'])->first(function ($field) {
                return str_contains($field['text'], 'Commit');
            });

            expect($commitField['text'])->toContain('<https://');
            expect($commitField['text'])->toContain('abc1234');
        });

        it('shows plain commit hash when no URL', function () {
            $notification = DeployNotification::create()
                ->repo('owner/repo')
                ->branch('main')
                ->commit('abc1234567890', 'Test')
                ->author('developer')
                ->commitCount(1)
                ->environment('production');

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $fieldsBlock = collect($blocks)->firstWhere('fields');
            $commitField = collect($fieldsBlock['fields'])->first(function ($field) {
                return str_contains($field['text'], 'Commit');
            });

            expect($commitField['text'])->toContain('`abc1234`');
            expect($commitField['text'])->not->toContain('<https://');
        });
    });

    describe('singular/plural commit count', function () {
        it('uses singular for 1 commit', function () {
            $notification = DeployNotification::create()
                ->repo('owner/repo')
                ->branch('main')
                ->commit('abc1234567890', 'Test')
                ->author('developer')
                ->commitCount(1)
                ->environment('production');

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $contextBlock = collect($blocks)->firstWhere('type', 'context');
            $hasCommit = collect($contextBlock['elements'])->contains(function ($element) {
                return str_contains($element['text'], '1 commit');
            });

            expect($hasCommit)->toBeTrue();
        });

        it('uses plural for multiple commits', function () {
            $notification = createTestNotification();

            $message = $notification->buildStarted();
            $blocks = $message->getBlocks();

            $contextBlock = collect($blocks)->firstWhere('type', 'context');
            $hasCommits = collect($contextBlock['elements'])->contains(function ($element) {
                return str_contains($element['text'], '3 commits');
            });

            expect($hasCommits)->toBeTrue();
        });
    });
});

function createTestNotification(): DeployNotification
{
    return DeployNotification::create()
        ->repo('owner/repo')
        ->branch('main')
        ->commit('abc1234567890', 'Fix critical bug in authentication')
        ->author('developer')
        ->commitCount(3)
        ->environment('production')
        ->commitUrl('https://github.com/owner/repo/commit/abc1234567890')
        ->compareUrl('https://github.com/owner/repo/compare/main...abc1234567890')
        ->logsUrl('https://github.com/owner/repo/actions/runs/123')
        ->retryUrl('https://github.com/owner/repo/actions/runs/123/rerun')
        ->timestamp(1708300800);
}
