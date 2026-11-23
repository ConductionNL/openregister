<?php

/**
 * ListQuery reads a listing the way the object controllers read it today.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Controller\Support\ObjectRequestParams;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListQueryTest extends TestCase {

	/**
	 * Without arguments the listing is the controllers' default.
	 *
	 * @return void
	 */
	public function testDefaultsAreTheControllerDefaults(): void {
		$query = new ListQuery();

		$this->assertSame(20, $query->limit);
		$this->assertNull($query->offset);
		$this->assertSame([], $query->filters);
		$this->assertSame([], $query->sort);
		$this->assertNull($query->search);
		$this->assertSame(1, $query->page());
	}//end testDefaultsAreTheControllerDefaults()

	/**
	 * An empty request gives the defaults.
	 *
	 * @return void
	 */
	public function testFromParamsOfAnEmptyRequestGivesTheDefaults(): void {
		$this->assertEquals(new ListQuery(), ListQuery::fromParams([]));
	}//end testFromParamsOfAnEmptyRequestGivesTheDefaults()

	/**
	 * Underscore names are read when the plain name is absent.
	 *
	 * @return void
	 */
	public function testFromParamsReadsTheUnderscoreNames(): void {
		$params = ['_limit' => '10', '_offset' => '30', '_order' => '{"title":"ASC"}', '_search' => 'zaak', 'status' => 'open'];
		$query  = ListQuery::fromParams($params);

		$this->assertSame(10, $query->limit);
		$this->assertSame(30, $query->offset);
		$this->assertSame(['title' => 'ASC'], $query->sort);
		$this->assertSame('zaak', $query->search);
		$this->assertSame($params, $query->filters);
		$this->assertSame(4, $query->page());
	}//end testFromParamsReadsTheUnderscoreNames()

	/**
	 * The plain name wins over the underscore name.
	 *
	 * @return void
	 */
	public function testFromParamsPrefersThePlainName(): void {
		$query = ListQuery::fromParams(['limit' => 5, '_limit' => 50, 'offset' => 0, '_offset' => 10, 'order' => ['a' => 'DESC'], '_order' => ['b' => 'ASC']]);

		$this->assertSame(5, $query->limit);
		$this->assertSame(0, $query->offset);
		$this->assertSame(['a' => 'DESC'], $query->sort);
	}//end testFromParamsPrefersThePlainName()

	/**
	 * Without an offset, the page gives it; page() returns that page again.
	 *
	 * @return void
	 */
	public function testFromParamsDerivesTheOffsetFromThePage(): void {
		$query = ListQuery::fromParams(['_page' => '3', '_limit' => '25']);

		$this->assertSame(50, $query->offset);
		$this->assertSame(3, $query->page());
	}//end testFromParamsDerivesTheOffsetFromThePage()

	/**
	 * An unreadable sort is empty and a non-scalar search is absent.
	 *
	 * @return void
	 */
	public function testFromParamsDropsUnreadableSortAndSearch(): void {
		$query = ListQuery::fromParams(['_order' => 'not json', '_search' => ['x']]);

		$this->assertSame([], $query->sort);
		$this->assertNull($query->search);
	}//end testFromParamsDropsUnreadableSortAndSearch()

	/**
	 * A page size of zero has one page.
	 *
	 * @return void
	 */
	public function testPageWithoutAPageSizeIsOne(): void {
		$this->assertSame(1, (new ListQuery(limit: 0, offset: 40))->page());
	}//end testPageWithoutAPageSizeIsOne()

	/**
	 * Limit, offset, sort and search agree with ObjectRequestParams::listing().
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	#[DataProvider('requests')]
	public function testFromParamsAgreesWithTheControllerListing(array $params): void {
		$listing = (new ObjectRequestParams())->listing($params);
		$query   = ListQuery::fromParams($params);

		$this->assertSame($listing['limit'], $query->limit);
		$this->assertSame($listing['offset'], $query->offset);
		$this->assertSame($listing['sort'], $query->sort);
		$this->assertSame($listing['filters'], $query->filters);
		$this->assertSame($listing['_search'], $query->search);
	}//end testFromParamsAgreesWithTheControllerListing()

	/**
	 * Requests to compare.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function requests(): array {
		return [
			'empty'      => [[]],
			'underscore' => [['_limit' => '10', '_offset' => '20', '_search' => 'x', '_order' => '{"a":"ASC"}']],
			'plain'      => [['limit' => 7, 'page' => 2, 'order' => ['b' => 'DESC']]],
			'page only'  => [['_page' => '4']],
		];
	}//end requests()

	/**
	 * unbounded() without arguments has no page size, filters or sort.
	 *
	 * @return void
	 */
	public function testUnboundedWithoutArguments(): void {
		$query = ListQuery::unbounded();

		$this->assertSame(ListQuery::UNBOUNDED, $query->limit);
		$this->assertSame(PHP_INT_MAX, $query->limit);
		$this->assertNull($query->offset);
		$this->assertSame([], $query->filters);
		$this->assertSame([], $query->sort);
		$this->assertNull($query->search);
		$this->assertTrue($query->isUnbounded());
		$this->assertNull($query->limitOrNull());
	}//end testUnboundedWithoutArguments()

	/**
	 * unbounded() keeps the filters and sort it is given.
	 *
	 * @return void
	 */
	public function testUnboundedKeepsFiltersAndSort(): void {
		$query = ListQuery::unbounded(filters: ['status' => 'open'], sort: ['created' => 'DESC']);

		$this->assertTrue($query->isUnbounded());
		$this->assertSame(['status' => 'open'], $query->filters);
		$this->assertSame(['created' => 'DESC'], $query->sort);
		$this->assertNull($query->offset);
		$this->assertNull($query->search);
	}//end testUnboundedKeepsFiltersAndSort()

	/**
	 * A bounded listing is not unbounded and reads its own limit.
	 *
	 * @return void
	 */
	public function testBoundedListingReadsItsLimit(): void {
		$default = new ListQuery();
		$five    = new ListQuery(limit: 5);

		$this->assertFalse($default->isUnbounded());
		$this->assertSame(20, $default->limitOrNull());
		$this->assertFalse($five->isUnbounded());
		$this->assertSame(5, $five->limitOrNull());
	}//end testBoundedListingReadsItsLimit()

	/**
	 * An unbounded listing starts on page 1, with or without an offset.
	 *
	 * @return void
	 */
	public function testUnboundedPageIsOne(): void {
		$this->assertSame(1, ListQuery::unbounded()->page());
		$this->assertSame(1, (new ListQuery(limit: ListQuery::UNBOUNDED, offset: 0))->page());
		$this->assertSame(1, (new ListQuery(limit: ListQuery::UNBOUNDED, offset: 40))->page());
	}//end testUnboundedPageIsOne()

	/**
	 * A request can never ask for an unbounded listing.
	 *
	 * @return void
	 */
	public function testFromParamsIsNeverUnbounded(): void {
		$zero  = ListQuery::fromParams(['limit' => 0]);
		$empty = ListQuery::fromParams([]);

		$this->assertFalse($zero->isUnbounded());
		$this->assertSame(0, $zero->limitOrNull());
		$this->assertFalse($empty->isUnbounded());
		$this->assertSame(20, $empty->limitOrNull());
	}//end testFromParamsIsNeverUnbounded()
}//end class
