<?php

/**
 * WalkCollector: the days a term walk examined, which of them counted, and when the record is cut off.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\Timer\WalkCollector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WalkCollector
 */
class WalkCollectorTest extends TestCase {

	/**
	 * Each examined day is recorded in order, counted only when it is a working day.
	 *
	 * @return void
	 */
	public function testRecordsEachDayAndWhetherItCounted(): void {
		$collector = new WalkCollector();

		$collector->examine(day: new DateTimeImmutable('2026-12-24'), kind: WalkCollector::WORKING);
		$collector->examine(day: new DateTimeImmutable('2026-12-25'), kind: 'holiday');
		$collector->examine(day: new DateTimeImmutable('2026-12-26'), kind: WalkCollector::WEEKEND);

		$this->assertSame(
			[
				['date' => '2026-12-24', 'kind' => 'working', 'counted' => true],
				['date' => '2026-12-25', 'kind' => 'holiday', 'counted' => false],
				['date' => '2026-12-26', 'kind' => 'weekend', 'counted' => false],
			],
			$collector->walk()
		);
		$this->assertSame(3, $collector->examinedCount());
		$this->assertFalse($collector->isTruncated());

	}//end testRecordsEachDayAndWhetherItCounted()

	/**
	 * The skipped days are the uncounted ones, reindexed from zero.
	 *
	 * @return void
	 */
	public function testSkippedListsOnlyTheUncountedDays(): void {
		$collector = new WalkCollector();
		$collector->examine(day: new DateTimeImmutable('2026-12-24'), kind: WalkCollector::WORKING);
		$collector->examine(day: new DateTimeImmutable('2026-12-25'), kind: 'holiday');

		$this->assertSame([['date' => '2026-12-25', 'kind' => 'holiday', 'counted' => false]], $collector->skipped());

	}//end testSkippedListsOnlyTheUncountedDays()

	/**
	 * A counted day is working; an uncounted one takes its rule, or weekend without one.
	 *
	 * @return void
	 */
	public function testKindOfNamesWhyADayDidOrDidNotCount(): void {
		$this->assertSame('working', WalkCollector::kindOf(counted: true, rule: 'holiday'));
		$this->assertSame('holiday', WalkCollector::kindOf(counted: false, rule: 'holiday'));
		$this->assertSame('weekend', WalkCollector::kindOf(counted: false, rule: null));

	}//end testKindOfNamesWhyADayDidOrDidNotCount()

	/**
	 * Past the row cap the walk keeps counting but stops recording, and says so.
	 *
	 * @return void
	 */
	public function testStopsRecordingAtTheRowCap(): void {
		$collector = new WalkCollector();
		$day       = new DateTimeImmutable('2026-01-01');
		for ($i = 0; $i < (WalkCollector::MAX_ROWS + 5); $i++) {
			$collector->examine(day: $day->modify('+'.$i.' days'), kind: WalkCollector::WORKING);
		}

		$this->assertCount(WalkCollector::MAX_ROWS, $collector->walk());
		$this->assertSame(WalkCollector::MAX_ROWS + 5, $collector->examinedCount());
		$this->assertTrue($collector->isTruncated());
		$this->assertSame('2026-01-01', $collector->walk()[0]['date']);

	}//end testStopsRecordingAtTheRowCap()
}//end class
