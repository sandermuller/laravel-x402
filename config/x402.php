<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Protocol version
    |--------------------------------------------------------------------------
    |
    | "v1" or "v2". v2 (Dec 2025) renamed the wire headers; v1 SDKs still
    | dominate deployments. Pick the version your facilitator and clients
    | speak.
    */

    'version' => env('X402_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Recipient address
    |--------------------------------------------------------------------------
    |
    | Default EVM address that receives settlements. Per-route overrides
    | possible via the route middleware parameter.
    */

    'recipient' => env('X402_RECIPIENT'),

    /*
    |--------------------------------------------------------------------------
    | Network (CAIP-2)
    |--------------------------------------------------------------------------
    |
    | "eip155:8453" = Base mainnet. Other supported: 84532 (Base Sepolia),
    | 1 (Ethereum), 137 (Polygon), 42161 (Arbitrum One).
    */

    'network' => env('X402_NETWORK', 'eip155:8453'),

    /*
    |--------------------------------------------------------------------------
    | Network slug map
    |--------------------------------------------------------------------------
    |
    | Slugs used in `RequirePayment::using('0.01', 'USDC', 'base')` and the
    | builder's `->onNetwork($slug)` resolve to CAIP-2 chain IDs via this map.
    | Add custom chains here without releasing a new package version. Unknown
    | slugs are passed through verbatim (e.g. raw "eip155:1234").
    */

    'networks' => [
        'base' => 'eip155:8453',
        'base-sepolia' => 'eip155:84532',
        'ethereum' => 'eip155:1',
        'polygon' => 'eip155:137',
        'arbitrum' => 'eip155:42161',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default settlement asset
    |--------------------------------------------------------------------------
    |
    | Used when a route doesn't specify an asset symbol, or specifies one not
    | listed under `assets` below.
    */

    'asset' => [
        'address' => env('X402_ASSET_ADDRESS', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'), // USDC on Base
        'symbol' => env('X402_ASSET_SYMBOL', 'USDC'),
        'decimals' => (int) env('X402_ASSET_DECIMALS', 6),
        'eip712' => [
            'name' => env('X402_ASSET_EIP712_NAME', 'USD Coin'),
            'version' => env('X402_ASSET_EIP712_VERSION', '2'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset symbol map
    |--------------------------------------------------------------------------
    |
    | When a route picks an asset by symbol via the middleware arg or
    | `->asAsset('PYUSD')`, the lookup hits this table. Symbols not listed
    | here fall back to the `asset` block above. Keep address + decimals
    | + eip712 in sync with the on-chain contract for each asset.
    */

    'assets' => [
        'USDC' => [
            'address' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', // USDC on Base
            'decimals' => 6,
            'eip712' => ['name' => 'USD Coin', 'version' => '2'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Facilitator
    |--------------------------------------------------------------------------
    |
    | Default = Coinbase's hosted facilitator (free tier 1,000 tx/month). Set
    | "auth" headers when using the CDP-authenticated endpoint.
    */

    'facilitator' => [
        'url' => env('X402_FACILITATOR_URL', 'https://x402.org/facilitator'),
        'auth' => [
            // 'Authorization' => 'Bearer ' . env('X402_FACILITATOR_TOKEN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Buyer wallet
    |--------------------------------------------------------------------------
    |
    | Used by Http::withX402() to sign outbound payments. NEVER commit the
    | private key — set X402_PRIVATE_KEY in your environment / KMS.
    */

    'wallet' => [
        'private_key' => env('X402_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay store
    |--------------------------------------------------------------------------
    */

    'replay' => [
        'cache_store' => env('X402_REPLAY_CACHE', null), // null = default cache store
        'prefix' => 'x402:nonce:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency / response cache
    |--------------------------------------------------------------------------
    |
    | The `x402.cache` middleware closes the "paid but didn't receive content"
    | gap: if a client's connection drops between facilitator-settle and
    | response-received, the same signed authorization can be retried — the
    | prior 2xx response is replayed from cache instead of hitting the nonce
    | store's replay guard and 402-ing the user who already paid.
    |
    | Apply per route, before the payment middleware:
    |
    |     Route::middleware(['x402.cache', RequirePayment::using('0.01')])
    |
    | TTL must comfortably exceed `replay` TTL so retries past nonce expiry
    | still hit the cache.
    */

    'response_cache' => [
        'cache_store' => env('X402_RESPONSE_CACHE_STORE', null), // null = default cache store
        'ttl' => (int) env('X402_RESPONSE_CACHE_TTL', 3600),
        'prefix' => 'x402:idem:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max payment timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'max_timeout_seconds' => (int) env('X402_MAX_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Bot detection (x402.bots middleware)
    |--------------------------------------------------------------------------
    |
    | The `x402.bots` middleware charges only requests whose User-Agent
    | matches a known AI agent / scraper pattern. Humans pass through free.
    |
    | - "patterns": full override of the built-in list. Leave null to use
    |   the curated default (see X402\Laravel\Detection\BotDetector).
    | - "extra_patterns": appended to the active list — useful for adding
    |   one-off agents without redefining the entire set.
    */

    'bots' => [
        'patterns' => null,
        'extra_patterns' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit (throttle:x402)
    |--------------------------------------------------------------------------
    |
    | Default named limiter shipped by the package. Apply to gated routes via
    | `->middleware(['throttle:x402', RequirePayment::using('0.01')])` to cap
    | unpaid 402 floods. Set `per_minute => 0` to disable the named limiter
    | entirely (host registers its own under the same key).
    */

    'rate_limit' => [
        'per_minute' => (int) env('X402_RATE_LIMIT_PER_MINUTE', 60),
    ],

];
