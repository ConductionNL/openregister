<?php

/**
 * How SaveObject's list cascade handles items that create nothing or fail.
 *
 * Pins that an item whose save returns no uuid adds nothing to the created
 * list, that a failing item is logged with its own message and the others go
 * on, and that a failure after the items were created (the orphan lookup of a
 * related list) is logged against the property while the data stays as sent.
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\CascadeRun;
use OCA\OpenRegister\Service\Object\SaveObject\NestedObjectTarget;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use Stringable;

/**
 * List cascade failure handling, without a database.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\CascadeItem
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\CascadeRules
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\NestedObjectTarget
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 */
final class SaveObjectCascadeListFailuresTest extends TestCase {

	/**
	 * Every log record, as [level, message, context].
	 *
	 * @var array<int, array{0: mixed, 1: string, 2: array}>
	 */
	private array $records = [];

	/**
	 * A save handler: `boom` fails to save, `ghost` saves without a uuid, the rest get `c-<text>`.
	 *
	 * @return SaveObject The handler.
	 */
	private function handler(): SaveObject {
		$handler = $this->getMockBuilder(SaveObject::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject', 'assertMayCascadeCreate'])
			->getMock();
		$handler->method('saveObject')->willReturnCallback(
			function (array $data): ObjectEntity {
				if (($data['text'] ?? null) === 'boom') {
					throw new Exception('storage');
				}

				$created = new ObjectEntity();
				if (($data['text'] ?? null) !== 'ghost') {
					$created->setUuid('c-' . $data['text']);
				}

				return $created;
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willThrowException(new Exception('register gone'));
		$reflection = new ReflectionClass(SaveObject::class);
		$reflection->getProperty('lookups')->setValue($handler, new SaveLookups(schemaMapper: $this->createMock(SchemaMapperInterface::class), registerMapper: $registers));
		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		SaveLookupsAccess::set($handler, 'schemaReferenceCache', ['note' => '2']);
		SaveLookupsAccess::set($handler, 'schemaCache', ['2' => $note]);
		$reflection->getProperty('urlGenerator')->setValue($handler, $this->createMock(IURLGenerator::class));
		$logger = new class ($this->records) extends AbstractLogger {
			/**
			 * @param array $records The test's record list.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->records[] = [$level, (string) $message, $context];
			}
		};
		$reflection->getProperty('logger')->setValue($handler, $logger);

		return $handler;
	}//end handler()

	/**
	 * A parent `p-1` in register `10`.
	 *
	 * @return ObjectEntity The parent.
	 */
	private static function parent(): ObjectEntity {
		$parent = new ObjectEntity();
		$parent->setUuid('p-1');
		$parent->setRegister('10');

		return $parent;
	}//end parent()

	/**
	 * An item without a uuid adds nothing; a failing item is logged and the others are created.
	 *
	 * @return void
	 */
	public function testAnItemWithoutAUuidAddsNothingAndAFailingItemIsLogged(): void {
		$created = (new ReflectionClass(SaveObject::class))->getMethod('cascadeMultipleObjects')->invoke(
			$this->handler(),
			new NestedObjectTarget(parent: self::parent(), definition: ['type' => 'array', 'items' => ['$ref' => 'note']]),
			[['text' => 'a'], ['text' => 'ghost'], ['text' => 'boom'], ['text' => 'b']]
		);

		$this->assertSame(['c-a', 'c-b'], $created);
		$this->assertSame(
			[['warning', '[SaveObject] Cascade of a nested object failed, continuing with the others', ['exception' => 'storage']]],
			$this->records
		);
	}//end testAnItemWithoutAUuidAddsNothingAndAFailingItemIsLogged()

	/**
	 * A failure after the items were created is logged against the property; the data stays as sent.
	 *
	 * @return void
	 */
	public function testAFailureAfterTheItemsWereCreatedIsLoggedAgainstTheProperty(): void {
		$run = new CascadeRun();
		$run->parent = self::parent();
		$run->data = ['notes' => [['text' => 'a']]];
		$run->stored = ['notes' => ['old-1']];
		$run->property = 'notes';
		$run->definition = ['type' => 'array', 'items' => ['$ref' => 'note', 'objectConfiguration' => ['handling' => 'related-object']]];

		(new ReflectionClass(SaveObject::class))->getMethod('cascadeArrayProperty')->invoke($this->handler(), $run);

		$this->assertSame(
			[['warning', '[SaveObject] Cascade of an array property failed, continuing with the other properties', ['property' => 'notes', 'exception' => 'register gone']]],
			$this->records
		);
		$this->assertSame(['c-a'], $run->data['notes']);
	}//end testAFailureAfterTheItemsWereCreatedIsLoggedAgainstTheProperty()
}//end class
