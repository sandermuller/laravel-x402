<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;

/**
 * Process-global token store for {@see MiddlewareSpec}. Specs are registered
 * at route-definition time (in `routes/web.php`) and resolved by token at
 * request time inside the middleware.
 *
 * Token = "x402-spec-" . sha1 of spec contents. Same overrides reuse the
 * same token, keeping route caching deterministic.
 */
final class MiddlewareSpecRegistry
{
    /**
     * @var array<string, MiddlewareSpec>
     */
    private static array $specs = [];

    public static function register(MiddlewareSpec $spec): string
    {
        $token = 'x402-spec-' . hash('sha256', implode('|', [
            $spec->middleware,
            $spec->amount,
            $spec->asset,
            $spec->network,
            $spec->payTo ?? '',
            $spec->description ?? '',
            // closures don't serialize cleanly; use object hash for identity
            $spec->skipWhen instanceof Closure ? spl_object_hash($spec->skipWhen) : '',
        ]));

        self::$specs[$token] = $spec;

        return $token;
    }

    public static function resolve(string $token): ?MiddlewareSpec
    {
        return self::$specs[$token] ?? null;
    }

    public static function flush(): void
    {
        self::$specs = [];
    }
}
