<?php

/**
 * MapperRegistrar registers the hand-built mappers in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

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
	 * Every factory asks the container for the same services, in the same order.
	 *
	 * @return void
	 */
	public function testEveryFactoryAsksForItsServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		MapperRegistrar::register(context: $context);

		$asked = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked) {
				$asked[] = $id;
				return $this->createMock(originalClassName: $id);
			}
		);

		$actual = [];
		foreach ($context->factories as $name => $factory) {
			$asked = [];
			$factory($container);
			$actual[$name] = $asked;
		}

		$fixture = __DIR__ . '/fixtures/mapper-registrar-wiring.json';
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode((string) file_get_contents($fixture), true), actual: $actual);
	}//end testEveryFactoryAsksForItsServicesInOrder()
}//end class
