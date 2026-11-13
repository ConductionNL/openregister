<?php

/**
 * A list query reads limit, offset or page, order and search from request params, and knows when it is unbounded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class ListQueryTest extends TestCase {

	public function testEmptyParamsGiveTheDefaults(): void {
		$query = ListQuery::fromParams([]);

		$this->assertSame(ListQuery::DEFAULT_LIMIT, $query->limit);
		$this->assertNull($query->offset);
		$this->assertSame([], $query->filters);
		$this->assertSame([], $query->sort);
		$this->assertNull($query->search);
		$this->assertFalse($query->isUnbounded());
		$this->assertSame(1, $query->page());
	}//end testEmptyParamsGiveTheDefaults()

	public function testPlainKeysWinOverUnderscoredAliasesAndAreCastToInt(): void {
		$query = ListQuery::fromParams(['limit' => '15', '_limit' => 99, 'offset' => '30', '_offset' => 77]);

		$this->assertSame(15, $query->limit);
		$this->assertSame(30, $query->offset);
		$this->assertSame(3, $query->page());
	}//end testPlainKeysWinOverUnderscoredAliasesAndAreCastToInt()

	public function testUnderscoredAliasesAreReadWhenThePlainKeysAreAbsent(): void {
		$query = ListQuery::fromParams(['_limit' => '5', '_offset' => '10']);

		$this->assertSame(5, $query->limit);
		$this->assertSame(10, $query->offset);
	}//end testUnderscoredAliasesAreReadWhenThePlainKeysAreAbsent()

	public function testPageBecomesAnOffsetOnlyWhenNoOffsetIsGiven(): void {
		$fromPage    = ListQuery::fromParams(['limit' => 10, 'page' => '3']);
		$fromAlias   = ListQuery::fromParams(['_limit' => 4, '_page' => 2]);
		$offsetWins  = ListQuery::fromParams(['limit' => 10, 'page' => 3, 'offset' => 5]);
		$defaultPage = ListQuery::fromParams(['page' => 2]);

		$this->assertSame(20, $fromPage->offset);
		$this->assertSame(3, $fromPage->page());
		$this->assertSame(4, $fromAlias->offset);
		$this->assertSame(5, $offsetWins->offset);
		$this->assertSame(ListQuery::DEFAULT_LIMIT, $defaultPage->offset);
	}//end testPageBecomesAnOffsetOnlyWhenNoOffsetIsGiven()

	public function testTheWholeParamsArrayIsKeptAsFilters(): void {
		$params = ['limit' => 3, 'status' => 'open', '_search' => 'x'];

		$this->assertSame($params, ListQuery::fromParams($params)->filters);
	}//end testTheWholeParamsArrayIsKeptAsFilters()

	public function testOrderIsDecodedFromJsonOrKeptAsAnArray(): void {
		$json  = ListQuery::fromParams(['order' => '{"title":"asc","created":"desc"}']);
		$array = ListQuery::fromParams(['_order' => ['title' => 'desc']]);
		$both  = ListQuery::fromParams(['order' => ['a' => 'asc'], '_order' => ['b' => 'desc']]);

		$this->assertSame(['title' => 'asc', 'created' => 'desc'], $json->sort);
		$this->assertSame(['title' => 'desc'], $array->sort);
		$this->assertSame(['a' => 'asc'], $both->sort);
	}//end testOrderIsDecodedFromJsonOrKeptAsAnArray()

	public function testUnreadableOrderGivesNoSort(): void {
		$this->assertSame([], ListQuery::fromParams(['order' => 'not json'])->sort);
		$this->assertSame([], ListQuery::fromParams(['order' => '"title"'])->sort);
		$this->assertSame([], ListQuery::fromParams(['order' => 5])->sort);
	}//end testUnreadableOrderGivesNoSort()

	public function testSearchIsAScalarCastToStringAndNullOtherwise(): void {
		$this->assertSame('budget', ListQuery::fromParams(['_search' => 'budget'])->search);
		$this->assertSame('42', ListQuery::fromParams(['_search' => 42])->search);
		$this->assertNull(ListQuery::fromParams(['_search' => ['budget']])->search);
		$this->assertNull(ListQuery::fromParams(['search' => 'ignored'])->search);
	}//end testSearchIsAScalarCastToStringAndNullOtherwise()

	public function testUnboundedHasNoLimitAndKeepsFiltersAndSort(): void {
		$query = ListQuery::unbounded(filters: ['status' => 'open'], sort: ['title' => 'asc']);

		$this->assertSame(ListQuery::UNBOUNDED, $query->limit);
		$this->assertTrue($query->isUnbounded());
		$this->assertNull($query->limitOrNull());
		$this->assertNull($query->offset);
		$this->assertSame(['status' => 'open'], $query->filters);
		$this->assertSame(['title' => 'asc'], $query->sort);
		$this->assertNull($query->search);
	}//end testUnboundedHasNoLimitAndKeepsFiltersAndSort()

	public function testABoundedQueryReportsItsLimit(): void {
		$query = new ListQuery(limit: 50);

		$this->assertFalse($query->isUnbounded());
		$this->assertSame(50, $query->limitOrNull());
	}//end testABoundedQueryReportsItsLimit()

	public function testPageIsOneBasedAndFallsBackToOneWithoutAUsableLimit(): void {
		$this->assertSame(1, (new ListQuery(limit: 10, offset: 0))->page());
		$this->assertSame(1, (new ListQuery(limit: 10, offset: 9))->page());
		$this->assertSame(2, (new ListQuery(limit: 10, offset: 10))->page());
		$this->assertSame(3, (new ListQuery(limit: 10, offset: 25))->page());
		$this->assertSame(1, (new ListQuery(limit: 0, offset: 25))->page());
		$this->assertSame(1, (new ListQuery(limit: -5, offset: 25))->page());
	}//end testPageIsOneBasedAndFallsBackToOneWithoutAUsableLimit()
}//end class
