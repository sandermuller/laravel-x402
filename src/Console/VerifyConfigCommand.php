<?php

declare(strict_types=1);

namespace X402\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Throwable;
use X402\Client\Wallet;
use X402\Laravel\Support\AssetRegistry;
use X402\Laravel\Support\ConfigReader;

final class VerifyConfigCommand extends Command
{
    protected $signature = 'x402:verify-config
        {--ping : Probe the configured facilitator URL and report reachability + auth}';

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

        try {
            AssetRegistry::fromConfig($config);
        } catch (Throwable $throwable) {
            $errors[] = 'Asset config invalid: ' . $throwable->getMessage();
        }

        $facilitatorUrl = ConfigReader::string($config, 'x402.facilitator.url');
        if ($facilitatorUrl === '') {
            $errors[] = 'x402.facilitator.url is empty.';
        }

        $driver = ConfigReader::string($config, 'x402.wallet.driver', 'private_key');
        $driverDetail = $driver;
        if ($driver === 'kms') {
            $provider = ConfigReader::string($config, 'x402.wallet.kms.provider');
            $driverDetail = sprintf('kms (%s)', $provider === '' ? 'unset' : $provider);
        }

        $this->info('Wallet driver: ' . $driverDetail);

        try {
            $wallet = $this->laravel->make(Wallet::class);
            $this->info('Wallet address: ' . $wallet->address());
        } catch (Throwable $throwable) {
            $errors[] = 'Wallet resolution failed: ' . $throwable->getMessage();
        }

        if ((bool) $this->option('ping') && $facilitatorUrl !== '') {
            $errors = array_merge($errors, $this->pingFacilitator($config, $facilitatorUrl));
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

    /**
     * @return list<string>
     */
    private function pingFacilitator(Repository $config, string $url): array
    {
        $authRaw = ConfigReader::array($config, 'x402.facilitator.auth');
        /** @var array<string, string> $auth */
        $auth = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $authRaw);

        try {
            $response = Http::timeout(5)
                ->withHeaders($auth)
                ->get(rtrim($url, '/') . '/supported');
        } catch (Throwable $throwable) {
            return ['Facilitator ping failed: ' . $throwable->getMessage()];
        }

        if ($response->successful()) {
            $this->info(sprintf('Facilitator reachable: %s — HTTP %d', $url, $response->status()));

            return [];
        }

        return [sprintf('Facilitator returned HTTP %d for %s/supported', $response->status(), rtrim($url, '/'))];
    }
}
