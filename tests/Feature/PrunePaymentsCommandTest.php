<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Command\Command;
use X402\Laravel\Models\Payment;

beforeEach(function (): void {
    $migration = include __DIR__ . '/../../database/migrations/2026_01_01_000000_create_x402_payments_table.php';
    $migration->up();
});

afterEach(function (): void {
    Date::setTestNow();
    $migration = include __DIR__ . '/../../database/migrations/2026_01_01_000000_create_x402_payments_table.php';
    $migration->down();
});

function pruneSeed(string $createdAt, string $status = 'rejected'): void
{
    Date::setTestNow($createdAt);

    Payment::query()->create([
        'status' => $status,
        'resource' => 'https://example.test/premium',
        'pay_to' => '0xrecipient',
        'amount' => '10000',
        'asset' => '0xasset',
        'network' => 'eip155:8453',
        'nonce' => '0x' . bin2hex(random_bytes(16)),
    ]);
}

it('prunes rows older than the relative window', function (): void {
    pruneSeed('2026-01-01');
    pruneSeed('2026-04-01');
    Date::setTestNow('2026-05-01');

    $this->artisan('x402:prune', ['--before' => '60days'])->assertSuccessful();

    expect(Payment::query()->count())->toBe(1);
});

it('prunes rows older than an absolute --before date', function (): void {
    pruneSeed('2026-01-01');
    pruneSeed('2026-04-01');
    Date::setTestNow('2026-05-01');

    $this->artisan('x402:prune', ['--before' => '2026-03-01'])->assertSuccessful();

    expect(Payment::query()->count())->toBe(1);
});

it('honours the --status filter', function (): void {
    pruneSeed('2026-01-01', 'settled');
    pruneSeed('2026-01-01', 'rejected');
    Date::setTestNow('2026-05-01');

    $this->artisan('x402:prune', ['--before' => '30days', '--status' => 'rejected'])->assertSuccessful();

    expect(Payment::settled()->count())->toBe(1)
        ->and(Payment::rejected()
            ->count())
        ->toBe(0);
});

it('--dry-run reports without deleting', function (): void {
    pruneSeed('2026-01-01');
    Date::setTestNow('2026-05-01');

    $this->artisan('x402:prune', ['--before' => '30days', '--dry-run' => true])->assertSuccessful();

    expect(Payment::query()->count())->toBe(1);
});

it('rejects an unparseable --before', function (): void {
    $this->artisan('x402:prune', ['--before' => 'not-a-date'])
        ->assertExitCode(Command::INVALID);
});

it('rejects an invalid --status', function (): void {
    $this->artisan('x402:prune', ['--before' => '30days', '--status' => 'pending'])
        ->assertExitCode(Command::INVALID);
});
