<?php

/**
 * Which saves SaveObject runs as a nested transaction, and which tables it heals first.
 *
 * Pins that a save carrying a cascading property opens the transaction while
 * a plain or non-persisting save does not, and that the tables of the saved
 * schema and of every schema its cascades write to are created or synced
 * before the transaction opens.
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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveCall;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveFlags;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SaveObjectNestedTransactionTest extends TestCase {

	/**
	 * The register+schema tables healed, in order.
	 *
	 * @var list<string>
	 */
	private array $healed = [];

	private Register $register;

	private Schema $parent;

	private function handler(): SaveObject {
		$handler = (new ReflectionClass(SaveObject::class))->newInstanceWithoutConstructor();

		$tables = $this->createMock(MagicMapper::class);
		$tables->method('ensureTableForRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema): bool {
				$this->healed[] = $register->getId() . '_' . $schema->getId();
				return true;
			}
		);
		$reflection = new ReflectionClass(SaveObject::class);
		$reflection->getProperty('objectEntityMapper')->setValue($handler, $tables);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$reflection->getProperty('lookups')->setValue($handler, new SaveLookups(schemaMapper: $this->createMock(SchemaMapperInterface::class), registerMapper: $registers));

		$note = self::schema(id: 2, properties: ['text' => ['type' => 'string'], 'parent' => ['type' => 'object', '$ref' => 'case', 'inversedBy' => 'notes']]);
		$secret = self::schema(id: 4, properties: ['text' => ['type' => 'string']]);
		$this->parent = self::schema(
			id: 1,
			properties: [
				'title' => ['type' => 'string'],
				'notes' => ['type' => 'array', 'items' => ['$ref' => 'note'], 'inversedBy' => 'parent'],
				'secret' => ['type' => 'object', '$ref' => 'secret', 'objectConfiguration' => ['handling' => 'cascade'], 'register' => 9],
			]
		);
		SaveLookupsAccess::set($handler, 'schemaReferenceCache', ['note' => '2', 'secret' => '4', 'case' => '1']);
		SaveLookupsAccess::set($handler, 'schemaCache', ['1' => $this->parent, '2' => $note, '4' => $secret]);
		$other = new Register();
		$other->setId(9);
		$this->register = new Register();
		$this->register->setId(3);
		SaveLookupsAccess::set($handler, 'registerCache', ['3' => $this->register, '9' => $other]);

		return $handler;
	}//end handler()

	private static function schema(int $id, array $properties): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('s' . $id);
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	private function call(array $data, bool $persist = true): ObjectSaveCall {
		$call = new ObjectSaveCall();
		$call->flags = new ObjectSaveFlags();
		$call->flags->persist = $persist;
		$call->register = $this->register;
		$call->schema = $this->parent;
		$call->data = $data;

		return $call;
	}//end call()

	public function testOnlyASaveCarryingACascadingPropertyOpensTheTransaction(): void {
		$handler = $this->handler();
		$writesNested = (new ReflectionClass(SaveObject::class))->getMethod('writesNestedObjects');

		$this->assertTrue($writesNested->invoke($handler, $this->call(data: ['title' => 't', 'notes' => [['text' => 'a']]])));
		$this->assertTrue($writesNested->invoke($handler, $this->call(data: ['secret' => ['text' => 's']])));
		$this->assertFalse($writesNested->invoke($handler, $this->call(data: ['title' => 't'])));
		$this->assertFalse($writesNested->invoke($handler, $this->call(data: ['notes' => [['text' => 'a']]], persist: false)));
	}//end testOnlyASaveCarryingACascadingPropertyOpensTheTransaction()

	public function testEveryTableTheCascadesWriteToIsHealedOnceBeforeTheSave(): void {
		$handler = $this->handler();

		(new ReflectionClass(SaveObject::class))->getMethod('healNestedSaveTables')->invoke(
			$handler,
			$this->call(data: ['notes' => [['text' => 'a']]])
		);

		$this->assertSame(['3_1', '3_2', '9_4'], $this->healed);
	}//end testEveryTableTheCascadesWriteToIsHealedOnceBeforeTheSave()
}//end class
