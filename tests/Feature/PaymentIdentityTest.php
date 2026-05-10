<?php

declare(strict_types=1);

use X402\Facilitator\SettleResult;
use X402\Laravel\Listeners\Support\PaymentIdentity;
use X402\Protocol\PaymentSignature;

function paymentSignatureWithAuth(string $nonce, string $from): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => $from,
                'to' => '0xrecipient',
                'value' => '10000',
                'validAfter' => 0,
                'validBefore' => 9999999999,
                'nonce' => $nonce,
            ],
        ],
    );
}

it('extracts transaction + nonce + payer from a settled signature+result', function (): void {
    $sig = paymentSignatureWithAuth('0xabcdef', '0xfromauth');
    $result = new SettleResult(
        success: true,
        transaction: '0xtxhash',
        network: 'eip155:8453',
        payer: '0xpayerfromresult',
    );

    $identity = PaymentIdentity::fromSignature($sig, $result);

    expect($identity->transaction)->toBe('0xtxhash')
        ->and($identity->nonce)
        ->toBe('0xabcdef')
        ->and($identity->from)
        ->toBe('0xpayerfromresult');
});

it('falls back to auth.from when the result payer is empty', function (): void {
    $sig = paymentSignatureWithAuth('0xabc', '0xfromauth');
    $result = new SettleResult(
        success: true,
        transaction: '0xtx',
        network: 'eip155:8453',
        payer: '',
    );

    expect(PaymentIdentity::fromSignature($sig, $result)->from)->toBe('0xfromauth');
});

it('treats an empty result transaction as null', function (): void {
    $sig = paymentSignatureWithAuth('0xabc', '0xfrom');
    $result = new SettleResult(
        success: false,
        transaction: '',
        network: 'eip155:8453',
        payer: '',
        errorReason: 'verify failed',
    );

    expect(PaymentIdentity::fromSignature($sig, $result)->transaction)->toBeNull();
});

it('extracts only nonce + from when no SettleResult is provided (rejected path)', function (): void {
    $sig = paymentSignatureWithAuth('0xabc', '0xfrom');

    $identity = PaymentIdentity::fromSignature($sig);

    expect($identity->transaction)->toBeNull()
        ->and($identity->nonce)
        ->toBe('0xabc')
        ->and($identity->from)
        ->toBe('0xfrom');
});

it('returns nulls all the way through when the signature is null', function (): void {
    $identity = PaymentIdentity::fromSignature(null);

    expect($identity->transaction)->toBeNull()
        ->and($identity->nonce)
        ->toBeNull()
        ->and($identity->from)
        ->toBeNull();
});

it('key() prefers transaction over nonce', function (): void {
    $identity = new PaymentIdentity(transaction: '0xtx', nonce: '0xn', from: '0xf');

    expect($identity->key())->toBe(['transaction' => '0xtx']);
});

it('key() falls back to nonce when transaction is null', function (): void {
    $identity = new PaymentIdentity(transaction: null, nonce: '0xn', from: null);

    expect($identity->key())->toBe(['nonce' => '0xn']);
});

it('key() returns null when neither transaction nor nonce is present', function (): void {
    $identity = new PaymentIdentity(transaction: null, nonce: null, from: '0xf');

    expect($identity->key())->toBeNull();
});

it('key() treats empty strings as missing', function (): void {
    $identity = new PaymentIdentity(transaction: '', nonce: '', from: null);

    expect($identity->key())->toBeNull();
});
