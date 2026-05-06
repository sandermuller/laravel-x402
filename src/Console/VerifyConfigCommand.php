<?php

declare(strict_types=1);

namespace X402\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;
use X402\Client\Wallet;
use X402\Laravel\Support\ConfigReader;

final class VerifyConfigCommand extends Command
{
    protected $signature = 'x402:verify-config';

    protected $description = 'Validate the x402 configuration and report any missing required values.';

    public function handle(): int
    {
        $errors = [];

        $config = $this->laravel->make(Repository::class);

        if (ConfigReader::string($config, 'x402.recipient') === '') {
            $errors[] = 'x402.recipient is empty (set X402_RECIPIENT).';
        }

        if (ConfigReader::string($config, 'x402.network') === '') {
            $errors[] = 'x402.network is empty.';
        }

        $asset = $config->get('x402.asset');
        $address = is_array($asset) ? ($asset['address'] ?? null) : null;
        if (! is_string($address) || $address === '') {
            $errors[] = 'x402.asset.address is empty.';
        }

        if (ConfigReader::string($config, 'x402.facilitator.url') === '') {
            $errors[] = 'x402.facilitator.url is empty.';
        }

        try {
            $wallet = $this->laravel->make(Wallet::class);
            $this->info('Wallet address: ' . $wallet->address());
        } catch (Throwable $throwable) {
            $errors[] = 'Wallet resolution failed: ' . $throwable->getMessage();
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
