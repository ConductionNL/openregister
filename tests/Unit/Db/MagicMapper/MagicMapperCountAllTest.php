<?php

/**
 * Characterisation test for MagicMapper::countAll().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Pins which tables countAll() counts in and what it hands each count.
 */
class MagicMapperCountAllTest extends TestCase {

	/**
	 * The counts asked for: register id, schema id, query.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A mapper over two tables of schema 7 (registers 1 and 2) and one of schema 8.
	 *
	 * @return MagicMapper
	 */
	private function mapper(): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['getAllRegisterSchemaPairs', 'countObjectsInRegisterSchemaTable'])
			->getMock();
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);
		(new ReflectionProperty(MagicMapper::class, 'logger'))->setValue($mapper, new NullLogger());
		(new ReflectionProperty(MagicMapper::class, 'registerMapper'))->setValue($mapper, $registers);
		(new ReflectionProperty(MagicMapper::class, 'schemaMapper'))->setValue($mapper, $schemas);
		$mapper->method('getAllRegisterSchemaPairs')->willReturn(
			[['registerId' => 1, 'schemaId' => 7], ['registerId' => 1, 'schemaId' => 8], ['registerId' => 2, 'schemaId' => '7']]
		);
		$mapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query, Register $register, Schema $schema): int {
				$this->calls[] = [$register->getId(), $schema->getId(), $query];
				return (10 * $register->getId()) + $schema->getId();
			}
		);

		return $mapper;
	}//end mapper()

	public function testWithRegisterAndSchemaCountsThatTableOnly(): void {
		$register = new Register();
		$register->setId(5);
		$schema = new Schema();
		$schema->setId(9);

		$this->assertSame(59, $this->mapper()->countAll(['a' => 1], $schema, $register));
		$this->assertSame([[5, 9, ['a' => 1]]], $this->calls);
	}//end testWithRegisterAndSchemaCountsThatTableOnly()

	public function testWithoutScopeSumsEveryTable(): void {
		$this->assertSame(17 + 18 + 27, $this->mapper()->countAll());
		$this->assertSame([[1, 7, []], [1, 8, []], [2, 7, []]], $this->calls);
	}//end testWithoutScopeSumsEveryTable()

	public function testWithSchemaOnlySumsItsTables(): void {
		$schema = new Schema();
		$schema->setId(7);

		$this->assertSame(17 + 27, $this->mapper()->countAll(['b' => 2], $schema));
		$this->assertSame([[1, 7, ['b' => 2]], [2, 7, ['b' => 2]]], $this->calls);
	}//end testWithSchemaOnlySumsItsTables()

	public function testWithRegisterOnlySumsItsTables(): void {
		$register = new Register();
		$register->setId(1);

		$this->assertSame(17 + 18, $this->mapper()->countAll(null, null, $register));
		$this->assertSame([[1, 7, []], [1, 8, []]], $this->calls);
	}//end testWithRegisterOnlySumsItsTables()
}//end class
