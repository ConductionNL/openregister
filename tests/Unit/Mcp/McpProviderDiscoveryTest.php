<?php

/**
 * The MCP provider discovery registers McpToolsService and nothing else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCP\App\IAppManager;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
			expected: ['OCA\OpenRegister\Service\Mcp\McpToolsService'],
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
}//end class
