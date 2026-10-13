<?php

declare(strict_types=1);

namespace Survos\MistralBundle\Client;

use Nyholm\Psr7\Factory\Psr17Factory;
use SoftCreatR\MistralAI\MistralAI;
use Survos\MistralBundle\Transport\EndpointClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ClientFactory
{
    public function __construct(private HttpClientInterface $http)
    {
    }

    public function create(string $name, #[\SensitiveParameter] string $apiKey, string $baseUri = 'https://api.mistral.ai/v1', float $timeout = 120, ?CacheInterface $cache = null, int $metadataTtl = 60): MistralClient
    {
        $http = $this->http->withOptions(['timeout' => $timeout, 'max_redirects' => 0]);
        $factory = new Psr17Factory();
        $transport = new EndpointClient(new Psr18Client($http, $factory, $factory), $baseUri);
        $sdk = new MistralAI($factory, $factory, $factory, $transport, $apiKey);

        return new MistralClient($name, $sdk, $http, $baseUri, $apiKey, $cache, $metadataTtl);
    }
}
