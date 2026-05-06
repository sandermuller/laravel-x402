<?php

declare(strict_types=1);

namespace X402\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\VerifyResult;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * @method static VerifyResult verify(PaymentSignature $signature, PaymentRequired $challenge)
 * @method static SettleResult settle(PaymentSignature $signature, PaymentRequired $challenge)
 */
final class X402 extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FacilitatorClient::class;
    }
}
