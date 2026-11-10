<?php

/**
 * The in-memory query over projected Tables rows: a non-array filters value,
 * a plain-text ascending sort, rows equal on every sort field, and boolean
 * filter values matched by their textual form.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectSource\TablesObjectQuery;
use PHPUnit\Framework\TestCase;

/**
 * Branch tests for {@see TablesObjectQuery}.
 */
class TablesObjectQueryBehaviourTest extends TestCase {

	/**
	 * A projected row.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The row data.
	 *
	 * @return ObjectEntity The row.
	 */
	private static function row(string $uuid, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);

		return $object;
	}//end row()

	/**
	 * Uuids of the rows, in order.
	 *
	 * @param array<int, ObjectEntity> $rows The rows.
	 *
	 * @return array<int, string> The uuids.
	 */
	private static function uuids(array $rows): array {
		return array_map(static fn (ObjectEntity $row): string => (string)$row->getUuid(), $rows);
	}//end uuids()

	/**
	 * A filters value that is not a map filters nothing and does not narrow.
	 *
	 * @return void
	 */
	public function testANonArrayFiltersValueFiltersNothing(): void {
		$query = new TablesObjectQuery(query: ['filters' => 'status=open']);

		$this->assertFalse($query->isNarrowing());
		$this->assertSame(['a', 'b'], self::uuids($query->matching(objects: [self::row('a', []), self::row('b', [])])));
	}//end testANonArrayFiltersValueFiltersNothing()

	/**
	 * A plain field name sorts ascending; a leading dash sorts descending.
	 *
	 * @return void
	 */
	public function testAPlainTextSortIsAscendingAndADashDescending(): void {
		$rows = [self::row('b', ['n' => 2]), self::row('c', ['n' => 3]), self::row('a', ['n' => 1])];

		$this->assertTrue((new TablesObjectQuery(query: ['sort' => 'n']))->isNarrowing());
		$this->assertSame(['a', 'b', 'c'], self::uuids((new TablesObjectQuery(query: ['sort' => 'n']))->matching(objects: $rows)));
		$this->assertSame(['c', 'b', 'a'], self::uuids((new TablesObjectQuery(query: ['sort' => '-n']))->matching(objects: $rows)));
	}//end testAPlainTextSortIsAscendingAndADashDescending()

	/**
	 * Rows equal on the first sort field are ordered by the next; rows equal
	 * on every field keep their order.
	 *
	 * @return void
	 */
	public function testTiesFallThroughToTheNextFieldAndThenKeepTheirOrder(): void {
		$rows = [
			self::row('x2', ['g' => 'x', 'n' => 2]),
			self::row('y1', ['g' => 'y', 'n' => 1]),
			self::row('x1', ['g' => 'x', 'n' => 1]),
			self::row('x1b', ['g' => 'x', 'n' => 1]),
		];

		$sorted = (new TablesObjectQuery(query: ['sort' => [['field' => 'g'], ['field' => 'n', 'order' => 'asc']]]))->matching(objects: $rows);

		$this->assertSame(['x1', 'x1b', 'x2', 'y1'], self::uuids($sorted));
	}//end testTiesFallThroughToTheNextFieldAndThenKeepTheirOrder()

	/**
	 * A boolean cell matches the textual form of a boolean filter.
	 *
	 * @return void
	 */
	public function testBooleanCellsMatchByTheirTextualForm(): void {
		$rows = [self::row('done', ['done' => true]), self::row('open', ['done' => false])];

		$this->assertSame(['done'], self::uuids((new TablesObjectQuery(query: ['filters' => ['done' => true]]))->matching(objects: $rows)));
		$this->assertSame(['open'], self::uuids((new TablesObjectQuery(query: ['filters' => ['done' => 'false']]))->matching(objects: $rows)));
	}//end testBooleanCellsMatchByTheirTextualForm()
}//end class
