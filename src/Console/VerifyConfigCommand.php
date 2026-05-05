<?php

declare(strict_types=1);

namespace X402\Laravel\Console;

use Illuminate\Console\Command;
use X402\Client\Wallet;

final class VerifyConfigCommand extends Command
{
    protected $signature = 'x402:verify-config';

    protected $description = 'Validate the x402 configuration and report any missing required values.';

    public function handle(): int
    {
        $errors = [];

        $config = $this->laravel->make('config');

        if (! $config->get('x402.recipient')) {
            $errors[] = 'x402.recipient is empty (set X402_RECIPIENT).';
        }

        if (! $config->get('x402.network')) {
            $errors[] = 'x402.network is empty.';
        }

        $asset = $config->get('x402.asset');
        if (! is_array($asset) || empty($asset['address'])) {
            $errors[] = 'x402.asset.address is empty.';
        }

        if (! $config->get('x402.facilitator.url')) {
            $errors[] = 'x402.facilitator.url is empty.';
        }

        try {
            /** @var Wallet $wallet */
            $wallet = $this->laravel->make(Wallet::class);
            $this->info('Wallet address: '.$wallet->address());
        } catch (\Throwable $e) {
            $errors[] = 'Wallet resolution failed: '.$e->getMessage();
        }

        if ($errors === []) {
            $this->info('x402 config OK.');

            return self::SUCCESS;
        }

        foreach ($errors as $err) {
            $this->error($err);
        }

        return self::FAILURE;
    }
}
