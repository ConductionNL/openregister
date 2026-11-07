<?php

/**
 * Golden characterization test for SystemEntityObjectAdapter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\SystemEntityObjectAdapter;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the adapter reads from entities with and without uuid, title,
 * name and jsonSerialize(), including the odd values (empty title, int uuid,
 * non-string title, non-array serialization).
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\SystemEntityObjectAdapter
 */
final class SystemEntityObjectAdapterGoldenTest extends TestCase {

	/**
	 * The adapter's visible state for an entity.
	 *
	 * @param Entity $entity The system entity.
	 *
	 * @return array<string, mixed>
	 */
	private function state(Entity $entity): array {
		$adapter = new SystemEntityObjectAdapter($entity, 'system-slug');
		return [
			'schema' => $adapter->getSchema(),
			'register' => $adapter->getRegister(),
			'uuid' => $adapter->getUuid(),
			'name' => $adapter->getName(),
			'object' => (new \ReflectionProperty($adapter, 'object'))->getValue($adapter),
		];
	}//end state()

	/**
	 * Uuid, title wins over name, serialized payload.
	 *
	 * @return void
	 */
	public function testFullEntity(): void {
		$entity = new class extends Entity implements \JsonSerializable {
			protected $uuid = 'u-1';
			protected $title = 'The title';
			protected $name = 'The name';

			public function jsonSerialize(): array {
				return ['a' => 1, 'b' => ['c' => 'd']];
			}
		};

		$this->assertSame(
			['schema' => 'system-slug', 'register' => null, 'uuid' => 'u-1', 'name' => 'The title', 'object' => ['a' => 1, 'b' => ['c' => 'd']]],
			$this->state($entity)
		);
	}//end testFullEntity()

	/**
	 * A null title falls back to the name; an int uuid is cast.
	 *
	 * @return void
	 */
	public function testNullTitleFallsBackToNameAndIntUuidIsCast(): void {
		$entity = new class extends Entity {
			protected $uuid = 7;
			protected $title = null;
			protected $name = 'Only name';
		};

		$this->assertSame(
			['schema' => 'system-slug', 'register' => null, 'uuid' => '7', 'name' => 'Only name', 'object' => []],
			$this->state($entity)
		);
	}//end testNullTitleFallsBackToNameAndIntUuidIsCast()

	/**
	 * Without a title property the name is used.
	 *
	 * @return void
	 */
	public function testMissingTitleUsesName(): void {
		$entity = new class extends Entity {
			protected $uuid = 'u-3';
			protected $name = 'Named';
		};

		$this->assertSame('Named', $this->state($entity)['name']);
		$this->assertSame('u-3', $this->state($entity)['uuid']);
	}//end testMissingTitleUsesName()

	/**
	 * An empty-string title is not null, so the name is not tried and no name is set.
	 *
	 * @return void
	 */
	public function testEmptyTitleBlocksName(): void {
		$entity = new class extends Entity {
			protected $uuid = 'u-4';
			protected $title = '';
			protected $name = 'Ignored';
		};

		$this->assertSame(
			['schema' => 'system-slug', 'register' => null, 'uuid' => 'u-4', 'name' => null, 'object' => []],
			$this->state($entity)
		);
	}//end testEmptyTitleBlocksName()

	/**
	 * A non-string title sets no name; a non-array serialization sets no payload.
	 *
	 * @return void
	 */
	public function testNonStringTitleAndNonArraySerialization(): void {
		$entity = new class extends Entity implements \JsonSerializable {
			protected $uuid = null;
			protected $title = 42;
			protected $name = 'Ignored';

			public function jsonSerialize(): string {
				return 'scalar';
			}
		};

		$this->assertSame(
			['schema' => 'system-slug', 'register' => null, 'uuid' => null, 'name' => null, 'object' => []],
			$this->state($entity)
		);
	}//end testNonStringTitleAndNonArraySerialization()

	/**
	 * An entity with none of the fields leaves every field at its default.
	 *
	 * @return void
	 */
	public function testBareEntity(): void {
		$entity = new class extends Entity implements \JsonSerializable {
			protected $other = 'x';

			public function jsonSerialize(): array {
				return ['other' => 'x'];
			}
		};

		$this->assertSame(
			['schema' => 'system-slug', 'register' => null, 'uuid' => null, 'name' => null, 'object' => ['other' => 'x']],
			$this->state($entity)
		);
	}//end testBareEntity()
}//end class
