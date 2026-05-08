<?php

declare(strict_types=1);

namespace X402\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class TestPaymentCommand extends Command
{
    protected $signature = 'x402:test-payment
        {url : URL of an x402-protected resource}
        {--simulate-bot= : User-Agent to send (e.g. "GPTBot/1.0") to test the bots middleware}
        {--ping : Send an unsigned request and just report the 402 challenge — no wallet, no payment}
        {--json : Emit machine-readable JSON instead of the human report}';

    protected $description = 'Send a test request through the x402 client middleware and report the result.';

    public function handle(): int
    {
        $urlRaw = $this->argument('url');
        $url = is_string($urlRaw) ? $urlRaw : '';

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $this->fail('Invalid URL: ' . ($url === '' ? '(empty)' : $url));
        }

        $userAgent = $this->option('simulate-bot');
        $userAgent = is_string($userAgent) && $userAgent !== '' ? $userAgent : null;

        $ping = (bool) $this->option('ping');
        $json = (bool) $this->option('json');

        $client = $ping ? Http::asJson() : Http::withX402();

        if ($userAgent !== null) {
            $client = $client->withHeaders(['User-Agent' => $userAgent]);
        }

        $response = $client->get($url);

        $report = [
            'url' => $url,
            'mode' => $ping ? 'ping' : 'pay',
            'simulate_bot' => $userAgent,
            'status' => $response->status(),
            'success' => $response->successful(),
            'receipt' => $this->extractReceipt($response->header('X-PAYMENT-RESPONSE'), $response->header('PAYMENT-RESPONSE')),
            'challenge' => $response->status() === 402 ? $response->json() : null,
        ];

        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $response->successful() || $response->status() === 402 ? self::SUCCESS : self::FAILURE;
        }

        $this->info(($ping ? 'PING ' : 'GET  ') . $url);

        if ($userAgent !== null) {
            $this->line('User-Agent: ' . $userAgent);
        }

        $this->line('Status: ' . $response->status());

        if ($report['receipt'] !== null) {
            $this->info('Settlement receipt: ' . $report['receipt']);
        }

        if ($response->successful()) {
            return self::SUCCESS;
        }

        if ($response->status() === 402) {
            $this->warn('402 — payment required.');
            $this->line($response->body());

            return self::SUCCESS;
        }

        $this->error('Request failed.');
        $this->line($response->body());

        return self::FAILURE;
    }

    private function extractReceipt(string $primary, string $fallback): ?string
    {
        if ($primary !== '') {
            return $primary;
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return null;
    }
}
