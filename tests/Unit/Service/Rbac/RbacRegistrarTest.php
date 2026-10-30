<?php

/**
 * RbacRegistrar registers the shared grant, hierarchy and reveal services in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\RbacRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;

class RbacRegistrarTest extends TestCase {

	/**
	 * register() registers the shared grant, hierarchy and reveal services, in this order.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		RbacRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Service\Rbac\TokenGrantSource',
				'OCA\OpenRegister\Service\Rbac\TokenGrantNarrower',
				'OCA\OpenRegister\Service\Rbac\HierarchyDescender',
				'OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander',
				'OCA\OpenRegister\Service\Rbac\ObjectGrantResolver',
				'OCA\OpenRegister\Service\Rbac\RevealCollector',
				'OCA\OpenRegister\Service\Rbac\ExplicitReveal',
			],
			actual: $context->services
		);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks for.
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		RbacRegistrar::register(context: $context);

		$doubles = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles) {
				$doubles[$id] ??= $this->createMock(originalClassName: $id);
				return $doubles[$id];
			}
		);

		foreach ($context->factories as $name => $factory) {
			$this->assertInstanceOf(expected: $name, actual: $factory($container), message: $name);
		}
	}//end testEveryFactoryBuildsItsService()
}//end class
