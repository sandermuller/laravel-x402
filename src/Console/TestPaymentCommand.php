<?php

declare(strict_types=1);

namespace X402\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class TestPaymentCommand extends Command
{
    protected $signature = 'x402:test-payment {url : URL of an x402-protected resource}';

    protected $description = 'Send a test request through the x402 client middleware and report the result.';

    public function handle(): int
    {
        $url = (string) $this->argument('url');

        $this->info('GET '.$url);

        $response = Http::withX402()->get($url);

        $this->info('Status: '.$response->status());

        if ($response->successful()) {
            $receipt = $response->header('X-PAYMENT-RESPONSE') ?: $response->header('PAYMENT-RESPONSE');
            if ($receipt !== '') {
                $this->info('Settlement receipt: '.$receipt);
            }

            return self::SUCCESS;
        }

        $this->error('Request failed.');
        $this->line($response->body());

        return self::FAILURE;
    }
}
