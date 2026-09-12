<?php
declare(strict_types=1);

namespace Survos\MistralBundle;

use Survos\Kit\{AbstractSurvosBundle, SurvosKitBundle};
use Survos\MistralBundle\Client\{ClientFactory, MistralClient};
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosMistralBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('default_client')->defaultValue('default')->end()
            ->arrayNode('clients')->useAttributeAsKey('name')->arrayPrototype()->children()
                ->scalarNode('api_key')->isRequired()->end()
                ->scalarNode('base_uri')->defaultValue('https://api.mistral.ai/v1')->end()
                ->floatNode('timeout')->defaultValue(120)->min(0.1)->end()
                ->scalarNode('cache_pool')->defaultNull()->end()
                ->integerNode('metadata_ttl')->defaultValue(60)->min(0)->end()
            ->end()->end()->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        if (!isset($config['clients'][$config['default_client']])) {
            throw new \InvalidArgumentException('survos_mistral.default_client must name a configured client.');
        }
        $services = $container->services();
        $services->set(ClientFactory::class)->arg('$http', service('http_client'));
        foreach ($config['clients'] as $name => $options) {
            $id = 'survos_mistral.client.'.$name;
            $services->set($id, MistralClient::class)
                ->factory([service(ClientFactory::class), 'create'])
                ->args([$name, $options['api_key'], $options['base_uri'], $options['timeout'],
                    $options['cache_pool'] === null ? null : service($options['cache_pool']), $options['metadata_ttl']]);
        }
        $services->alias(MistralClient::class, 'survos_mistral.client.'.$config['default_client']);
    }
}
