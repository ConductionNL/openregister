<?php

/**
 * Unit test for ActivityFilter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ActivityFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ActivityFilter
 */
class ActivityFilterTest extends TestCase {

	public function testCarriesItsFiltersWithoutACursor(): void {
		$filter = new ActivityFilter('obj-1', 'update', 'bob', 50);

		$this->assertSame('obj-1', $filter->objectUuid);
		$this->assertSame('update', $filter->type);
		$this->assertSame('bob', $filter->actor);
		$this->assertSame(50, $filter->after);
		$this->assertNull($filter->cursor());
	}//end testCarriesItsFiltersWithoutACursor()

	public function testWithCursorLeavesTheOriginalUnpaged(): void {
		$filter = new ActivityFilter('obj-1', null, null, null);
		$paged = $filter->withCursor(250);

		$this->assertSame(250, $paged->cursor());
		$this->assertNull($filter->cursor());
		$this->assertSame('obj-1', $paged->objectUuid);
		$this->assertNull($paged->withCursor(null)->cursor());
	}//end testWithCursorLeavesTheOriginalUnpaged()
}//end class
