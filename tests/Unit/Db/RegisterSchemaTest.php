<?php

/**
 * RegisterSchema derives the register and schema ids from its entities.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

class RegisterSchemaTest extends TestCase {

	/**
	 * The pair holds the entities it was given.
	 *
	 * @return void
	 */
	public function testHoldsTheEntitiesItWasGiven(): void {
		$register = new Register();
		$schema   = new Schema();
		$table    = new RegisterSchema(register: $register, schema: $schema);

		$this->assertSame($register, $table->register);
		$this->assertSame($schema, $table->schema);
	}//end testHoldsTheEntitiesItWasGiven()

	/**
	 * registerId() and schemaId() are the entities' database ids.
	 *
	 * @return void
	 */
	public function testIdsAreTheEntityIds(): void {
		$register = new Register();
		$register->setId(5);
		$schema = new Schema();
		$schema->setId(9);

		$table = new RegisterSchema(register: $register, schema: $schema);

		$this->assertSame(5, $table->registerId());
		$this->assertSame(9, $table->schemaId());
	}//end testIdsAreTheEntityIds()
}//end class
