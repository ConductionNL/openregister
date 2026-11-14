<?php

/**
 * The query config turns request parameters into paging, filters, sort and a date window.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Service\SearchTrailQueryConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SearchTrailQueryConfig
 */
class SearchTrailQueryConfigTest extends TestCase {

	public function testDefaultsWhenNothingIsGiven(): void {
		$result = (new SearchTrailQueryConfig())->process([]);

		$this->assertSame(20, $result['limit']);
		$this->assertSame(0, $result['offset']);
		$this->assertSame(1, $result['page']);
		$this->assertSame(['created' => 'DESC'], $result['sort']);
		$this->assertSame([], $result['filters']);
		$this->assertNull($result['search']);
		$this->assertNull($result['from']);
		$this->assertNull($result['to']);
	}//end testDefaultsWhenNothingIsGiven()

	public function testPageIsTurnedIntoAnOffset(): void {
		$result = (new SearchTrailQueryConfig())->process(['page' => 3, 'limit' => 10]);

		$this->assertSame(10, $result['limit']);
		$this->assertSame(20, $result['offset']);
		$this->assertSame(3, $result['page']);
	}//end testPageIsTurnedIntoAnOffset()

	public function testOffsetIsTurnedIntoAPage(): void {
		$result = (new SearchTrailQueryConfig())->process(['_offset' => 45, '_limit' => 20]);

		$this->assertSame(45, $result['offset']);
		// floor(45 / 20) + 1 = 3 as a float, the value the code returns here.
		$this->assertSame(3.0, $result['page']);
	}//end testOffsetIsTurnedIntoAPage()

	public function testSortSearchDatesAndFiltersAreExtracted(): void {
		$result = (new SearchTrailQueryConfig())->process(
			['sort' => 'title', 'order' => 'ASC', 'search' => 'abc', 'from' => '2026-01-01', 'to' => '2026-02-01', 'status' => 'open', '_route' => 'x', '_hidden' => 'y']
		);

		$this->assertSame(['title' => 'ASC'], $result['sort']);
		$this->assertSame('abc', $result['search']);
		$this->assertEquals(new DateTime('2026-01-01'), $result['from']);
		$this->assertEquals(new DateTime('2026-02-01'), $result['to']);
		$this->assertSame(['status' => 'open'], $result['filters']);
	}//end testSortSearchDatesAndFiltersAreExtracted()

	public function testAnUnparseableDateBecomesNull(): void {
		$result = (new SearchTrailQueryConfig())->process(['from' => 'not-a-date']);

		$this->assertNull($result['from']);
	}//end testAnUnparseableDateBecomesNull()
}//end class
