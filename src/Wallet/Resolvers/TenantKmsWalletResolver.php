<?php

declare(strict_types=1);

namespace X402\Laravel\Wallet\Resolvers;

use Aws\Kms\KmsClient;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use X402\Client\AwsKmsWallet;
use X402\Client\Wallet;
use X402\Laravel\Client\WalletResolver;

/**
 * Reference per-tenant {@see WalletResolver} backed by AWS KMS. Each
 * tenant signs with a distinct KMS key id; the underlying `KmsClient`
 * is shared (it is policy-free — auth, region, retry are configured on
 * the client itself).
 *
 * Wire from a service provider:
 *
 * ```php
 * $this->app->bind(WalletResolver::class, fn ($app) => new TenantKmsWalletResolver(
 *     kms: $app->make(\Aws\Kms\KmsClient::class),
 *     keyIdByTenant: [
 *         'acme'   => 'arn:aws:kms:us-east-1:123:key/abc...',
 *         'globex' => 'arn:aws:kms:us-east-1:123:key/def...',
 *     ],
 * ));
 * ```
 *
 * `$context` is whatever the caller threads through `Http::withX402()`.
 * The default extraction reads `tenant_id` off the authenticated user
 * when a `Request` is passed, and otherwise treats the context as the
 * tenant id directly. Hosts whose tenant lookup differs pass a custom
 * extractor via the `tenantIdResolver:` constructor argument.
 */
final readonly class TenantKmsWalletResolver implements WalletResolver
{
    /**
     * @param  array<string, string>  $keyIdByTenant  Tenant id → KMS key id (or ARN). Lookups are case-sensitive.
     * @param  ?Closure(mixed): string  $tenantIdResolver  Optional. Extract the tenant id from `$context`. Default reads `Request::user()->tenant_id`, falls back to treating a non-empty string `$context` as the id.
     */
    public function __construct(
        private KmsClient $kms,
        private array $keyIdByTenant,
        private ?Closure $tenantIdResolver = null,
    ) {}

    public function resolve(mixed $context = null): Wallet
    {
        $resolver = $this->tenantIdResolver ?? $this->defaultTenantIdResolver();
        $rawTenantId = $resolver($context);

        if (! is_string($rawTenantId) || $rawTenantId === '') {
            throw new RuntimeException('tenantIdResolver returned a non-string or empty value; expected the tenant id.');
        }

        $keyId = $this->keyIdByTenant[$rawTenantId] ?? throw new RuntimeException(sprintf(
            'No KMS key configured for tenant "%s". Add it to keyIdByTenant or pass a custom tenantIdResolver.',
            $rawTenantId,
        ));

        return new AwsKmsWallet(kms: $this->kms, keyId: $keyId);
    }

    private function defaultTenantIdResolver(): Closure
    {
        return static function (mixed $context): string {
            if ($context instanceof Request) {
                $user = $context->user();
                $id = $user?->getAttribute('tenant_id');

                if (! is_string($id) || $id === '') {
                    throw new RuntimeException('Authenticated user has no string tenant_id attribute; pass a custom tenantIdResolver to TenantKmsWalletResolver.');
                }

                return $id;
            }

            if (is_string($context) && $context !== '') {
                return $context;
            }

            throw new RuntimeException('TenantKmsWalletResolver could not resolve a tenant id from the supplied context. Pass a Request whose user has a tenant_id, a non-empty string, or a custom tenantIdResolver.');
        };
    }
}
