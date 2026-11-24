<?php

/**
 * EmailListPaging: the offset and next-page cursor of a paged mail listing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\Providers\EmailListPaging;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Integration\Providers\EmailListPaging
 */
class EmailListPagingTest extends TestCase {

	public function testWithoutALimitThereIsNoPaging(): void {
		$paging = new EmailListPaging(['_page' => 3]);

		$this->assertNull($paging->limit());
		$this->assertNull($paging->offset());
		$this->assertNull($paging->nextCursor(10, 100));
	}

	public function testTheOffsetIsThePageTimesTheLimit(): void {
		$paging = new EmailListPaging(['_limit' => '20', '_page' => 2]);

		$this->assertSame(20, $paging->limit());
		$this->assertSame(40, $paging->offset());
	}

	public function testANegativeOrMissingPageIsTheFirst(): void {
		$this->assertSame(0, (new EmailListPaging(['_limit' => 10, '_page' => -4]))->offset());
		$this->assertSame(0, (new EmailListPaging(['_limit' => 10]))->offset());
	}

	public function testTheNextCursorPointsAtTheNextPageUntilEverythingWasSeen(): void {
		$paging = new EmailListPaging(['_limit' => 10, '_page' => 1]);

		$this->assertSame('2', $paging->nextCursor(10, 21));
		$this->assertNull($paging->nextCursor(10, 20));
		$this->assertNull($paging->nextCursor(5, 12));
	}
}
