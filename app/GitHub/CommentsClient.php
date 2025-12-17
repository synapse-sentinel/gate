<?php

declare(strict_types=1);

namespace App\GitHub;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class CommentsClient
{
    private Client $client;

    private string $repo;

    private ?int $prNumber;

    public function __construct(
        private readonly ?string $token = null,
    ) {
        $this->client = new Client([
            'base_uri' => 'https://api.github.com/',
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => $this->token ? "Bearer {$this->token}" : '',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        $this->repo = getenv('GITHUB_REPOSITORY') ?: '';
        $this->prNumber = $this->extractPRNumber();
    }

    public function isAvailable(): bool
    {
        return $this->token !== null
            && $this->token !== ''
            && $this->repo !== ''
            && $this->prNumber !== null;
    }

    public function postOrUpdateComment(string $body, string $signature = '🏆 Synapse Sentinel Gate'): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $fullBody = $body;

        // Try to find existing comment
        $existingCommentId = $this->findExistingComment($signature);

        try {
            if ($existingCommentId !== null) {
                // Update existing comment
                $this->client->patch("repos/{$this->repo}/issues/comments/{$existingCommentId}", [
                    'json' => ['body' => $fullBody],
                ]);
            } else {
                // Create new comment
                $this->client->post("repos/{$this->repo}/issues/{$this->prNumber}/comments", [
                    'json' => ['body' => $fullBody],
                ]);
            }

            return true;
        } catch (GuzzleException $e) {
            echo "::warning::Failed to post/update PR comment: {$e->getMessage()}\n";
            return false;
        }
    }

    private function findExistingComment(string $signature): ?int
    {
        try {
            $response = $this->client->get("repos/{$this->repo}/issues/{$this->prNumber}/comments");
            $comments = json_decode($response->getBody()->getContents(), true);

            foreach ($comments as $comment) {
                if (str_contains($comment['body'] ?? '', $signature)) {
                    return $comment['id'];
                }
            }

            return null;
        } catch (GuzzleException) {
            return null;
        }
    }

    private function extractPRNumber(): ?int
    {
        // Check if we're in a PR context
        $eventName = getenv('GITHUB_EVENT_NAME');
        if ($eventName !== 'pull_request') {
            return null;
        }

        // Try to get PR number from event path
        $eventPath = getenv('GITHUB_EVENT_PATH');
        if (! $eventPath || ! file_exists($eventPath)) {
            return null;
        }

        $event = json_decode(file_get_contents($eventPath), true);
        return $event['pull_request']['number'] ?? null;
    }
}
