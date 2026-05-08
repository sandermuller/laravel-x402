<?php

declare(strict_types=1);

namespace X402\Laravel\Detection;

/**
 * Matches User-Agent strings against a list of known AI agents, assistants,
 * scrapers, and search crawlers. Used by the `x402.bots` middleware to gate
 * routes for bots while leaving humans untouched.
 *
 * Default pattern list curated from https://knownagents.com.
 */
final readonly class BotDetector
{
    /**
     * @var list<string>
     */
    public const DEFAULT_PATTERNS = [
        // Agents
        'AmazonBuyForMe',
        'ChatGPT Agent',
        'GoogleAgent-Mariner',
        'Manus-User',
        'NovaAct',
        'TwinAgent',

        // Assistants
        'AI2Bot-DeepResearchEval',
        'Amzn-User',
        'ChatGPT-User',
        'Claude-User',
        'Devin',
        'DuckAssistBot',
        'Gemini-Deep-Research',
        'Google-NotebookLM',
        'kagi-fetcher',
        'KlaviyoAIBot',
        'LinerBot',
        'meta-externalfetcher',
        'MistralAI-User',
        'Perplexity-User',
        'PhindBot',
        'Poggio-Citations',
        'QualifiedBot',

        // Data scrapers
        'Ai2Bot-Dolma',
        'Amazonbot',
        'Applebot-Extended',
        'Bytespider',
        'CCBot',
        'ChatGLM-Spider',
        'ClaudeBot',
        'CloudVertexBot',
        'cohere-training-data-crawler',
        'Diffbot',
        'FacebookBot',
        'Google-Extended',
        'GoogleOther',
        'GPTBot',
        'ICC-Crawler',
        'Kangaroo Bot',
        'meta-externalagent',
        'PanguBot',
        'SBIntuitionsBot',
        'Timpibot',
        'VelenPublicWebCrawler',

        // Search crawlers
        'Amzn-SearchBot',
        'AzureAI-SearchBot',
        'Bravebot',
        'Claude-SearchBot',
        'Cloudflare-AutoRAG',
        'ExaBot',
        'Google-CloudVertexBot',
        'OAI-SearchBot',
        'PerplexityBot',
        'PetalBot',
        'YouBot',

        // Undocumented
        'anthropic-ai',
        'ApifyBot',
        'Claude-Web',
        'cohere-ai',
        'Crawl4AI',
        'DeepSeekBot',
        'iAskBot',
        'iaskspider',
        'KunatoCrawler',
        'TavilyBot',
        'WRTNBot',
    ];

    /**
     * @var list<string>
     */
    private array $patterns;

    /**
     * @param  list<string>|null  $patterns  Override the built-in list. Pass null to use defaults.
     * @param  list<string>  $extra  Additional patterns merged on top of the active list.
     */
    public function __construct(?array $patterns = null, array $extra = [])
    {
        $base = $patterns ?? self::DEFAULT_PATTERNS;
        $this->patterns = array_values(array_unique([...$base, ...$extra]));
    }

    public function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            if ($pattern !== '' && stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }
}
