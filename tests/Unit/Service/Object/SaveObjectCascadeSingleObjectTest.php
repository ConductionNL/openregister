<?php

/**
 * What SaveObject::cascadeSingleObject hands the create check and the nested
 * save, and what it records for the parent's response.
 *
 * Pins the register id the create-rights check receives (definition register,
 * parent register, an `{id}` register, a non-scalar register), the exact
 * arguments of the nested save, and the created sub-object being tracked only
 * when the save returned a uuid.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\NestedObjectTarget;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use TypeError;

/**
 * cascadeSingleObject's hand-offs, without a database.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 */
final class SaveObjectCascadeSingleObjectTest extends TestCase {

	/**
	 * Every create check, as [schema id, register].
	 *
	 * @var array<int, array{0: int|null, 1: string|null}>
	 */
	private array $checks = [];

	/**
	 * Every nested save, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * A save handler whose nested save and create check only record.
	 *
	 * @param string|null $createdUuid The uuid the nested save answers with.
	 *
	 * @return SaveObject The handler.
	 */
	private function handler(?string $createdUuid): SaveObject {
		$handler = $this->getMockBuilder(SaveObject::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject', 'assertMayCascadeCreate'])
			->getMock();
		$handler->method('assertMayCascadeCreate')->willReturnCallback(
			function (Schema $schema, ?string $register = null): void {
				$this->checks[] = [$schema->getId(), $register];
			}
		);
		$handler->method('saveObject')->willReturnCallback(
			function (...$args) use ($createdUuid): ObjectEntity {
				$this->saves[] = $args;
				$created = new ObjectEntity();
				$created->setUuid($createdUuid);
				$created->setObject(['text' => 'saved']);

				return $created;
			}
		);

		$note = new Schema();
		$note->setId(2);
		$note->setUuid('note-schema');
		$note->setSlug('note');
		$reflection = new ReflectionClass(SaveObject::class);
		SaveLookupsAccess::set($handler, 'schemaReferenceCache', ['note' => '2']);
		SaveLookupsAccess::set($handler, 'schemaCache', ['2' => $note]);
		$reflection->getProperty('urlGenerator')->setValue($handler, $this->createMock(IURLGenerator::class));
		$reflection->getProperty('logger')->setValue($handler, new NullLogger());

		return $handler;
	}//end handler()

	/**
	 * Cascade one object under parent `p-1` in register `10`.
	 *
	 * @param SaveObject $handler    The handler.
	 * @param array      $definition The property definition.
	 *
	 * @return string|null What cascadeSingleObject answers.
	 */
	private static function cascade(SaveObject $handler, array $definition): ?string {
		$parent = new ObjectEntity();
		$parent->setUuid('p-1');
		$parent->setRegister('10');

		return (new ReflectionClass(SaveObject::class))->getMethod('cascadeSingleObject')
			->invoke($handler, new NestedObjectTarget(parent: $parent, definition: $definition), ['text' => 'new']);
	}//end cascade()

	/**
	 * The create check receives the register as a string, or null when it is not a scalar.
	 *
	 * @return void
	 */
	public function testTheCreateCheckReceivesTheRegisterId(): void {
		$cases = [
			'parent register' => [['$ref' => 'note'], '10'],
			'definition register' => [['$ref' => 'note', 'register' => 5], '5'],
			'register object with id' => [['$ref' => 'note', 'register' => ['id' => 7]], '7'],
			'register object without id' => [['$ref' => 'note', 'register' => ['slug' => 'r']], null],
		];
		foreach ($cases as $name => [$definition, $expected]) {
			$this->checks = [];
			try {
				self::cascade($this->handler(createdUuid: 'c-1'), $definition);
			} catch (TypeError $error) {
				// A register the save cannot take is refused there, after the check.
				$this->assertNull($expected, $name);
			}

			$this->assertSame([[2, $expected]], $this->checks, $name);
		}
	}//end testTheCreateCheckReceivesTheRegisterId()

	/**
	 * The nested save gets the register, the resolved schema id, the stripped data and fixed
	 * flags (uuid null, rbac, multitenancy, persist, silent).
	 *
	 * @return void
	 */
	public function testTheNestedSaveArgumentsAreFixed(): void {
		self::cascade($this->handler(createdUuid: 'c-1'), ['$ref' => 'note', 'register' => 5]);

		$this->assertSame(
			[5, '2', ['text' => 'new'], null, true, true, true, true],
			array_slice($this->saves[0], 0, 8)
		);
		$this->assertCount(1, $this->saves);
	}//end testTheNestedSaveArgumentsAreFixed()

	/**
	 * A created sub-object is tracked under its uuid; a save without a uuid tracks nothing.
	 *
	 * @return void
	 */
	public function testACreatedSubObjectIsTrackedOnlyWithAUuid(): void {
		$handler = $this->handler(createdUuid: 'c-1');
		$this->assertSame('c-1', self::cascade($handler, ['$ref' => 'note']));
		$this->assertSame(['c-1'], array_keys($handler->getCreatedSubObjects()));
		$this->assertSame('saved', $handler->getCreatedSubObjects()['c-1']['text'] ?? null);

		$handler = $this->handler(createdUuid: null);
		$this->assertNull(self::cascade($handler, ['$ref' => 'note']));
		$this->assertSame([], $handler->getCreatedSubObjects());
	}//end testACreatedSubObjectIsTrackedOnlyWithAUuid()
}//end class
