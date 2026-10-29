<?php

/**
 * MapperRegistrar registers the hand-built mappers in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DbContractBindings;
use OCA\OpenRegister\Db\MapperRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;

class MapperRegistrarTest extends TestCase {

	/**
	 * register() registers the hand-built mappers, in this order.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		MapperRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Db\SchemaMapper',
				'OCA\OpenRegister\Db\RegisterMapper',
				'OCA\OpenRegister\Db\WebhookMapper',
				'OCA\OpenRegister\Db\MagicMapper',
				'OCA\OpenRegister\Db\EntityRelationMapper',
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
		MapperRegistrar::register(context: $context);

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

	/**
	 * register() also aliases the Db contracts to their mappers.
	 *
	 * @return void
	 */
	public function testRegistersTheDbContractAliases(): void {
		$context = new RecordingRegistrationContext();
		MapperRegistrar::register(context: $context);

		$aliases = [];
		foreach ($context->aliases as $alias) {
			$aliases[$alias['alias']] = $alias['target'];
		}

		$this->assertSame(expected: DbContractBindings::BINDINGS, actual: $aliases);
	}//end testRegistersTheDbContractAliases()
}//end class
