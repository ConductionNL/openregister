<?php

/**
 * CapabilityRegistrar registers the integrations capability service and both capabilities.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\Capabilities\AuthorizationCapability;
use OCA\OpenRegister\Capabilities\CapabilityRegistrar;
use OCA\OpenRegister\Capabilities\IntegrationsCapability;
use OCA\OpenRegister\Capabilities\IntegrationsCapabilityServices;
use OCA\OpenRegister\Capabilities\PlatformCapability;
use OCA\OpenRegister\Capabilities\SearchCapability;
use OCA\OpenRegister\Capabilities\SearchScopesCapability;
use OCA\OpenRegister\Capabilities\UrnCapability;
use OCA\OpenRegister\Kernel\Contracts\SemanticProvidersInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class CapabilityRegistrarTest extends TestCase {

	/**
	 * register() registers the URN capability first, then the integrations
	 * capability, and builds the integrations capability from the container.
	 *
	 * @return void
	 */
	public function testRegistersTheCapabilitiesInOrder(): void {
		$factories = [];
		$capabilities = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);
		$context->method('registerCapability')->willReturnCallback(
			static function (string $capability) use (&$capabilities): void {
				$capabilities[] = $capability;
			}
		);

		CapabilityRegistrar::register(context: $context);

		$this->assertSame(expected: [UrnCapability::class, IntegrationsCapability::class, PlatformCapability::class, SearchScopesCapability::class, AuthorizationCapability::class, SearchCapability::class], actual: $capabilities);
		$this->assertSame(expected: [IntegrationsCapability::class], actual: array_keys($factories));

		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $this->createMock(originalClassName: $id)
		);
		$this->assertInstanceOf(
			expected: IntegrationsCapability::class,
			actual: $factories[IntegrationsCapability::class]($container)
		);
	}//end testRegistersTheCapabilitiesInOrder()

	/**
	 * The integrations capability is built with the container's semantic provider port (the Core catalogue),
	 * so `semanticProviders` advertises the cached list rather than always [].
	 *
	 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
	 */
	public function testTheIntegrationsCapabilityReadsTheSemanticProviderCatalogue(): void {
		$factories = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);
		CapabilityRegistrar::register(context: $context);

		$catalogue = $this->createMock(originalClassName: SemanticProvidersInterface::class);
		$requested = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($catalogue, &$requested) {
				$requested[] = $id;
				return $id === SemanticProvidersInterface::class ? $catalogue : $this->createMock(originalClassName: $id);
			}
		);

		$capability = $factories[IntegrationsCapability::class]($container);
		$services   = (new ReflectionProperty(IntegrationsCapability::class, 'services'))->getValue($capability);

		$this->assertContains(needle: SemanticProvidersInterface::class, haystack: $requested);
		$this->assertInstanceOf(expected: IntegrationsCapabilityServices::class, actual: $services);
		$this->assertSame(expected: $catalogue, actual: $services->semanticProviders);
	}//end testTheIntegrationsCapabilityReadsTheSemanticProviderCatalogue()
}//end class
