<?php

/**
 * FeatureToggleRegistrar registers the shared feature-toggle reader in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\FeatureToggleRegistrar;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;

class FeatureToggleRegistrarTest extends TestCase {

	/**
	 * register() registers the shared feature-toggle reader, in this order.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		FeatureToggleRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\AppHost\Service\FeatureToggleService',
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
		FeatureToggleRegistrar::register(context: $context);

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
