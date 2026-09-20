<?php

declare(strict_types=1);

namespace Lava\HttpClient;

use Lava\Core\Map\ApiSurface;

/** `lavaphp/http-client`'s public surface: the client, its options, and URL handling. */
final class HttpClientApiSurface extends ApiSurface
{
    public function pack(): string
    {
        return 'http-client';
    }

    public function package(): string
    {
        return 'lavaphp/http-client';
    }

    public function feature(): string
    {
        return 'http_client';
    }

    public function namespacePrefix(): string
    {
        return 'Lava\\HttpClient\\';
    }

    public function sourceRoot(): string
    {
        return __DIR__;
    }

    public function groups(): array
    {
        return [
            '(root)' => 'the client, its options, the transport and URL handling',
        ];
    }

    public function exclusions(): array
    {
        return [
            'Problem/' => 'every problem is catalogued in docs/problem-codes.md, under its own drift guard',
        ];
    }

    public function examples(): array
    {
        return [
            \Lava\HttpClient\HttpClient::class => <<<'PHP'
                use Lava\HttpClient\HttpClient;

                function rates(HttpClient $client): array
                {
                    // getJson/postJson decode for you and raise a problem when the
                    // response is not JSON; get() and post() hand back the PSR-7
                    // response for anything else.
                    return $client->getJson('https://api.example.com/rates');
                }

                function publish(HttpClient $client, array $item): array
                {
                    return $client->postJson('https://api.example.com/items', $item);
                }
                PHP,
        ];
    }
}
