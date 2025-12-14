<?php

declare(strict_types=1);

namespace App\GitHub;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class ChecksClient
{
    private Client $client;

    private string $repo;

    private string $sha;

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
        $this->sha = getenv('GITHUB_SHA') ?: '';
    }

    public function isAvailable(): bool
    {
        return $this->token !== null
            && $this->token !== ''
            && $this->repo !== ''
            && $this->sha !== '';
    }

    public function createCheck(string $name, string $status = 'in_progress'): ?int
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $response = $this->client->post("repos/{$this->repo}/check-runs", [
                'json' => [
                    'name' => $name,
                    'head_sha' => $this->sha,
                    'status' => $status,
                    'started_at' => date('c'),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['id'] ?? null;
        } catch (GuzzleException) {
            return null;
        }
    }

    public function completeCheck(
        int $checkId,
        bool $passed,
        string $title,
        string $summary,
    ): bool {
        if (! $this->isAvailable()) {
            return false;
        }

        try {
            $this->client->patch("repos/{$this->repo}/check-runs/{$checkId}", [
                'json' => [
                    'status' => 'completed',
                    'conclusion' => $passed ? 'success' : 'failure',
                    'completed_at' => date('c'),
                    'output' => [
                        'title' => $title,
                        'summary' => $summary,
                    ],
                ],
            ]);

            return true;
        } catch (GuzzleException) {
            return false;
        }
    }

    public function reportCheck(
        string $name,
        bool $passed,
        string $title,
        string $summary,
    ): bool {
        if (! $this->isAvailable()) {
            $hasToken = $this->token ? 'yes' : 'no';
            fwrite(STDERR, "ChecksClient: Not available (token={$hasToken}, repo={$this->repo}, sha={$this->sha})\n");
            return false;
        }

        try {
            $this->client->post("repos/{$this->repo}/check-runs", [
                'json' => [
                    'name' => $name,
                    'head_sha' => $this->sha,
                    'status' => 'completed',
                    'conclusion' => $passed ? 'success' : 'failure',
                    'started_at' => date('c'),
                    'completed_at' => date('c'),
                    'output' => [
                        'title' => $title,
                        'summary' => $summary,
                    ],
                ],
            ]);

            return true;
        } catch (GuzzleException $e) {
            fwrite(STDERR, "ChecksClient error: {$e->getMessage()}\n");
            return false;
        }
    }
}
