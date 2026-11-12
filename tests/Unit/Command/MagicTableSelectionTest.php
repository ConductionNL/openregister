<?php

/**
 * Which registers, schemas and magic tables a magic-table maintenance command
 * works on: one by reference or all of them without RBAC or multitenancy,
 * and only the register/schema pairs whose table exists.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;

/**
 * MagicTableSelection.
 *
 * @covers \OCA\OpenRegister\Command\MagicTableSelection
 * @uses \OCA\OpenRegister\Command\MagicTableTarget
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\MagicMappingOptions
 */
class MagicTableSelectionTest extends TestCase {

	private RegisterMapperInterface $registers;

	private SchemaMapperInterface $schemas;

	private MagicMapperInterface $tables;

	private MagicTableSelection $selection;

	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->tables    = $this->createMock(MagicMapperInterface::class);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($this->registers);
		$catalog->method('schemas')->willReturn($this->schemas);
		$catalog->method('tables')->willReturn($this->tables);

		$this->selection = new MagicTableSelection($catalog);
	}//end setUp()

	private function register(array $schemaIds): Register {
		$register = new Register();
		$register->setSchemas($schemaIds);

		return $register;
	}//end register()

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}//end schema()

	public function testOneRegisterIsFoundByReferenceWithoutMultitenancy(): void {
		$register = $this->register([]);
		$this->registers->expects($this->once())->method('find')->with('zaken', true, false)->willReturn($register);
		$this->registers->expects($this->never())->method('findAll');

		$this->assertSame([$register], $this->selection->registers('zaken'));
	}//end testOneRegisterIsFoundByReferenceWithoutMultitenancy()

	public function testNoRegisterReferenceSelectsEveryRegister(): void {
		$all = [$this->register([]), $this->register([])];
		$this->registers->expects($this->exactly(2))->method('findAll')
			->with(null, null, [], [], [], false, false)
			->willReturn($all);
		$this->registers->expects($this->never())->method('find');

		$this->assertSame($all, $this->selection->registers(null));
		$this->assertSame($all, $this->selection->registers(''));
	}//end testNoRegisterReferenceSelectsEveryRegister()

	public function testOneSchemaIsFoundByReferenceWithoutMultitenancy(): void {
		$schema = $this->schema(4);
		$this->schemas->expects($this->once())->method('find')->with('case', [], true, false)->willReturn($schema);

		$this->assertSame([$schema], $this->selection->schemas('case'));
	}//end testOneSchemaIsFoundByReferenceWithoutMultitenancy()

	public function testNoSchemaReferenceSelectsEverySchema(): void {
		$all = [$this->schema(1)];
		$this->schemas->expects($this->once())->method('findAll')
			->with(null, null, [], [], [], [], false, false)
			->willReturn($all);

		$this->assertSame($all, $this->selection->schemas(null));
	}//end testNoSchemaReferenceSelectsEverySchema()

	public function testOnlyLinkedSelectedSchemasWithAnExistingTableAreTargets(): void {
		$case    = $this->schema(1);
		$note    = $this->schema(2);
		$invoice = $this->schema(3);
		$zaken   = $this->register(['1', 2, 3, 99]);
		$empty   = $this->register([]);

		$this->tables->method('tableExistsForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): bool => $schema !== $note
		);
		$this->tables->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 'oc_or_t_'.$schema->getId()
		);

		$targets = $this->selection->tables([$zaken, $empty], [$case, $note, $invoice]);

		$this->assertSame(
			[[$zaken, $case, 'oc_or_t_1'], [$zaken, $invoice, 'oc_or_t_3']],
			array_map(static fn ($t): array => [$t->register, $t->schema, $t->tableName], $targets)
		);
	}//end testOnlyLinkedSelectedSchemasWithAnExistingTableAreTargets()

	public function testNoRegistersGiveNoTables(): void {
		$this->tables->expects($this->never())->method('tableExistsForRegisterSchema');

		$this->assertSame([], $this->selection->tables([], [$this->schema(1)]));
	}//end testNoRegistersGiveNoTables()
}//end class
