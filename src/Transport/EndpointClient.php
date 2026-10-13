<?php

declare(strict_types=1);

namespace Survos\MistralBundle\Transport;

use Nyholm\Psr7\Uri;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Temporary adapter until the upstream SDK accepts a full base URI. */
final readonly class EndpointClient implements ClientInterface
{
    private string $baseUri;

    public function __construct(private ClientInterface $http, string $baseUri)
    {
        $uri = new Uri($baseUri);
        if (!in_array($uri->getScheme(), ['http', 'https'], true) || '' === $uri->getHost() || '' !== $uri->getUserInfo() || '' !== $uri->getQuery() || '' !== $uri->getFragment()) {
            throw new \InvalidArgumentException('base_uri must be an HTTP(S) URL without credentials, query or fragment.');
        }
        $this->baseUri = rtrim($baseUri, '/');
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        if ('api.mistral.ai' !== $uri->getHost() || !str_starts_with($uri->getPath(), '/v1/')) {
            throw new \LogicException('Unexpected SDK request origin or API path.');
        }
        $target = (new Uri($this->baseUri.substr($uri->getPath(), 3)))->withQuery($uri->getQuery());

        return $this->http->sendRequest($request->withUri($target));
    }
}
