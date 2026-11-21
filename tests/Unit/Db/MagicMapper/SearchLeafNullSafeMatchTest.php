<?php

/**
 * SearchLeaf builds null-safe and plain column matches per platform.
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

use OCA\OpenRegister\Db\MagicMapper\SearchLeafScope;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\SearchLeaf
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeafScope
 */
class SearchLeafNullSafeMatchTest extends TestCase {

	/**
	 * A scope on the given platform.
	 *
	 * @param bool $isPostgres Whether the scope is on PostgreSQL.
	 *
	 * @return SearchLeafScope
	 */
	private function scope(bool $isPostgres): SearchLeafScope {
		return new SearchLeafScope($this->createMock(Schema::class), $isPostgres);
	}//end scope()

	/**
	 * On MySQL a null-safe match coalesces before the case-folding cast.
	 *
	 * @return void
	 */
	public function testMysqlNullSafeMatchCoalescesInsideTheCast(): void {
		$leaf = $this->scope(false)->nullSafeTerm('%jan%', 'jan');

		$this->assertSame("LOWER(CAST(COALESCE(t.title, '') AS CHAR)) LIKE LOWER(:p)", $leaf->columnMatch('t.title', ':p'));
	}//end testMysqlNullSafeMatchCoalescesInsideTheCast()

	/**
	 * On MySQL a plain match casts the bare column.
	 *
	 * @return void
	 */
	public function testMysqlPlainMatchCastsTheBareColumn(): void {
		$leaf = $this->scope(false)->term('%jan%', 'jan');

		$this->assertSame('LOWER(CAST(t.title AS CHAR)) LIKE LOWER(:p)', $leaf->columnMatch('t.title', ':p'));
	}//end testMysqlPlainMatchCastsTheBareColumn()

	/**
	 * On PostgreSQL a null-safe match coalesces the text cast and uses ILIKE.
	 *
	 * @return void
	 */
	public function testPostgresNullSafeMatchUsesIlike(): void {
		$leaf = $this->scope(true)->nullSafeTerm('%jan%', 'jan');

		$this->assertSame("COALESCE(t.title::text, '') ILIKE :p", $leaf->columnMatch('t.title', ':p'));
	}//end testPostgresNullSafeMatchUsesIlike()
}//end class
