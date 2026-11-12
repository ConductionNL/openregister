<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Mcp\McpRegistrar;
use OCA\OpenRegister\Notifications\NotificationsRegistrar;
use OCA\OpenRegister\Search\SearchRegistrar;
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
