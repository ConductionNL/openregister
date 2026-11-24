<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\PlatformMapperRegistrar;
use OCA\OpenRegister\Db\ConfigurationMapperRegistrar;
use OCA\OpenRegister\Db\ComplianceMapperRegistrar;
use OCA\OpenRegister\Db\FilesMapperRegistrar;
use OCA\OpenRegister\Db\IntegrationsMapperRegistrar;
use OCA\OpenRegister\Db\ProcessesMapperRegistrar;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Mcp\McpRegistrar;
use OCA\OpenRegister\Notifications\NotificationsRegistrar;
use OCA\OpenRegister\Search\SearchRegistrar;
use OCA\OpenRegister\Service\NotificationsServiceRegistrar;
use OCA\OpenRegister\Service\PlatformServiceRegistrar;
use OCA\OpenRegister\Service\ComplianceServiceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Application::register reaches every component registrar, so a component's interfaces resolve.
 *
 * @covers \OCA\OpenRegister\AppInfo\Application
 * @uses \OCA\OpenRegister\AppHost\AppHostRegistrar
 * @uses \OCA\OpenRegister\AppHost\AppHostWiring
 * @uses \OCA\OpenRegister\AppHost\FeatureToggleRegistrar
 * @uses \OCA\OpenRegister\AppHost\FeatureToggleWiring
 * @uses \OCA\OpenRegister\AppHost\ObservabilityRegistrar
 * @uses \OCA\OpenRegister\AppHost\ObservabilityWiring
 * @uses \OCA\OpenRegister\AppInfo\ClassAliasMap
 * @uses \OCA\OpenRegister\BulkAction\BulkActionRegistrar
 * @uses \OCA\OpenRegister\BulkAction\BulkActionWiring
 * @uses \OCA\OpenRegister\Capabilities\CapabilityRegistrar
 * @uses \OCA\OpenRegister\ContextChat\ContextChatRegistrar
 * @uses \OCA\OpenRegister\Controller\IntegrationControllersRegistrar
 * @uses \OCA\OpenRegister\Controller\IntegrationControllersWiring
 * @uses \OCA\OpenRegister\Db\DbRegistrar
 * @uses \OCA\OpenRegister\Db\MapperRegistrar
 * @uses \OCA\OpenRegister\Db\MapperWiring
 * @uses \OCA\OpenRegister\Db\PlatformMapperBindings
 * @uses \OCA\OpenRegister\Db\PlatformMapperRegistrar
 * @uses \OCA\OpenRegister\Db\ConfigurationMapperBindings
 * @uses \OCA\OpenRegister\Db\ConfigurationMapperRegistrar
 * @uses \OCA\OpenRegister\Db\ComplianceMapperBindings
 * @uses \OCA\OpenRegister\Db\ComplianceMapperRegistrar
 * @uses \OCA\OpenRegister\Db\FilesMapperBindings
 * @uses \OCA\OpenRegister\Db\FilesMapperRegistrar
 * @uses \OCA\OpenRegister\Db\IntegrationsMapperBindings
 * @uses \OCA\OpenRegister\Db\IntegrationsMapperRegistrar
 * @uses \OCA\OpenRegister\Db\ProcessesMapperBindings
 * @uses \OCA\OpenRegister\Db\ProcessesMapperRegistrar
 * @uses \OCA\OpenRegister\Files\FilesRegistrar
 * @uses \OCA\OpenRegister\Listener\ListenerBootstrap
 * @uses \OCA\OpenRegister\Listener\ListenerRegistrar
 * @uses \OCA\OpenRegister\Listener\Registrar\AccessListenerRegistrar
 * @uses \OCA\OpenRegister\Listener\Registrar\ConfigurationListenerRegistrar
 * @uses \OCA\OpenRegister\Listener\Registrar\FlowsListenerRegistrar
 * @uses \OCA\OpenRegister\Listener\Registrar\WorkListenerRegistrar
 * @uses \OCA\OpenRegister\Mcp\Listener\Registrar\McpListenerRegistrar
 * @uses \OCA\OpenRegister\Mcp\McpProviderDiscovery
 * @uses \OCA\OpenRegister\Mcp\McpRegistrar
 * @uses \OCA\OpenRegister\Mcp\McpWiring
 * @uses \OCA\OpenRegister\Middleware\MiddlewareRegistrar
 * @uses \OCA\OpenRegister\Middleware\MiddlewareWiring
 * @uses \OCA\OpenRegister\Notification\NotifierRegistrar
 * @uses \OCA\OpenRegister\Notifications\Listener\Registrar\NotificationsListenerRegistrar
 * @uses \OCA\OpenRegister\Notifications\Listener\Registrar\NotificationsListenerWiring
 * @uses \OCA\OpenRegister\Notifications\NotificationsRegistrar
 * @uses \OCA\OpenRegister\Notifications\NotificationsWiring
 * @uses \OCA\OpenRegister\Search\SearchProviderRegistrar
 * @uses \OCA\OpenRegister\Search\SearchRegistrar
 * @uses \OCA\OpenRegister\Search\SearchWiring
 * @uses \OCA\OpenRegister\Service\Configuration\ConfigurationServiceRegistrar
 * @uses \OCA\OpenRegister\Service\ContractBindings
 * @uses \OCA\OpenRegister\Service\ContractBindingsRegistrar
 * @uses \OCA\OpenRegister\Service\Flow\FlowRegistrar
 * @uses \OCA\OpenRegister\Service\Flow\FlowWiring
 * @uses \OCA\OpenRegister\Service\Gdpr\GdprRegistrar
 * @uses \OCA\OpenRegister\Service\Integration\IntegrationRegistrar
 * @uses \OCA\OpenRegister\Service\Integration\IntegrationWiring
 * @uses \OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistrar
 * @uses \OCA\OpenRegister\Service\ObjectSource\ObjectSourceWiring
 * @uses \OCA\OpenRegister\Service\NotificationsServiceBindings
 * @uses \OCA\OpenRegister\Service\NotificationsServiceRegistrar
 * @uses \OCA\OpenRegister\Service\PlatformServiceBindings
 * @uses \OCA\OpenRegister\Service\PlatformServiceRegistrar
 * @uses \OCA\OpenRegister\Service\ComplianceServiceRegistrar
 * @uses \OCA\OpenRegister\Service\ComplianceServiceBindings
 * @uses \OCA\OpenRegister\Service\Rbac\RbacRegistrar
 * @uses \OCA\OpenRegister\Service\Rbac\RbacWiring
 * @uses \OCA\OpenRegister\Service\ServiceBootstrap
 * @uses \OCA\OpenRegister\Service\ServiceRegistrar
 * @uses \OCA\OpenRegister\Service\WritePhase
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 */
class ComponentRegistrarWiringTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function components(): array
    {
        return [
            'files'         => [FilesRegistrar::BINDINGS],
            'mcp'           => [McpRegistrar::BINDINGS],
            'notifications' => [NotificationsRegistrar::BINDINGS],
            'notifications services' => [NotificationsServiceRegistrar::BINDINGS],
            'platform mappers' => [PlatformMapperRegistrar::BINDINGS],
            'platform services' => [PlatformServiceRegistrar::BINDINGS],
            'compliance services' => [ComplianceServiceRegistrar::BINDINGS],
            'configuration mappers' => [ConfigurationMapperRegistrar::BINDINGS],
            'compliance mappers' => [ComplianceMapperRegistrar::BINDINGS],
            'files mappers' => [FilesMapperRegistrar::BINDINGS],
            'integrations mappers' => [IntegrationsMapperRegistrar::BINDINGS],
            'processes mappers' => [ProcessesMapperRegistrar::BINDINGS],
            'search'        => [SearchRegistrar::BINDINGS],
        ];
    }//end components()

    /**
     * @param array<string, string> $bindings The component's interface to class bindings.
     */
    #[DataProvider('components')]
    public function testApplicationBindsTheComponentsInterfaces(array $bindings): void
    {
        $context = new RecordingRegistrationContext();
        (new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);

        $this->assertNotSame(expected: [], actual: $bindings);
        foreach ($bindings as $contract => $service) {
            $this->assertContains(needle: ['alias' => $contract, 'target' => $service], haystack: $context->aliases);
        }
    }//end testApplicationBindsTheComponentsInterfaces()
}//end class
