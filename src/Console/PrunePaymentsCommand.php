<?php

declare(strict_types=1);

namespace X402\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Throwable;
use X402\Laravel\Models\Payment;

/**
 * Bounded pruner for the `x402_payments` history table. Targets failed
 * verifies on public endpoints — they can flood the table fast and have
 * little forensic value past a short window.
 *
 *   php artisan x402:prune --before=30days --status=rejected
 *   php artisan x402:prune --before=2026-01-01 --status=settled
 *   php artisan x402:prune --before=7days
 */
final class PrunePaymentsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'x402:prune
        {--before=30days : Relative ("30days") or absolute ("2026-01-01") cut-off; rows older than this go.}
        {--status= : Optional status filter — "settled" or "rejected". Omit to prune all statuses.}
        {--dry-run : Report the count without deleting.}
        {--force : Skip the production confirmation prompt.}';

    protected $description = 'Prune x402_payments rows older than the given window.';

    public function handle(): int
    {
        $beforeArg = (string) $this->option('before');
        $cutoff = $this->parseCutoff($beforeArg);

        if (! $cutoff instanceof Carbon) {
            $this->error(sprintf('Could not parse --before=%s. Use a relative ("30days", "7days") or ISO date.', $beforeArg));

            return self::INVALID;
        }

        $status = $this->option('status');
        if (is_string($status) && ! in_array($status, [Payment::STATUS_SETTLED, Payment::STATUS_REJECTED], true)) {
            $this->error(sprintf('Invalid --status=%s. Use "settled" or "rejected".', $status));

            return self::INVALID;
        }

        $query = Payment::query()->where('created_at', '<', $cutoff);
        if (is_string($status)) {
            $query->where('status', $status);
        }

        if ((bool) $this->option('dry-run')) {
            $count = $query->toBase()->count();
            $this->info('Would prune ' . $count . ' row(s) created before ' . $cutoff->toIso8601String() . '.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $deleted = $query->toBase()->delete();

        $this->info('Pruned ' . $deleted . ' row(s) created before ' . $cutoff->toIso8601String() . '.');

        return self::SUCCESS;
    }

    private function parseCutoff(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d+)(days?|weeks?|months?|years?)$/i', $value, $matches) === 1) {
            return Date::now()->sub($matches[2], (int) $matches[1]);
        }

        try {
            return Date::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
