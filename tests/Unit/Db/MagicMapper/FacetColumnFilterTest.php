<?php

/**
 * The conditions the fallback facet query puts on one column: equality, list
 * membership, JSON-array containment and the case-insensitive search on both
 * platforms, as builder calls.
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
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\FacetColumnFilter;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins every builder call FacetColumnFilter makes.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\FacetColumnFilter
 * @uses   \OCA\OpenRegister\Support\FilterParams
 */
class FacetColumnFilterTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The log without the bound-type argument of each parameter, which differs between DBAL versions.
	 *
	 * @return array<int, string>
	 */
	private function log(): array {
		return array_map(
			static fn (string $line): string => (string)preg_replace('/^(#\d+ param :p\d+ = .*), [^,]+$/', '$1', $line),
			$this->queryLog
		);
	}//end log()

	/**
	 * A scalar is compared with eq.
	 *
	 * @return void
	 */
	public function testEqualsAScalar(): void {
		(new FacetColumnFilter($this->recordingBuilder(), 'status'))->equals('open');

		$this->assertSame(
			['#1 param :p1 = open', '#1 expr.eq(status, :p1, NULL)', '#1 andWhere(eq(status, :p1, NULL))'],
			$this->log()
		);
	}//end testEqualsAScalar()

	/**
	 * A list is a string-array IN.
	 *
	 * @return void
	 */
	public function testEqualsAList(): void {
		(new FacetColumnFilter($this->recordingBuilder(), 'status'))->equals(['open', 'closed']);

		$this->assertSame(
			['#1 param :p1 = ["open","closed"]', '#1 expr.in(status, :p1, NULL)', '#1 andWhere(in(status, :p1, NULL))'],
			$this->log()
		);
	}//end testEqualsAList()

	/**
	 * An array property must contain every value, one jsonb containment each.
	 *
	 * @return void
	 */
	public function testAnArrayPropertyContainsEveryValue(): void {
		(new FacetColumnFilter($this->recordingBuilder(), 'tags'))->matchesProperty(['a', 'b'], 'array');

		$this->assertSame(
			[
				'#1 param :p1 = ["a"]',
				'#1 andWhere(tags::jsonb @> :p1)',
				'#1 param :p2 = ["b"]',
				'#1 andWhere(tags::jsonb @> :p2)',
			],
			$this->log()
		);
	}//end testAnArrayPropertyContainsEveryValue()

	/**
	 * A single containment value is wrapped in a list.
	 *
	 * @return void
	 */
	public function testContainsAllWrapsAScalar(): void {
		(new FacetColumnFilter($this->recordingBuilder(), 'tags'))->containsAll('x');

		$this->assertSame(['#1 param :p1 = ["x"]', '#1 andWhere(tags::jsonb @> :p1)'], $this->log());
	}//end testContainsAllWrapsAScalar()

	/**
	 * A boolean property compares its stored form; a string property the value's JSON spelling.
	 *
	 * @return void
	 */
	public function testOtherPropertiesCompareTheirComparableValue(): void {
		$builder = $this->recordingBuilder();
		(new FacetColumnFilter($builder, 'active'))->matchesProperty(true, 'boolean');
		(new FacetColumnFilter($builder, 'label'))->matchesProperty(false, 'string');

		$this->assertSame(
			[
				'#1 param :p1 = 1',
				'#1 expr.eq(active, :p1, NULL)',
				'#1 andWhere(eq(active, :p1, NULL))',
				'#1 param :p2 = false',
				'#1 expr.eq(label, :p2, NULL)',
				'#1 andWhere(eq(label, :p2, NULL))',
			],
			$this->log()
		);
	}//end testOtherPropertiesCompareTheirComparableValue()

	/**
	 * On MySQL the search is LIKE on LOWER() with a lower-cased pattern.
	 *
	 * @return void
	 */
	public function testSearchConditionOnMysql(): void {
		$condition = (new FacetColumnFilter($this->recordingBuilder(), 'title'))
			->searchCondition('%Foo%', $this->createMock(MySQLPlatform::class));

		$this->assertSame('like(LOWER(title), :p1, NULL)', $condition);
		$this->assertSame(['#1 param :p1 = %foo%', '#1 expr.like(LOWER(title), :p1, NULL)'], $this->log());
	}//end testSearchConditionOnMysql()

	/**
	 * On PostgreSQL the search is ILIKE, the LIKE parameter still created first.
	 *
	 * @return void
	 */
	public function testSearchConditionOnPostgres(): void {
		$condition = (new FacetColumnFilter($this->recordingBuilder(), 'title'))
			->searchCondition('%Foo%', $this->createMock(PostgreSQLPlatform::class));

		$this->assertSame('LOWER(title) ILIKE LOWER(:p2)', (string)$condition);
		$this->assertSame(
			['#1 param :p1 = %foo%', '#1 expr.like(LOWER(title), :p1, NULL)', '#1 param :p2 = %Foo%'],
			$this->log()
		);
	}//end testSearchConditionOnPostgres()
}//end class
