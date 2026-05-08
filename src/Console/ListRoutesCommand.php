<?php

declare(strict_types=1);

namespace X402\Laravel\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use X402\Laravel\Http\Middleware\MiddlewareSpec;
use X402\Laravel\Http\Middleware\MiddlewareSpecRegistry;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;

final class ListRoutesCommand extends Command
{
    protected $signature = 'x402:list-routes';

    protected $description = 'List routes guarded by x402 middleware (amount, network, asset, kind).';

    public function handle(Router $router): int
    {
        $rows = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $row = $this->describeRoute($route);

            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            $this->info('No x402-guarded routes registered.');

            return self::SUCCESS;
        }

        $this->table(['Method', 'URI', 'Kind', 'Amount', 'Asset', 'Network', 'PayTo', 'SkipWhen'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function describeRoute(Route $route): ?array
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            $hit = $this->matchX402($middleware);

            if ($hit === null) {
                continue;
            }

            [$kind, $amount, $asset, $network, $payTo, $skip] = $hit;

            /** @var list<string> $methods */
            $methods = $route->methods();

            return [
                implode('|', $methods),
                $route->uri(),
                $kind,
                $amount,
                $asset,
                $network,
                $payTo,
                $skip,
            ];
        }

        return null;
    }

    /**
     * @return array{string, string, string, string, string, string}|null
     */
    private function matchX402(mixed $middleware): ?array
    {
        if (is_string($middleware)) {
            $aliases = [
                RequirePayment::class => 'RequirePayment',
                RequirePaymentFromBots::class => 'RequirePaymentFromBots',
                'x402' => 'RequirePayment',
                'x402.bots' => 'RequirePaymentFromBots',
            ];

            foreach ($aliases as $needle => $kind) {
                if (! str_starts_with($middleware, $needle . ':') && $middleware !== $needle) {
                    continue;
                }

                $params = $middleware === $needle ? '' : substr($middleware, strlen($needle) + 1);

                if (str_starts_with($params, 'x402-spec-')) {
                    $spec = MiddlewareSpecRegistry::resolve($params);

                    if ($spec instanceof MiddlewareSpec) {
                        return [
                            $kind,
                            $spec->amount,
                            $spec->asset,
                            $spec->network,
                            $spec->payTo ?? '(default)',
                            $spec->skipWhen instanceof Closure ? 'yes' : 'no',
                        ];
                    }
                }

                $parts = explode(',', $params);

                return [
                    $kind,
                    $parts[0] ?? '?',
                    $parts[1] ?? 'USDC',
                    $parts[2] ?? 'base',
                    '(default)',
                    'no',
                ];
            }
        }

        return null;
    }
}
