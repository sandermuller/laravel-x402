# laravel-x402

Laravel adapter for the [x402 payment protocol](https://www.x402.org/). Gate routes behind HTTP 402 stablecoin payments, or pay outbound API calls automatically via the `Http` facade.

> **Status:** scaffolding. Not yet usable.

Built on top of [`sandermuller/php-x402`](https://github.com/sandermuller/php-x402) (framework-agnostic core).

## Install

```bash
composer require sandermuller/laravel-x402
php artisan vendor:publish --tag=x402-config
```

## Server side — gate a route

```php
Route::get('/premium', PremiumController::class)
    ->middleware('x402:0.01,USDC,base');
```

## Client side — pay outbound calls

```php
$response = Http::withX402()->get('https://api.example.com/data');
```

Wallet key resolved from `config/x402.php` (default: `X402_PRIVATE_KEY` env).

## MCP support

For [`laravel/mcp`](https://github.com/laravel/mcp) integration, install [`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp).

## License

MIT.
