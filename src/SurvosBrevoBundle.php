<?php

declare(strict_types=1);

namespace Survos\BrevoBundle;

use Brevo\Brevo;
use Survos\BrevoBundle\Command\BrevoService;
use Survos\BrevoBundle\EventListener\BrevoWebhookConsumer;
use Survos\BrevoBundle\Menu\BrevoMenuSubscriber;
use Survos\BrevoBundle\Service\BrevoClientFactory;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mailer\Bridge\Brevo\Webhook\BrevoRequestParser;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\Webhook\Controller\WebhookController;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosBrevoBundle extends AbstractSurvosBundle
{
    use HasConfigurableRoutes;

    protected string $extensionAlias = 'survos_brevo';

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '/admin/brevo');
        $children
            ->scalarNode('api_key')
                ->info('Brevo API key (xkeysib-…). Empty means not configured; commands and pages say so instead of failing mid-call.')
                ->defaultValue('%env(default:survos_brevo.empty:BREVO_API_KEY)%')
            ->end()
            ->booleanNode('webhook')
                ->info('Route /webhook/brevo to the symfony/brevo-mailer parser and re-dispatch events as BrevoMailEvent. Needs symfony/webhook and symfony/brevo-mailer.')
                ->defaultTrue()
            ->end()
            ->scalarNode('admin_role')
                ->info('Role required for the admin pages and the navbar dropdown.')
                ->defaultValue('ROLE_ADMIN')
            ->end()
            ->scalarNode('base_template')
                ->info('Template the admin pages extend; it must define a "body" block.')
                ->defaultValue('base.html.twig')
            ->end()
        ->end();
    }

    private static function webhookAvailable(): bool
    {
        return class_exists(WebhookController::class)
            && class_exists(BrevoRequestParser::class)
            && interface_exists(ConsumerInterface::class);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // %env(default::X)% yields null, not ''; this is the '' fallback for unset env vars.
        $builder->setParameter('survos_brevo.empty', '');

        $configs = $builder->getExtensionConfig($this->extensionAlias);
        $webhook = array_reduce($configs, static fn (bool $carry, array $c) => $c['webhook'] ?? $carry, true);
        if (!$webhook || !self::webhookAvailable()) {
            return;
        }

        // Brevo sends the webhook with HTTP Basic auth when the URL carries user:password;
        // BREVO_WEBHOOK_SECRET is that "user:password". Empty = only Brevo's source IPs are checked.
        $builder->prependExtensionConfig('framework', [
            'webhook' => [
                'routing' => [
                    'brevo' => [
                        'service' => 'mailer.webhook.request_parser.brevo',
                        'secret' => '%env(default:survos_brevo.empty:BREVO_WEBHOOK_SECRET)%',
                    ],
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Scans src/Command and src/Controller, and wires the configurable routes.
        parent::loadExtension($config, $container, $builder);

        $builder->setParameter('survos_brevo.admin_role', $config['admin_role']);
        $builder->setParameter('survos_brevo.base_template', $config['base_template']);

        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set('survos_brevo.client', Brevo::class)
            ->factory([BrevoClientFactory::class, 'create'])
            ->args([service('http_client'), $config['api_key']]);
        $services->alias(Brevo::class, 'survos_brevo.client');

        $services->set(BrevoService::class)
            ->arg('$brevo', service('survos_brevo.client'))
            ->arg('$apiKey', $config['api_key'])
            ->public();

        if ($config['webhook'] && self::webhookAvailable()) {
            $services->set(BrevoWebhookConsumer::class);
        }

        // The dropdown links to the admin routes, so it only exists when they do.
        if ($config['routes_enabled'] && trait_exists(MenuBuilderTrait::class)) {
            $services->set(BrevoMenuSubscriber::class);
        }
    }
}
