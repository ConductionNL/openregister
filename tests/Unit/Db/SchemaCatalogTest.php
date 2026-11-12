<?php

/**
 * SchemaCatalog exposes the three mappers it was built with, as properties and through its interface.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;

class SchemaCatalogTest extends TestCase {

	/**
	 * The properties and the accessors give the same mappers.
	 *
	 * @return void
	 */
	public function testExposesTheMappersItWasBuiltWith(): void {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$schemas   = $this->createMock(SchemaMapperInterface::class);
		$tables    = $this->createMock(MagicMapperInterface::class);

		$catalog = new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $tables);

		$this->assertInstanceOf(SchemaCatalogInterface::class, $catalog);
		$this->assertSame($registers, $catalog->registers);
		$this->assertSame($schemas, $catalog->schemas);
		$this->assertSame($tables, $catalog->tables);
		$this->assertSame($registers, $catalog->registers());
		$this->assertSame($schemas, $catalog->schemas());
		$this->assertSame($tables, $catalog->tables());
	}//end testExposesTheMappersItWasBuiltWith()

	/**
	 * The interface is bound to the class in DbRegistrar.
	 *
	 * @return void
	 */
	public function testTheInterfaceIsBoundInDbRegistrar(): void {
		$this->assertSame(SchemaCatalog::class, DbRegistrar::BINDINGS[SchemaCatalogInterface::class]);
	}//end testTheInterfaceIsBoundInDbRegistrar()
}//end class
