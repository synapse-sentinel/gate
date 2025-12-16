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

    private ?int $prNumber;

    public function __construct(
        private readonly ?string $token = null,
        ?Client $client = null,
        ?string $repo = null,
        ?string $sha = null,
        ?int $prNumber = null,
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => 'https://api.github.com/',
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => $this->token ? "Bearer {$this->token}" : '',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        $this->repo = $repo ?? (getenv('GITHUB_REPOSITORY') ?: '');
        $this->sha = $sha ?? (getenv('GITHUB_SHA') ?: '');
        $this->prNumber = $prNumber ?? $this->extractPRNumber();
    }

    private function extractPRNumber(): ?int
    {
        // Try GITHUB_REF_NAME first (e.g., "123/merge" for PRs)
        $refName = getenv('GITHUB_REF_NAME') ?: '';
        if (preg_match('/^(\d+)\/merge$/', $refName, $matches)) {
            return (int) $matches[1];
        }

        // Try GITHUB_REF (e.g., "refs/pull/123/merge")
        $ref = getenv('GITHUB_REF') ?: '';
        if (preg_match('/^refs\/pull\/(\d+)\//', $ref, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function getRepo(): string
    {
        return $this->repo;
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
            echo "::warning::ChecksClient not available (token={$hasToken}, repo={$this->repo}, sha={$this->sha})\n";
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
            echo "::warning::ChecksClient error: {$e->getMessage()}\n";
            return false;
        }
    }

    /**
     * Post a certification comment on a PR with badge code.
     *
     * @param  array<string, string>  $checkResults  Map of check name => result message
     */
    public function postCertificationComment(array $checkResults): bool
    {
        if (! $this->isAvailable() || $this->prNumber === null) {
            return false;
        }

        $badgeUrl = "https://img.shields.io/github/actions/workflow/status/{$this->repo}/gate.yml?label=Sentinel%20Certified&style=flat-square";
        $actionUrl = "https://github.com/{$this->repo}/actions/workflows/gate.yml";

        $checksSection = '';
        foreach ($checkResults as $name => $result) {
            $checksSection .= "✅ **{$name}**: {$result}\n";
        }

        $body = <<<MARKDOWN
## 🏆 Sentinel Certified

{$checksSection}
---

**Add this badge to your README:**

```markdown
[![Sentinel Certified]({$badgeUrl})]({$actionUrl})
```
MARKDOWN;

        try {
            $this->client->post("repos/{$this->repo}/issues/{$this->prNumber}/comments", [
                'json' => ['body' => $body],
            ]);

            return true;
        } catch (GuzzleException) {
            return false;
        }
    }
}
