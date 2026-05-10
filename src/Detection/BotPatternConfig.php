<?php

declare(strict_types=1);

namespace X402\Laravel\Detection;

use Illuminate\Contracts\Config\Repository;
use X402\Laravel\Support\ConfigReader;

/**
 * Normalised view of `x402.bots.*` config. Preserves the upstream
 * `X402\Server\BotDetector` semantic: `patterns === null` means "use the
 * upstream curated default list" while an explicit array (even empty)
 * is treated as a full override.
 *
 * @internal
 */
final readonly class BotPatternConfig
{
    /**
     * @param  ?list<string>  $patterns  null = upstream defaults
     * @param  list<string>  $extra      always appended to the active list
     */
    public function __construct(
        public ?array $patterns,
        public array $extra,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        return new self(
            patterns: ConfigReader::stringListOrNull($config, 'x402.bots.patterns'),
            extra: ConfigReader::stringListOrNull($config, 'x402.bots.extra_patterns') ?? [],
        );
    }
}
