<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kommandhub\ChowdeckRelaySW\Flow\FlowInstaller;
use Kommandhub\ChowdeckRelaySW\Flow\RequestRiderAction;
use Kommandhub\ChowdeckRelaySW\Shipping\Installer\ShippingMethodInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Main Shopware plugin class for Chowdeck Relay Rider Delivery.
 *
 * Responsibilities:
 * - Extends the DI container with this plugin's package configuration.
 * - Manages the install / update / activate / deactivate / uninstall lifecycle.
 *
 * No executeComposerCommands() override: returning true makes plugin:uninstall
 * run `composer remove` against the host project's root composer.json. Only add
 * it if the plugin ships third-party composer dependencies of its own.
 *
 * Keep this class thin: it is lifecycle glue that can only run with a booted
 * kernel, so it is excluded from the no-kernel coverage gate (see
 * phpunit.dist.xml) and is covered by the @group kernel integration tests.
 */
class KmhChowdeckRelaySW extends Plugin
{
    /**
     * Load additional service configuration files.
     *
     * Shopware loads Resources/config/services.yml on its own; this adds the
     * Resources/config/packages/*.yaml bundle configuration (the monolog
     * channel) on top.
     *
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $locator = new FileLocator('Resources/config');

        $resolver = new LoaderResolver([
            new YamlFileLoader($container, $locator),
            new GlobFileLoader($container, $locator),
            new DirectoryLoader($container, $locator),
        ]);

        $loader = new DelegatingLoader($resolver);

        $configPath = rtrim($this->getPath(), '/') . '/Resources/config';

        $loader->load($configPath . '/{packages}/*.yaml', 'glob');
    }

    /**
     * Plugin installation lifecycle hook.
     */
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $this->runInstallers($installContext->getContext());
    }

    /**
     * Plugin update lifecycle hook.
     *
     * Re-runs the installers; the shipping method installer only creates the
     * method when it is missing, so merchant changes survive an update.
     */
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->runInstallers($updateContext->getContext());
    }

    /**
     * Plugin activation lifecycle hook.
     */
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        $this->runInstallers($activateContext->getContext());
    }

    /**
     * Plugin deactivation lifecycle hook. Switches the shipping method off so it
     * is no longer selectable while the plugin is off (never deleted).
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->shippingMethodInstaller()->setActive(false, $deactivateContext->getContext());

        parent::deactivate($deactivateContext);
    }

    /**
     * Plugin uninstall lifecycle hook.
     *
     * The shipping method is never deleted — that would break historical
     * orders; it is only switched off. User data is only removed when the
     * merchant did not ask to keep it.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        $this->shippingMethodInstaller()->setActive(false, $uninstallContext->getContext());

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->requireContainer()->get(Connection::class);

        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Invalid database connection service.'); // @codeCoverageIgnore
        }

        // List every table the plugin's migrations create, children first.
        $connection->executeStatement('DROP TABLE IF EXISTS `kmh_chowdeck_webhook_event`');
        $connection->executeStatement('DROP TABLE IF EXISTS `kmh_chowdeck_delivery`');
        // The plugin's flows, and any flow using its action or events, would
        // point at an action or trigger that no longer exists.
        $connection->executeStatement(
            'DELETE FROM `flow` WHERE `event_name` LIKE :events OR `id` IN (SELECT `flow_id` FROM (SELECT DISTINCT `flow_id` FROM `flow_sequence` WHERE `action_name` = :action) AS `flows`)',
            ['events' => 'kmh_chowdeck.%', 'action' => RequestRiderAction::ACTION_NAME],
        );
        $connection->executeStatement(
            'DELETE FROM `flow` WHERE `id` IN (:ids)',
            ['ids' => array_map(Uuid::fromHexToBytes(...), FlowInstaller::flowIds())],
            ['ids' => ArrayParameterType::BINARY],
        );
        $mailTypes = ['types' => FlowInstaller::MAIL_TYPES];
        $connection->executeStatement(
            'DELETE `mail_template` FROM `mail_template` JOIN `mail_template_type` ON `mail_template_type`.`id` = `mail_template`.`mail_template_type_id` WHERE `mail_template_type`.`technical_name` IN (:types)',
            $mailTypes,
            ['types' => ArrayParameterType::STRING],
        );
        $connection->executeStatement('DELETE FROM `mail_template_type` WHERE `technical_name` IN (:types)', $mailTypes, ['types' => ArrayParameterType::STRING]);
        // Without this the migrations stay marked as executed, so a reinstall
        // skips them and never recreates the tables.
        $this->removeMigrations();
    }

    /**
     * Runs every installer that must be idempotent across install and update.
     */
    private function runInstallers(Context $context): void
    {
        $this->shippingMethodInstaller()->install($context);

        $container = $this->requireContainer();
        /** @var EntityRepository<\Shopware\Core\Content\Flow\FlowCollection> $flowRepository */
        $flowRepository = $container->get('flow.repository');
        /** @var EntityRepository<\Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeCollection> $mailTemplateTypeRepository */
        $mailTemplateTypeRepository = $container->get('mail_template_type.repository');
        /** @var EntityRepository<\Shopware\Core\Content\MailTemplate\MailTemplateCollection> $mailTemplateRepository */
        $mailTemplateRepository = $container->get('mail_template.repository');
        (new FlowInstaller($flowRepository, $mailTemplateTypeRepository, $mailTemplateRepository))->install($context);
    }

    /**
     * The plugin bootstrap runs without the plugin's own DI wiring, so the
     * installer is composed from core repositories here.
     */
    private function shippingMethodInstaller(): ShippingMethodInstaller
    {
        $container = $this->requireContainer();

        /** @var EntityRepository<\Shopware\Core\Checkout\Shipping\ShippingMethodCollection> $shippingMethodRepository */
        $shippingMethodRepository = $container->get('shipping_method.repository');
        /** @var EntityRepository<\Shopware\Core\System\DeliveryTime\DeliveryTimeCollection> $deliveryTimeRepository */
        $deliveryTimeRepository = $container->get('delivery_time.repository');
        /** @var EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $salesChannelRepository */
        $salesChannelRepository = $container->get('sales_channel.repository');

        return new ShippingMethodInstaller($shippingMethodRepository, $deliveryTimeRepository, $salesChannelRepository);
    }

    /**
     * The plugin container is null outside a booted kernel; every lifecycle
     * hook that reaches for a service must fail loudly rather than on a null
     * dereference three frames later.
     */
    private function requireContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container is not available.'); // @codeCoverageIgnore
        }

        return $this->container;
    }
}
