<?php

declare(strict_types=1);

namespace X402\Laravel\Client;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use X402\Client\PrivateKeyWallet;
use X402\Client\Wallet;
use X402\Exceptions\X402Exception;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Schemes\Evm\AuthorizationSigner;
use X402\Schemes\Evm\Eip712Hasher;

/**
 * Registers the `Http::withX402()` macro on Laravel's HTTP client factory.
 *
 * Implementation: a Guzzle handler-stack middleware that catches 402, signs
 * an EIP-3009 authorization with the operator wallet, and retries with the
 * PAYMENT-SIGNATURE header. Mirrors php-x402's PayingClient but plugs into
 * Guzzle's promise pipeline directly so it composes with retry, logging,
 * and macro chains the host already uses.
 */
final class HttpClientMacro
{
    public static function register(HttpFactory $http, Container $container): void
    {
        $factory = static function (?string $privateKey = null) use ($container): callable {
            $wallet = $privateKey !== null
                ? new PrivateKeyWallet($privateKey)
                : $container->make(Wallet::class);

            $version = Version::from((string) $container->make('config')->get('x402.version', 'v1'));

            return self::guzzleMiddleware($wallet, $version);
        };

        $http::macro('withX402', function (?string $privateKey = null) use ($factory): PendingRequest {
            /** @var PendingRequest $self */
            $self = $this; // @phpstan-ignore-line — macro $this binding

            return $self->withMiddleware($factory($privateKey));
        });
    }

    /**
     * Build the Guzzle handler-stack middleware closure.
     */
    public static function guzzleMiddleware(Wallet $wallet, Version $version): \Closure
    {
        return static function (callable $next) use ($wallet, $version): \Closure {
            return static function (RequestInterface $request, array $options) use ($next, $wallet, $version) {
                /** @var \GuzzleHttp\Promise\PromiseInterface $promise */
                $promise = $next($request, $options);

                return $promise->then(static function (ResponseInterface $response) use ($next, $request, $options, $wallet, $version) {
                    if ($response->getStatusCode() !== 402) {
                        return $response;
                    }

                    $challenge = self::decodeChallenge($response, $version);
                    $signed = self::sign($wallet, $challenge, $version);

                    $paid = $request->withHeader($version->signatureHeader(), $signed->toHeader());

                    return $next($paid, $options);
                });
            };
        };
    }

    private static function decodeChallenge(ResponseInterface $response, Version $version): PaymentRequired
    {
        $headerLine = $response->getHeaderLine($version->challengeHeader());

        $body = $headerLine !== ''
            ? (base64_decode($headerLine, true) ?: '')
            : (string) $response->getBody();

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new X402Exception('Could not decode 402 challenge: '.$e->getMessage(), previous: $e);
        }

        $accepts = $decoded['accepts'] ?? [];
        if (! is_array($accepts) || $accepts === []) {
            throw new X402Exception('402 response contained no challenges.');
        }

        /** @var array<string, mixed> $first */
        $first = $accepts[0];

        return new PaymentRequired(
            scheme: (string) $first['scheme'],
            network: (string) $first['network'],
            maxAmountRequired: (string) $first['maxAmountRequired'],
            asset: (string) $first['asset'],
            payTo: (string) $first['payTo'],
            maxTimeoutSeconds: isset($first['maxTimeoutSeconds']) ? (int) $first['maxTimeoutSeconds'] : 60,
            extra: isset($first['extra']) && is_array($first['extra']) ? $first['extra'] : [],
        );
    }

    private static function sign(Wallet $wallet, PaymentRequired $challenge, Version $version): PaymentSignature
    {
        $now = time();
        $message = [
            'from' => $wallet->address(),
            'to' => $challenge->payTo,
            'value' => $challenge->maxAmountRequired,
            'validAfter' => $now - 5,
            'validBefore' => $now + $challenge->maxTimeoutSeconds,
            'nonce' => AuthorizationSigner::randomNonce(),
        ];

        $chainId = str_starts_with($challenge->network, 'eip155:')
            ? (int) substr($challenge->network, 7)
            : 0;

        $domain = [
            'name' => (string) ($challenge->extra['name'] ?? ''),
            'version' => (string) ($challenge->extra['version'] ?? '2'),
            'chainId' => $chainId,
            'verifyingContract' => $challenge->asset,
        ];

        $digest = (new Eip712Hasher)->digest($domain, $message);
        $signature = $wallet->signDigest($digest);

        return new PaymentSignature(
            scheme: $challenge->scheme,
            network: $challenge->network,
            payload: ['signature' => $signature, 'authorization' => $message],
            x402Version: $version === Version::V2 ? '2' : '1',
        );
    }
}
