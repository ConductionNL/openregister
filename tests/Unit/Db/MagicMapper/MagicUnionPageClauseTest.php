<?php

/**
 * The LIMIT/OFFSET clause and the per-batch query of a cross-schema UNION page.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicUnionPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins limitClause() and batchQuery() of MagicUnionPage.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicUnionPage
 * @uses   \OCA\OpenRegister\Support\QueryLimit
 */
class MagicUnionPageClauseTest extends TestCase {

	/**
	 * Queries and the clause each gets with a largest page of 100.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function clauses(): array {
		return [
			'no limit, no offset'         => [[], ''],
			'unlimited from an offset'    => [['_limit' => false, '_offset' => 40], ' LIMIT 18446744073709551615 OFFSET 40'],
			'a limit, no offset'          => [['_limit' => 20], ' LIMIT 20 OFFSET 0'],
			'a limit and an offset'       => [['_limit' => '20', '_offset' => '60'], ' LIMIT 20 OFFSET 60'],
			'a limit past the bound'      => [['_limit' => 5000, '_offset' => 10], ' LIMIT 100 OFFSET 10'],
			'a negative offset'           => [['_limit' => 5, '_offset' => -9], ' LIMIT 5 OFFSET 0'],
			'an unlimited word'           => [['_limit' => 'all'], ''],
			'an injected offset'          => [['_limit' => 5, '_offset' => '3; DROP TABLE x'], ' LIMIT 5 OFFSET 3'],
			'a zero limit is unlimited'   => [['_limit' => 0, '_offset' => 2], ' LIMIT 18446744073709551615 OFFSET 2'],
		];
	}//end clauses()

	/**
	 * The clause is built from cast, clamped values only.
	 *
	 * @param array  $query    The search parameters.
	 * @param string $expected The clause.
	 *
	 * @return void
	 */
	#[DataProvider('clauses')]
	public function testLimitClause(array $query, string $expected): void {
		$this->assertSame($expected, (new MagicUnionPage($query, 100))->limitClause());
	}//end testLimitClause()

	/**
	 * A batch starts at page one and reaches the end of the caller's page.
	 *
	 * @return void
	 */
	public function testBatchQueryCoversOffsetPlusLimit(): void {
		$page = new MagicUnionPage(['_limit' => 25, '_offset' => 50, '_search' => 'x'], 100);

		$this->assertSame(['_limit' => 75, '_offset' => 0, '_search' => 'x'], $page->batchQuery());
	}//end testBatchQueryCoversOffsetPlusLimit()

	/**
	 * The batch limit is the limit asked for, not the clamped page size.
	 *
	 * @return void
	 */
	public function testBatchQueryDoesNotClampTheLimit(): void {
		$this->assertSame(['_limit' => 510, '_offset' => 0], (new MagicUnionPage(['_limit' => 500, '_offset' => 10], 100))->batchQuery());
	}//end testBatchQueryDoesNotClampTheLimit()

	/**
	 * An unlimited query stays unlimited; only the offset is reset.
	 *
	 * @return void
	 */
	public function testBatchQueryKeepsAnUnlimitedQueryUnlimited(): void {
		$this->assertSame(
			['_limit' => false, '_offset' => 0],
			(new MagicUnionPage(['_limit' => false, '_offset' => 30], 100))->batchQuery()
		);
	}//end testBatchQueryKeepsAnUnlimitedQueryUnlimited()

	/**
	 * An unlimited query is read in one statement, the batch query.
	 *
	 * @return void
	 */
	public function testAnUnlimitedQueryIsOneStep(): void {
		$steps = iterator_to_array((new MagicUnionPage(['_limit' => 'none', '_offset' => 7], 100))->batchSteps(), false);

		$this->assertSame([['_limit' => 'none', '_offset' => 0]], $steps);
	}//end testAnUnlimitedQueryIsOneStep()
}//end class
