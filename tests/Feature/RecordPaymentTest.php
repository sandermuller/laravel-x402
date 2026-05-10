<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use X402\Facilitator\SettleResult;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Facades\X402;
use X402\Laravel\Listeners\RecordPayment;
use X402\Laravel\Listeners\RecordPaymentQueued;
use X402\Laravel\Models\Payment;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

beforeEach(function (): void {
    $this->app->make(Repository::class)->set('x402.history.enabled', true);

    $migration = include __DIR__ . '/../../database/migrations/2026_01_01_000000_create_x402_payments_table.php';
    $migration->up();

    // The provider's boot() ran *before* we flipped enabled — re-register the
    // listener now so the test gets the wiring it would in real usage.
    $events = $this->app->make(Dispatcher::class);
    $events->listen(PaymentSettled::class, [RecordPayment::class, 'handleSettled']);
    $events->listen(PaymentRejected::class, [RecordPayment::class, 'handleRejected']);
});

afterEach(function (): void {
    $migration = include __DIR__ . '/../../database/migrations/2026_01_01_000000_create_x402_payments_table.php';
    $migration->down();
});

function recordPaymentChallenge(): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0xrecipient',
        resource: 'https://example.test/premium',
    );
}

function recordPaymentSignature(string $nonce): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'authorization' => [
                'from' => '0xpayer',
                'nonce' => $nonce,
                'validBefore' => 99999999999,
            ],
        ],
    );
}

it('settled event writes a row with the right columns', function (): void {
    $nonce = '0x' . str_repeat('a', 64);

    event(new PaymentSettled(
        result: new SettleResult(
            success: true,
            transaction: '0xtxhash',
            network: 'eip155:8453',
            payer: '0xpayer',
            amount: '10000',
        ),
        resource: 'https://example.test/premium',
        challenge: recordPaymentChallenge(),
        signature: recordPaymentSignature($nonce),
        context: ['tenant_id' => 't42'],
    ));

    /** @var Payment $row */
    $row = Payment::settled()->firstOrFail();

    expect($row->payer)->toBe('0xpayer')
        ->and($row->pay_to)
        ->toBe('0xrecipient')
        ->and($row->amount)
        ->toBe('10000')
        ->and($row->transaction)
        ->toBe('0xtxhash')
        ->and($row->nonce)
        ->toBe($nonce)
        ->and($row->meta)
        ->toBe(['tenant_id' => 't42'])
        ->and($row->settled_at)->not->toBeNull();
});

it('rejected event writes a row with reason and no transaction', function (): void {
    $nonce = '0x' . str_repeat('b', 64);

    event(new PaymentRejected(
        reason: 'insufficient-funds',
        resource: 'https://example.test/premium',
        challenge: recordPaymentChallenge(),
        signature: recordPaymentSignature($nonce),
        context: ['tenant_id' => 't42'],
    ));

    /** @var Payment $row */
    $row = Payment::rejected()->firstOrFail();

    expect($row->reason)->toBe('insufficient-funds')
        ->and($row->transaction)
        ->toBeNull()
        ->and($row->nonce)
        ->toBe($nonce)
        ->and($row->settled_at)
        ->toBeNull();
});

it('dedup on retry — same nonce updates instead of inserting twice', function (): void {
    $nonce = '0x' . str_repeat('c', 64);

    $settle = fn () => event(new PaymentSettled(
        result: new SettleResult(success: true, transaction: '0xtxhash', network: 'eip155:8453', payer: '0xpayer'),
        resource: 'https://example.test/premium',
        challenge: recordPaymentChallenge(),
        signature: recordPaymentSignature($nonce),
    ));

    $settle();
    $settle();

    expect(Payment::query()->count())->toBe(1);
});

it('queued listener variant carries the configured queue name', function (): void {
    $listener = new RecordPaymentQueued('payments-low');

    expect($listener->queue)->toBe('payments-low');
});

it('full request flow — settle through middleware writes a payment row', function (): void {
    X402::fake();

    Route::middleware('x402:0.01,USDC,base')->get('/premium-history', fn (): string => 'ok');

    $sig = base64_encode((string) json_encode([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => '0xpayer',
                'to' => config('x402.recipient'),
                'value' => '10000',
                'validAfter' => Date::now()->getTimestamp() - 10,
                'validBefore' => Date::now()->getTimestamp() + 60,
                'nonce' => '0x' . bin2hex(random_bytes(32)),
            ],
        ],
    ]));

    $this->withHeader('X-PAYMENT', $sig)->get('/premium-history')->assertOk();

    expect(Payment::settled()->count())->toBe(1);
    /** @var Payment $row */
    $row = Payment::settled()->firstOrFail();
    expect($row->resource)->toBe('http://localhost/premium-history');
});

it('migration runs cleanly and creates the table', function (): void {
    expect(Schema::hasTable('x402_payments'))->toBeTrue();
});
