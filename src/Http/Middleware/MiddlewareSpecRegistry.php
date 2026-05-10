<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Container\Container;
use RuntimeException;

/**
 * Token store for {@see MiddlewareSpec}. Specs are registered at
 * route-definition time (typically when `routes/web.php` runs) and resolved
 * by token at request time inside the middleware.
 *
 * Token = "x402-spec-" . sha256 of spec contents. Same overrides reuse the
 * same token, keeping route caching deterministic.
 *
 * Bound as a container singleton; the static facade methods proxy through
 * the container so existing call sites keep working while tests can swap in
 * a clean instance via `app()->instance(self::class, new self())`.
 */
final class MiddlewareSpecRegistry
{
    /**
     * @var array<string, MiddlewareSpec>
     */
    private array $specs = [];

    private int $closureSeq = 0;

    /**
     * @var array<int, int> spl_object_id($closure) => assigned sequence number
     */
    private array $closureSeqs = [];

    /**
     * @internal Specs are registered at route-definition time via
     *  {@see MiddlewareSpec::__toString()}. Calling this at request time is
     *  unsupported under Octane — the spec table is treated as static for
     *  the lifetime of the worker once routes are resolved.
     */
    public function add(MiddlewareSpec $spec): string
    {
        $token = 'x402-spec-' . hash('sha256', implode('|', [
            $spec->middleware,
            $spec->amount,
            $spec->asset,
            $spec->network,
            $spec->payTo ?? '',
            $spec->description ?? '',
            $spec->skipWhen instanceof Closure ? $this->closureToken($spec->skipWhen) : '',
            $spec->botGated ? '1' : '0',
        ]));

        $this->specs[$token] = $spec;

        return $token;
    }

    /**
     * Resolve a token to its registered spec. Throws if the token is
     * unknown — typically a sign that routes were cached (`route:cache`)
     * before the spec registry was warmed.
     */
    public function get(string $token): MiddlewareSpec
    {
        if (! isset($this->specs[$token])) {
            throw new RuntimeException(sprintf(
                'Unknown x402 middleware spec token "%s". This usually means routes were cached '
                . '(`route:cache`) but the spec registry was not warmed — call your route file once '
                . 'or avoid `payTo()`/`describing()`/`skipWhen()` on cached routes.',
                $token,
            ));
        }

        return $this->specs[$token];
    }

    public function exists(string $token): bool
    {
        return isset($this->specs[$token]);
    }

    public function clear(): void
    {
        $this->specs = [];
        $this->closureSeqs = [];
        $this->closureSeq = 0;
    }

    /**
     * Identity-based closure token. Same closure object reuses its sequence
     * number; distinct closures get distinct numbers, so two skipWhen()
     * predicates that look identical in source still hash to different
     * tokens — matching the original `spl_object_hash` behaviour but
     * collision-free for the lifetime of this registry.
     */
    private function closureToken(Closure $c): string
    {
        $oid = spl_object_id($c);

        if (! isset($this->closureSeqs[$oid])) {
            $this->closureSeqs[$oid] = ++$this->closureSeq;
        }

        return 'closure#' . $this->closureSeqs[$oid];
    }

    public static function register(MiddlewareSpec $spec): string
    {
        return self::instance()->add($spec);
    }

    public static function resolve(string $token): MiddlewareSpec
    {
        return self::instance()->get($token);
    }

    public static function has(string $token): bool
    {
        return self::instance()->exists($token);
    }

    public static function flush(): void
    {
        self::instance()->clear();
    }

    private static function instance(): self
    {
        $resolved = Container::getInstance()->make(self::class);

        if (! $resolved instanceof self) {
            throw new RuntimeException(sprintf(
                'Container returned %s for %s; expected an instance.',
                get_debug_type($resolved),
                self::class,
            ));
        }

        return $resolved;
    }
}
