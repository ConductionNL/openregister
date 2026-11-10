<?php

/**
 * TermsFacetTable hands out metadata and property facets over one table.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\TermsFacetTable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\TermsFacetTable
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableTermsFacet
 */
class TermsFacetTableTest extends TestCase {

	/**
	 * A property facet is not a metadata facet and carries the table it came from.
	 *
	 * @return void
	 */
	public function testAPropertyFacetCarriesTheTable(): void {
		$register = $this->createMock(Register::class);
		$schema = $this->createMock(Schema::class);
		$table = new TermsFacetTable('oc_openregister_table_1_2', ['status' => 'open'], $register, $schema);

		$facet = $table->forPropertyField('colour');

		$this->assertSame('colour', $facet->field);
		$this->assertFalse($facet->isMetadata);
		$this->assertSame('oc_openregister_table_1_2', $facet->tableName);
		$this->assertSame(['status' => 'open'], $facet->baseQuery);
		$this->assertSame($register, $facet->register);
		$this->assertSame($schema, $facet->schema);
	}//end testAPropertyFacetCarriesTheTable()

	/**
	 * A metadata facet is flagged as metadata.
	 *
	 * @return void
	 */
	public function testAMetadataFacetIsFlagged(): void {
		$table = new TermsFacetTable('t', [], $this->createMock(Register::class), $this->createMock(Schema::class));

		$facet = $table->forMetadataField('owner');

		$this->assertSame('owner', $facet->field);
		$this->assertTrue($facet->isMetadata);
	}//end testAMetadataFacetIsFlagged()
}//end class
