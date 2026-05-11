<?php

declare(strict_types=1);

use Aws\Kms\KmsClient;
use Illuminate\Http\Request;
use X402\Client\AwsKmsWallet;
use X402\Client\PrivateKeyWallet;
use X402\Client\Wallet;
use X402\Laravel\Wallet\Resolvers\TenantKmsWalletResolver;

it('defaults to the private_key driver and resolves a PrivateKeyWallet', function (): void {
    expect($this->app->make(Wallet::class))->toBeInstanceOf(PrivateKeyWallet::class);
});

it('throws an actionable error for an unknown wallet driver', function (): void {
    config()->set('x402.wallet.driver', 'mystery');

    resolve(Wallet::class);
})->throws(RuntimeException::class, 'Unknown x402 wallet driver "mystery"');

it('builds an AwsKmsWallet when driver=kms and provider=aws', function (): void {
    config()->set('x402.wallet.driver', 'kms');
    config()->set('x402.wallet.kms.provider', 'aws');
    config()->set('x402.wallet.kms.aws.region', 'us-east-1');
    config()->set('x402.wallet.kms.aws.key_id', 'arn:aws:kms:us-east-1:123:key/abc');

    expect($this->app->make(Wallet::class))->toBeInstanceOf(AwsKmsWallet::class);
});

it('throws when the KMS provider is unset', function (): void {
    config()->set('x402.wallet.driver', 'kms');
    config()->set('x402.wallet.kms.provider', '');

    resolve(Wallet::class);
})->throws(RuntimeException::class, 'x402.wallet.kms.provider is not configured');

it('throws when the KMS provider is unknown', function (): void {
    config()->set('x402.wallet.driver', 'kms');
    config()->set('x402.wallet.kms.provider', 'azure');

    resolve(Wallet::class);
})->throws(RuntimeException::class, 'Unknown x402 KMS provider "azure"');

it('throws when AWS key id is unset', function (): void {
    config()->set('x402.wallet.driver', 'kms');
    config()->set('x402.wallet.kms.provider', 'aws');
    config()->set('x402.wallet.kms.aws.region', 'us-east-1');
    config()->set('x402.wallet.kms.aws.key_id', '');

    resolve(Wallet::class);
})->throws(RuntimeException::class, 'x402.wallet.kms.aws.key_id is not configured');

it('honours a host-bound KmsClient instead of constructing one', function (): void {
    $sentinel = new KmsClient(['region' => 'eu-west-1', 'version' => 'latest']);
    $this->app->instance(KmsClient::class, $sentinel);

    config()->set('x402.wallet.driver', 'kms');
    config()->set('x402.wallet.kms.provider', 'aws');
    config()->set('x402.wallet.kms.aws.key_id', 'arn:aws:kms:eu-west-1:123:key/x');

    $wallet = $this->app->make(Wallet::class);

    expect($wallet)->toBeInstanceOf(AwsKmsWallet::class);
    assert($wallet instanceof AwsKmsWallet);

    $reflection = new ReflectionClass(AwsKmsWallet::class);
    $kmsProperty = $reflection->getProperty('kms');

    expect($kmsProperty->getValue($wallet))->toBe($sentinel);
});

describe('TenantKmsWalletResolver', function (): void {
    it('returns an AwsKmsWallet for a known tenant', function (): void {
        $resolver = new TenantKmsWalletResolver(
            kms: new KmsClient(['region' => 'us-east-1', 'version' => 'latest']),
            keyIdByTenant: ['acme' => 'arn:aws:kms:us-east-1:123:key/acme'],
        );

        expect($resolver->resolve('acme'))->toBeInstanceOf(AwsKmsWallet::class);
    });

    it('throws when the tenant has no configured key', function (): void {
        $resolver = new TenantKmsWalletResolver(
            kms: new KmsClient(['region' => 'us-east-1', 'version' => 'latest']),
            keyIdByTenant: ['acme' => 'arn:aws:kms:us-east-1:123:key/acme'],
        );

        $resolver->resolve('globex');
    })->throws(RuntimeException::class, 'No KMS key configured for tenant "globex"');

    it('honours a custom tenantIdResolver closure', function (): void {
        $resolver = new TenantKmsWalletResolver(
            kms: new KmsClient(['region' => 'us-east-1', 'version' => 'latest']),
            keyIdByTenant: ['internal' => 'arn:aws:kms:us-east-1:123:key/internal'],
            tenantIdResolver: static fn (mixed $context): string => 'internal',
        );

        expect($resolver->resolve(new Request()))->toBeInstanceOf(AwsKmsWallet::class);
    });

    it('throws when the default resolver gets neither a Request nor a string', function (): void {
        $resolver = new TenantKmsWalletResolver(
            kms: new KmsClient(['region' => 'us-east-1', 'version' => 'latest']),
            keyIdByTenant: [],
        );

        $resolver->resolve(['tenant' => 'acme']);
    })->throws(RuntimeException::class, 'could not resolve a tenant id');
});
