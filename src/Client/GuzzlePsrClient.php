<?php

declare(strict_types=1);

namespace X402\Laravel\Client;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Default PSR-18 client implementation. Guzzle ships PSR-18 support natively
 * since v7; this thin wrapper keeps the binding explicit so hosts can swap
 * it for a different transport (e.g. Symfony HttpClient) without rebinding
 * Guzzle itself.
 */
final class GuzzlePsrClient implements ClientInterface
{
    public function __construct(private readonly Client $client = new Client) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }
}
