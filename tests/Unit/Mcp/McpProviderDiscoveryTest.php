<?php

/**
 * The MCP provider discovery registers McpToolsService and nothing else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Kernel\Contracts\Integrations\BuiltInToolProvidersInterface;
use OCA\OpenRegister\Mcp\BuiltIn\IntegrationsToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\ObjectsToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\RegistersToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use OCP\App\IAppManager;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;

class McpProviderDiscoveryTest extends TestCase {

	/**
	 * register() registers one service, McpToolsService, and no listeners.
	 *
	 * @return void
	 */
	public function testRegistersMcpToolsServiceOnly(): void {
		$context = new RecordingRegistrationContext();
		(new McpProviderDiscovery())->register(context: $context);

		$this->assertSame(
			expected: ['OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService'],
			actual: $context->services
		);
		$this->assertCount(expectedCount: 0, haystack: $context->listeners);
	}//end testRegistersMcpToolsServiceOnly()

	/**
	 * The factory builds McpToolsService when every collaborator is a test double
	 * and no app is installed.
	 *
	 * @return void
	 */
	public function testTheFactoryBuildsMcpToolsService(): void {
		$context = new RecordingRegistrationContext();
		(new McpProviderDiscovery())->register(context: $context);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn([]);

		$doubles   = [IAppManager::class => $appManager];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles) {
				if (isset($doubles[$id]) === false) {
					$class        = new ReflectionClass(objectOrClass: $id);
					$doubles[$id] = $class->isFinal() === true ? $class->newInstanceWithoutConstructor() : $this->createMock(originalClassName: $id);
				}

				return $doubles[$id];
			}
		);

		$service = ($context->factories[McpToolsService::class])($container);

		$this->assertInstanceOf(expected: McpToolsService::class, actual: $service);
	}//end testTheFactoryBuildsMcpToolsService()
	/**
	 * The built-in providers are Integrations' own four, then the contributed ones in their order.
	 *
	 * @return void
	 */
	public function testTheContributedProvidersFollowTheOwnBuiltIns(): void {
		$own = [
			RegistersToolProvider::class    => $this->createMock(originalClassName: IMcpToolProvider::class),
			SchemasToolProvider::class      => $this->createMock(originalClassName: IMcpToolProvider::class),
			ObjectsToolProvider::class      => $this->createMock(originalClassName: IMcpToolProvider::class),
			IntegrationsToolProvider::class => $this->createMock(originalClassName: IMcpToolProvider::class),
		];
		$first  = $this->createMock(originalClassName: IMcpToolProvider::class);
		$second = $this->createMock(originalClassName: IMcpToolProvider::class);

		$contributed = $this->createMock(originalClassName: BuiltInToolProvidersInterface::class);
		$contributed->method('providers')->willReturn([$first, $second]);

		$services  = $own + [BuiltInToolProvidersInterface::class => $contributed];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		$providers = (new ReflectionMethod(McpProviderDiscovery::class, 'builtInProviders'))
			->invoke(new McpProviderDiscovery(), $container);

		$this->assertSame(expected: [...array_values($own), $first, $second], actual: $providers);
	}//end testTheContributedProvidersFollowTheOwnBuiltIns()
}//end class
