<?php

declare(strict_types=1);

namespace X402\Laravel\Console;

use Illuminate\Console\Command;

/**
 * Interactive scaffold: publishes the config and ensures the two required
 * environment variables (recipient + private key) are present in `.env`.
 * Existing values are never overwritten.
 */
final class InstallCommand extends Command
{
    protected $signature = 'x402:install
        {--recipient= : EVM address that receives settlements (X402_RECIPIENT)}
        {--private-key= : Buyer wallet key for outbound Http::withX402() (X402_PRIVATE_KEY)}
        {--no-publish : Skip publishing the config file}';

    protected $description = 'Publish the x402 config and write missing environment variables to .env.';

    public function handle(): int
    {
        if (! (bool) $this->option('no-publish')) {
            $this->call('vendor:publish', ['--tag' => 'x402-config']);
        }

        $envPath = $this->laravel->basePath('.env');

        if (! is_file($envPath)) {
            $this->warn('.env not found at ' . $envPath . ' — skipping env append. Add X402_RECIPIENT and X402_PRIVATE_KEY manually.');

            return self::SUCCESS;
        }

        $recipient = $this->resolveOption('recipient', 'Recipient EVM address (X402_RECIPIENT)', '0x...');
        $privateKey = $this->resolveOption('private-key', 'Buyer wallet private key (X402_PRIVATE_KEY) — leave empty to skip', '');

        $appended = [];

        if ($recipient !== '' && ! $this->envHasKey($envPath, 'X402_RECIPIENT')) {
            $this->appendEnv($envPath, 'X402_RECIPIENT', $recipient);
            $appended[] = 'X402_RECIPIENT';
        }

        if ($privateKey !== '' && ! $this->envHasKey($envPath, 'X402_PRIVATE_KEY')) {
            $this->appendEnv($envPath, 'X402_PRIVATE_KEY', $privateKey);
            $appended[] = 'X402_PRIVATE_KEY';
        }

        if ($appended === []) {
            $this->info('No env changes — required keys already present.');
        } else {
            $this->info('Appended to .env: ' . implode(', ', $appended));
        }

        $this->line('Run `php artisan x402:verify-config` to sanity-check the setup.');

        return self::SUCCESS;
    }

    private function resolveOption(string $name, string $prompt, string $default): string
    {
        $value = $this->option($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! $this->input->isInteractive()) {
            return '';
        }

        $answer = $this->ask($prompt, $default === '' ? null : $default);

        return is_string($answer) ? $answer : '';
    }

    private function envHasKey(string $path, string $key): bool
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        return preg_match('/^' . preg_quote($key, '/') . '=/m', $contents) === 1;
    }

    private function appendEnv(string $path, string $key, string $value): void
    {
        $line = $key . '=' . self::quoteEnvValue($value) . PHP_EOL;
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Quote an `.env` value when it contains characters that the parser
     * (`Dotenv`) would interpret — whitespace, `#` (comment marker),
     * `"` / `'` / `\` / `$` — so the appended line round-trips correctly.
     *
     * Public so the quoting rule is unit-testable; the surrounding
     * scaffold logic is integration-shaped.
     */
    public static function quoteEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s#"\'\\\\$]/', $value) !== 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }
}
