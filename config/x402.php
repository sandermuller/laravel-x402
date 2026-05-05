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
    | Default settlement asset
    |--------------------------------------------------------------------------
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
    | Max payment timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'max_timeout_seconds' => (int) env('X402_MAX_TIMEOUT', 60),

];
