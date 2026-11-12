<?php

/**
 * The hierarchy walk and the facet index field name a property's column by the
 * rule the magic-table writer uses: camelCase to snake_case, every other
 * character to `_`, runs of `_` collapsed, trailing `_` trimmed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\FacetListing;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Column names on the hierarchy and facet paths.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\HierarchyDescender
 * @covers \OCA\OpenRegister\Service\Object\FacetListing
 * @covers \OCA\OpenRegister\Service\Object\FacetEntry
 */
class StoredColumnNameTest extends TestCase {

	private const NAMES = [
		'parent' => 'parent',
		'firstName' => 'first_name',
		'parent-unit' => 'parent_unit',
		'parent.unit' => 'parent_unit',
		'a--b' => 'a_b',
		'trailing_' => 'trailing',
		'HTTPCode' => 'httpcode',
		'item2Code' => 'item2_code',
	];

	/**
	 * The hierarchy walk reads the column the writer stored the property in.
	 *
	 * @return void
	 */
	public function testHierarchyColumnIsTheStoredColumn(): void {
		$descender = new HierarchyDescender(
			$this->createMock(IDBConnection::class),
			$this->createMock(SchemaMapperInterface::class),
			$this->createMock(RegisterMapperInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		foreach (self::NAMES as $property => $column) {
			$this->assertSame($column, $descender->columnFor(property: $property), $property);
		}
	}//end testHierarchyColumnIsTheStoredColumn()

	/**
	 * A facet's index field is the stored column.
	 *
	 * @return void
	 */
	public function testFacetIndexFieldIsTheStoredColumn(): void {
		$facets = [];
		foreach (array_keys(self::NAMES) as $property) {
			$facets[$property] = ['type' => 'terms', 'buckets' => []];
		}

		$transformed = (new FacetListing(facetableConfig: []))->format(facets: $facets);

		foreach (self::NAMES as $property => $column) {
			$this->assertSame($column, $transformed[$property]['index_field'] ?? null, $property);
		}
	}//end testFacetIndexFieldIsTheStoredColumn()
}//end class
