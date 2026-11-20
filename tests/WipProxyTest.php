<?php

declare(strict_types=1);

namespace Survos\MistralBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\MistralBundle\Client\ClientFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WipProxyTest extends TestCase
{
    /** @return array<string, mixed> options of the first request */
    private function optionsFor(string $baseUri): array
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options;

            return new MockResponse('{"object":"list","data":[]}', ['response_headers' => ['content-type' => 'application/json']]);
        });
        (new ClientFactory($http))->create('t', 'key', $baseUri)->files();

        return $seen;
    }

    public function testWipHostGoesThroughTheLocalProxy(): void
    {
        $this->assertSame('http://127.0.0.1:7080', $this->optionsFor('https://mediary.wip/v1')['proxy'] ?? null);
    }

    public function testOtherHostsAreLeftAlone(): void
    {
        $this->assertArrayNotHasKey('proxy', array_filter($this->optionsFor('https://api.mistral.ai/v1')));
    }
}
