<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SaveObject::cascadeMultipleObjects creates the nested objects of an array property in the right register,
 * each naming its parent once.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\NestedObjectTarget;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;

/**
 * Records every nested save of cascadeMultipleObjects as [register, schema, data].
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RegisterReferenceLookup
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SaveObjectCascadeMultipleTest extends TestCase {

	/**
	 * Every call to the nested save, as [register, schema, data].
	 *
	 * @var array<int, array{0: mixed, 1: mixed, 2: array}>
	 */
	private array $saves = [];

	/**
	 * Cascade an array property of parent `p-1` (register 10) whose items are `note` objects.
	 *
	 * @param array $property The property definition.
	 * @param array $propData The property value.
	 *
	 * @return array The uuids the cascade returns.
	 */
	private function cascade(array $property, array $propData): array {
		$handler = $this->getMockBuilder(SaveObject::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject'])
			->getMock();
		$handler->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data): ObjectEntity {
				$this->saves[] = [$register, $schema, $data];
				$created = new ObjectEntity();
				$created->setUuid('created-' . count($this->saves));
				return $created;
			}
		);

		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');

		$reflection = new ReflectionClass(SaveObject::class);
		SaveLookupsAccess::set($handler, 'schemaReferenceCache', ['note' => '2']);
		SaveLookupsAccess::set($handler, 'schemaCache', ['2' => $note]);
		$reflection->getProperty('urlGenerator')->setValue($handler, $this->createMock(IURLGenerator::class));
		$reflection->getProperty('permissionHandler')->setValue($handler, $this->createMock(PermissionHandler::class));

		$parent = new ObjectEntity();
		$parent->setUuid('p-1');
		$parent->setRegister('10');

		return $reflection->getMethod('cascadeMultipleObjects')->invoke($handler, new NestedObjectTarget(parent: $parent, definition: $property), $propData);
	}//end cascade()

	public function testPropertyLevelRefInverseAndRegisterReachTheItems(): void {
		$created = $this->cascade(
			property: ['type' => 'array', '$ref' => 'note', 'inversedBy' => 'incident', 'register' => ['id' => 77]],
			propData: [
				['text' => 'a', 'incident' => ['x']],
				['text' => 'b', 'incident' => ['p-1']],
				['text' => 'c', 'incident' => 'other'],
				'not-an-id',
				'11111111-1111-1111-1111-111111111111',
				[],
				['id' => ''],
			]
		);

		$this->assertSame(['created-1', 'created-2', 'created-3'], $created);
		$this->assertSame(
			[
				[77, '2', ['text' => 'a', 'incident' => ['x', 'p-1']]],
				[77, '2', ['text' => 'b', 'incident' => ['p-1']]],
				[77, '2', ['text' => 'c', 'incident' => 'p-1']],
			],
			$this->saves
		);
	}

	public function testItemsWithoutARegisterAreSavedInTheParentsRegister(): void {
		$created = $this->cascade(
			property: ['type' => 'array', 'items' => ['$ref' => 'note', 'register' => 'reg-slug']],
			propData: [['text' => 'a', '@self' => ['name' => 'dropped']]]
		);
		$this->cascade(
			property: ['type' => 'array', 'items' => ['$ref' => 'note']],
			propData: [['text' => 'b']]
		);

		$this->assertSame(['created-1'], $created);
		$this->assertSame([['reg-slug', '2', ['text' => 'a']], ['10', '2', ['text' => 'b']]], $this->saves);
	}

	public function testAPropertyNamingNoSchemaOrHoldingOnlyIdsCreatesNothing(): void {
		$this->assertSame([], $this->cascade(property: ['type' => 'array', 'items' => []], propData: [['text' => 'a']]));
		$this->assertSame([], $this->cascade(property: ['$ref' => 'note'], propData: ['11111111-1111-1111-1111-111111111111']));
		$this->assertSame([], $this->cascade(property: ['$ref' => 'note'], propData: ['k' => ['text' => 'a']]));
		$this->assertSame([], $this->saves);
	}
}//end class
