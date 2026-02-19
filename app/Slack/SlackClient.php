<?php

declare(strict_types=1);

namespace App\Slack;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP client for sending Slack notifications via webhooks.
 */
final class SlackClient
{
    private Client $httpClient;

    private ?string $webhookUrl;

    public function __construct(?string $webhookUrl = null, ?Client $httpClient = null)
    {
        $this->webhookUrl = $webhookUrl ?? (getenv('SLACK_WEBHOOK_URL') ?: null);
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Check if the client is configured and ready to send.
     */
    public function isAvailable(): bool
    {
        return $this->webhookUrl !== null && $this->webhookUrl !== '';
    }

    /**
     * Send a SlackMessage to the configured webhook.
     */
    public function send(SlackMessage $message): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        try {
            $response = $this->httpClient->post($this->webhookUrl, [
                'json' => $message->toArray(),
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            $this->logError('send', $e->getMessage());

            return false;
        }
    }

    /**
     * Send raw payload to the webhook (for testing or custom payloads).
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendRaw(array $payload): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        try {
            $response = $this->httpClient->post($this->webhookUrl, [
                'json' => $payload,
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            $this->logError('sendRaw', $e->getMessage());

            return false;
        }
    }

    /**
     * Send to a specific webhook URL (overriding the default).
     */
    public function sendTo(string $webhookUrl, SlackMessage $message): bool
    {
        try {
            $response = $this->httpClient->post($webhookUrl, [
                'json' => $message->toArray(),
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            $this->logError('sendTo', $e->getMessage());

            return false;
        }
    }

    private function logError(string $method, string $message): void
    {
        // Use GitHub Actions annotation format when in CI
        if (getenv('GITHUB_ACTIONS')) {
            echo "::warning::Slack API error in {$method}: {$message}\n";
        }
    }
}
