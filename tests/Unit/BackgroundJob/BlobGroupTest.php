<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\BackgroundJob\BlobGroup}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\BlobGroup;
use OCA\OpenRegister\BackgroundJob\ProcessingLogPrune;
use PHPUnit\Framework\TestCase;

/**
 * Small value objects of the background jobs: a blob group key and a prune record.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\BlobGroup
 * @covers \OCA\OpenRegister\BackgroundJob\ProcessingLogPrune
 */
class BlobGroupTest extends TestCase {

	/**
	 * A "register_schema" key splits into the two ids; rows are kept.
	 *
	 * @return void
	 */
	public function testKeySplitsIntoIds(): void {
		$group = new BlobGroup('12_34', [['id' => 1], ['id' => 2]]);

		$this->assertSame('12', $group->registerId);
		$this->assertSame('34', $group->schemaId);
		$this->assertSame(['registerId' => '12', 'schemaId' => '34'], $group->ids());
		$this->assertCount(2, $group->rows);

	}//end testKeySplitsIntoIds()

	/**
	 * Extra underscore parts beyond the second are ignored.
	 *
	 * @return void
	 */
	public function testExtraPartsAreIgnored(): void {
		$this->assertSame(['registerId' => 'a', 'schemaId' => 'b'], (new BlobGroup('a_b_c', []))->ids());

	}//end testExtraPartsAreIgnored()

	/**
	 * A prune records its retention, the ISO-8601 cutoff and the deleted count.
	 *
	 * @return void
	 */
	public function testProcessingLogPruneRecorded(): void {
		$prune = new ProcessingLogPrune('P30D', new DateTime('2026-01-02T03:04:05+00:00'), 17);

		$this->assertSame(
			['retention' => 'P30D', 'before' => '2026-01-02T03:04:05+00:00', 'deleted' => 17],
			$prune->recorded()
		);

	}//end testProcessingLogPruneRecorded()
}//end class
