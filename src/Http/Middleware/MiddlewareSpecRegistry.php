<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use RuntimeException;

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

        // Snapshot the spec — fluent setters return $this, so a builder kept
        // alive after registration could otherwise mutate the cached entry.
        self::$specs[$token] = clone $spec;

        return $token;
    }

    /**
     * Resolve a token to its registered spec. Throws if the token is
     * unknown — typically a sign that routes were cached (`route:cache`)
     * before the spec registry was warmed.
     *
     * Callers wanting "spec or null" semantics can use {@see has()} first.
     */
    public static function resolve(string $token): MiddlewareSpec
    {
        if (! isset(self::$specs[$token])) {
            throw new RuntimeException(sprintf(
                'Unknown x402 middleware spec token "%s". This usually means routes were cached '
                . '(`route:cache`) but the spec registry was not warmed — call your route file once '
                . 'or avoid `payTo()`/`describing()`/`skipWhen()` on cached routes.',
                $token,
            ));
        }

        return self::$specs[$token];
    }

    public static function has(string $token): bool
    {
        return isset(self::$specs[$token]);
    }

    public static function flush(): void
    {
        self::$specs = [];
    }
}
