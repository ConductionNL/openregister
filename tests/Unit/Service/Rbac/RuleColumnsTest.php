<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumns;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\RuleColumns;
use PHPUnit\Framework\TestCase;

/**
 * What a rule key resolves to, and what a save refuses.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 */
class RuleColumnsTest extends TestCase {

	/**
	 * A schema with one declared property, one non-column entry and one property named like a config entry.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['firstName' => ['type' => 'string'], 'scalar' => 'not an array', 'required' => ['a']]);
		$schema->setConfiguration(['linkedTypes' => ['deck']]);

		return $schema;
	}//end schema()

	/**
	 * The fixed metadata map is the one the table builder uses.
	 *
	 * @return void
	 */
	public function testTheMetadataMapIsTheTableBuilders(): void {
		$this->assertEqualsCanonicalizing(array_keys(MagicMetadataColumns::ALL), RuleColumns::METADATA);
	}//end testTheMetadataMapIsTheTableBuilders()

	/**
	 * A key renders to the column the table builder names, and only ever to safe characters.
	 *
	 * @return void
	 */
	public function testAKeyRendersToItsColumn(): void {
		$columns = new RuleColumns();

		$this->assertSame('first_name', $columns->columnOf(key: 'firstName'));
		$this->assertSame('first_name', $columns->columnOf(key: 'first-name'));
		$this->assertSame('_owner', $columns->columnOf(key: '_owner'));
		$this->assertSame('_owner', $columns->columnOf(key: '@self.owner'));
		$this->assertSame('_uuid', $columns->columnOf(key: '@self.id'));
		$this->assertSame('_schema_version', $columns->columnOf(key: '_schemaVersion'));
		$this->assertSame('x_or_1_1', $columns->columnOf(key: 'x) or (1=1'));
		$this->assertSame('foo_bar', $columns->columnOf(key: 'foo__bar'));
		$this->assertSame('a_b', $columns->columnOf(key: "a`\"'; b--"));
	}//end testAKeyRendersToItsColumn()

	/**
	 * What compiles for a schema.
	 *
	 * @return void
	 */
	public function testWhatASchemaAdmits(): void {
		$columns = new RuleColumns(schema: $this->schema(), prefix: 't.');

		$this->assertSame('t.', $columns->prefix);
		$this->assertTrue($columns->admits(match: []));
		$this->assertTrue($columns->admits(match: ['firstName' => 1, '_owner' => 2, '@self.owner' => 3, '_deck' => 4]));
		$this->assertFalse($columns->admits(match: ['firstName' => 1, 'lastName' => 2]));
		$this->assertFalse($columns->admits(match: ['scalar' => 1]));
		$this->assertFalse($columns->admits(match: ['required' => 1]));
		$this->assertFalse($columns->admits(match: ['_notes' => 1]));
		$this->assertFalse($columns->admits(match: ['_x) or (1=1' => 1]));
		$this->assertFalse($columns->admits(match: ['first_name' => 1]));
		$this->assertFalse($columns->admits(match: [0 => 1]));
	}//end testWhatASchemaAdmits()

	/**
	 * Without a schema only metadata columns compile.
	 *
	 * @return void
	 */
	public function testWithoutASchemaOnlyMetadataCompiles(): void {
		$columns = new RuleColumns();

		$this->assertTrue($columns->admits(match: ['_owner' => 1]));
		$this->assertFalse($columns->admits(match: ['firstName' => 1]));
	}//end testWithoutASchemaOnlyMetadataCompiles()

	/**
	 * What a schema's save refuses; metadata keys are left to their own check.
	 *
	 * @return void
	 */
	public function testWhatASchemaSaveRefuses(): void {
		$columns = new RuleColumns(schema: $this->schema());

		$this->assertFalse($columns->refusesAtSave(key: 'firstName'));
		$this->assertFalse($columns->refusesAtSave(key: '_anything'));
		$this->assertFalse($columns->refusesAtSave(key: '@self.anything'));
		$this->assertTrue($columns->refusesAtSave(key: 'lastName'));
		$this->assertTrue($columns->refusesAtSave(key: 'x) or (1=1'));
	}//end testWhatASchemaSaveRefuses()

	/**
	 * A malformed key is refused even when the schema declares a property of that name.
	 *
	 * @return void
	 */
	public function testAMalformedKeyIsRefusedEvenWhenDeclared(): void {
		$schema = new Schema();
		$schema->setProperties(['x) or (1=1' => ['type' => 'string']]);

		$this->assertTrue((new RuleColumns(schema: $schema))->refusesAtSave(key: 'x) or (1=1'));
	}//end testAMalformedKeyIsRefusedEvenWhenDeclared()

	/**
	 * Without a schema a save judges the form only.
	 *
	 * @return void
	 */
	public function testWithoutASchemaASaveJudgesTheForm(): void {
		$columns = new RuleColumns();

		$this->assertFalse($columns->refusesAtSave(key: 'anyProperty'));
		$this->assertFalse($columns->refusesAtSave(key: 'any-property_2'));
		$this->assertTrue($columns->refusesAtSave(key: 'a.b'));
		$this->assertTrue($columns->refusesAtSave(key: 'x) or (1=1'));
		$this->assertTrue($columns->refusesAtSave(key: "a\n"));
		$this->assertTrue($columns->refusesAtSave(key: ''));
	}//end testWithoutASchemaASaveJudgesTheForm()
}//end class
