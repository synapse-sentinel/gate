<?php

declare(strict_types=1);

namespace App\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LaravelZero\Framework\Commands\Command;

class AnalyzeCommand extends Command
{
    protected $signature = 'analyze
        {--failures= : JSON file with failures to analyze}
        {--api-url= : Override API URL}
        {--api-token= : API token for authentication}';

    protected $description = 'Send failures to Prefrontal Cortex for AI analysis';

    public function handle(): int
    {
        $apiUrl = $this->option('api-url') ?? getenv('PREFRONTAL_API_URL') ?: 'https://prefrontal.jordanpartridge.us';
        $apiToken = $this->option('api-token') ?? getenv('PREFRONTAL_API_TOKEN') ?: null;

        if (! $apiToken) {
            $this->error('API token required. Set PREFRONTAL_API_TOKEN or use --api-token');

            return 1;
        }

        $failuresFile = $this->option('failures');
        if (! $failuresFile || ! file_exists($failuresFile)) {
            $this->error('Failures file required. Use --failures=path/to/failures.json');

            return 1;
        }

        $failuresContent = file_get_contents($failuresFile);
        if ($failuresContent === false) {
            $this->error('Could not read failures file');

            return 1;
        }

        $failures = json_decode($failuresContent, true);
        if (! $failures) {
            $this->error('Invalid JSON in failures file');

            return 1;
        }

        $this->info('🧠 Sending failures to Prefrontal Cortex for analysis...');

        try {
            $client = new Client([
                'base_uri' => $apiUrl,
                'timeout' => 60,
            ]);

            $response = $client->post('/api/gate/analyze', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiToken,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'repo' => $this->detectRepo(),
                    'sha' => $this->detectSha(),
                    'failures' => $failures,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if ($data) {
                $this->outputFixes($data['fixes'] ?? []);
                $this->newLine();
                $this->line($data['minimal_report'] ?? '');

                return 0;
            }

            $this->error('Invalid response from API');

            return 1;

        } catch (GuzzleException $e) {
            $this->error('Request failed: '.$e->getMessage());

            return 1;
        }
    }

    protected function detectRepo(): string
    {
        $remote = trim(shell_exec('git remote get-url origin 2>/dev/null') ?? '');
        if (preg_match('#github\.com[:/](.+?)(?:\.git)?$#', $remote, $matches)) {
            return $matches[1];
        }

        return basename(getcwd() ?: '.');
    }

    protected function detectSha(): string
    {
        return trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?? '');
    }

    /**
     * @param  array<int, array{type?: string, file?: string, suggestion?: string}>  $fixes
     */
    protected function outputFixes(array $fixes): void
    {
        foreach ($fixes as $fix) {
            $this->newLine();
            $this->info(sprintf('📝 %s: %s', ucfirst($fix['type'] ?? 'unknown'), $fix['file'] ?? 'unknown'));

            if (isset($fix['suggestion'])) {
                $this->line('');
                $this->line($fix['suggestion']);
            }
        }
    }
}
