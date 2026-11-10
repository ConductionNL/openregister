<?php

/**
 * Unit test for LinkedEventMerge.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\LinkedEventMerge;
use PHPUnit\Framework\TestCase;

/**
 * Pins how link-table rows and X-OR-* scan events merge.
 */
class LinkedEventMergeTest extends TestCase {

	public function testEventWithoutUidIsSkipped(): void {
		$merge = new LinkedEventMerge();
		$merge->addXorEvent(event: ['uid' => '', 'summary' => 'x'], calendarUri: 'cal');
		$merge->addXorEvent(event: ['summary' => 'y'], calendarUri: 'cal');

		$this->assertSame([], $merge->rows());
	}//end testEventWithoutUidIsSkipped()

	public function testUnknownEventIsAddedAsXorOnly(): void {
		$merge = new LinkedEventMerge();
		$merge->addXorEvent(event: ['uid' => 'u1', 'summary' => 'Live'], calendarUri: 'cal');

		$this->assertSame([['uid' => 'u1', 'summary' => 'Live', 'source' => 'xor-only', 'calendarUri' => 'cal']], $merge->rows());
	}//end testUnknownEventIsAddedAsXorOnly()

	public function testSameKeyMarksBothAndRefreshesNonEmptyFields(): void {
		$merge = new LinkedEventMerge();
		$merge->addLinkRow(key: 'cal|u1', payload: ['source' => 'link-table', 'summary' => 'Old', 'location' => 'Here']);
		$merge->addXorEvent(event: ['uid' => 'u1', 'summary' => 'New', 'location' => '', 'extra' => 'ignored'], calendarUri: 'cal');

		$this->assertSame([['source' => 'both', 'summary' => 'New', 'location' => 'Here']], $merge->rows());
	}//end testSameKeyMarksBothAndRefreshesNonEmptyFields()

	public function testUidOnlyMatchMarksFirstEntryBoth(): void {
		$merge = new LinkedEventMerge();
		$merge->addLinkRow(key: 'a|u1', payload: ['source' => 'link-table', 'summary' => 'A']);
		$merge->addLinkRow(key: 'b|u1', payload: ['source' => 'link-table', 'summary' => 'B']);
		$merge->addXorEvent(event: ['uid' => 'u1', 'summary' => 'Live'], calendarUri: '');

		$this->assertSame(
			[['source' => 'both', 'summary' => 'A'], ['source' => 'link-table', 'summary' => 'B']],
			$merge->rows()
		);
	}//end testUidOnlyMatchMarksFirstEntryBoth()
}//end class
