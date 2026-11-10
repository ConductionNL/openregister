<?php

/**
 * LinkColumnTest: the equality filters a link-table lookup chains together.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\LinkColumn;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\LinkColumn
 */
class LinkColumnTest extends TestCase {
	use RecordsQueries;

	/**
	 * A fresh recording builder.
	 *
	 * @return IQueryBuilder
	 */
	private function qb(): IQueryBuilder {
		return $this->recordingConnection()->getQueryBuilder();
	}//end qb()

	public function testANullValueMatchesOnlyNull(): void {
		$this->assertSame('isNull(schema_id)', (new LinkColumn('schema_id', null))->equals($this->qb()));
	}//end testANullValueMatchesOnlyNull()

	public function testAnIntegerIsBoundAsAnInteger(): void {
		$this->assertSame('eq(register_id, 7)', (new LinkColumn('register_id', 7))->equals($this->qb()));
	}//end testAnIntegerIsBoundAsAnInteger()

	public function testANumericStringStaysAString(): void {
		$this->assertSame("eq(object_uuid, '7')", (new LinkColumn('object_uuid', '7'))->equals($this->qb()));
	}//end testANumericStringStaysAString()

	public function testASingleColumnAddsOneFilter(): void {
		$qb = $this->qb();

		(new LinkColumn('a', 1))->applyTo($qb);

		$this->assertSame(['andWhere(eq(a, 1))'], $this->queries[0]);
	}//end testASingleColumnAddsOneFilter()

	public function testChainedColumnsAddTheirFiltersInOrderWithoutChangingTheOriginal(): void {
		$first = new LinkColumn('a', 1);
		$chain = $first->also(new LinkColumn('b', 'x'))->also(new LinkColumn('c', null));
		$qb = $this->qb();

		$chain->applyTo($qb);
		$first->applyTo($qb);

		$this->assertSame(
			['andWhere(eq(a, 1))', "andWhere(eq(b, 'x'))", 'andWhere(isNull(c))', 'andWhere(eq(a, 1))'],
			$this->queries[0]
		);
	}//end testChainedColumnsAddTheirFiltersInOrderWithoutChangingTheOriginal()
}//end class
