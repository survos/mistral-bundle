<?php
declare(strict_types=1);

namespace Survos\MistralBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\MistralBundle\SurvosMistralBundle;
use Survos\MistralBundle\Client\MistralClient;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;

final class BundleTest extends TestCase
{
    public function testNamedClientsCompileAndResolve(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->register('http_client', MockHttpClient::class);
        $bundle = new SurvosMistralBundle();
        $extension = $bundle->getContainerExtension();
        $extension->load([['default_client' => 'local', 'clients' => [
            'local' => ['api_key' => '', 'base_uri' => 'http://127.0.0.1:5001/v1'],
            'cloud' => ['api_key' => 'test'],
        ]]], $container);
        $container->getAlias(MistralClient::class)->setPublic(true);
        $container->getDefinition('survos_mistral.client.cloud')->setPublic(true);
        $container->compile();
        self::assertSame('local', $container->get(MistralClient::class)->name);
        self::assertSame('cloud', $container->get('survos_mistral.client.cloud')->name);
    }
}
