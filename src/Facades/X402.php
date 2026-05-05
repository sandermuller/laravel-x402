<?php

declare(strict_types=1);

namespace X402\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use X402\Facilitator\FacilitatorClient;

/**
 * @method static \X402\Facilitator\VerifyResult verify(\X402\Protocol\PaymentSignature $signature, \X402\Protocol\PaymentRequired $challenge)
 * @method static \X402\Facilitator\SettleResult settle(\X402\Protocol\PaymentSignature $signature, \X402\Protocol\PaymentRequired $challenge)
 */
final class X402 extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FacilitatorClient::class;
    }
}
