<?php

/**
 * CapabilityRegistrar registers the integrations capability service and both capabilities.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\Capabilities\CapabilityRegistrar;
use OCA\OpenRegister\Capabilities\IntegrationsCapability;
use OCA\OpenRegister\Capabilities\UrnCapability;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;

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

		$this->assertSame(expected: [UrnCapability::class, IntegrationsCapability::class], actual: $capabilities);
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
}//end class
