<?php

/**
 * FlowTimerSweepTally: the counters one sweep reports, and when a full batch marks it truncated.
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
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepTally;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepTally
 */
class FlowTimerSweepTallyTest extends TestCase {

	/**
	 * A fresh tally reports every counter at zero and is not truncated.
	 *
	 * @return void
	 */
	public function testAFreshTallyReportsNothing(): void {
		$now   = new DateTimeImmutable('2026-11-12 10:00:00');
		$tally = new FlowTimerSweepTally(now: $now, batch: 50);

		$this->assertSame($now, $tally->now);
		$this->assertSame(50, $tally->batch);
		$this->assertSame(
			['expiriesFired' => 0, 'rungsFired' => 0, 'taskTimeouts' => 0, 'truncated' => false, 'errors' => 0],
			$tally->result()
		);

	}//end testAFreshTallyReportsNothing()

	/**
	 * Additions accumulate per counter and leave the others alone.
	 *
	 * @return void
	 */
	public function testAdditionsAccumulatePerCounter(): void {
		$tally = new FlowTimerSweepTally(now: new DateTimeImmutable(), batch: 10);

		$tally->add(counter: 'expiriesFired', amount: 2);
		$tally->add(counter: 'expiriesFired', amount: 3);
		$tally->add(counter: 'errors', amount: 1);

		$result = $tally->result();
		$this->assertSame(5, $result['expiriesFired']);
		$this->assertSame(1, $result['errors']);
		$this->assertSame(0, $result['rungsFired']);
		$this->assertSame(0, $result['taskTimeouts']);

	}//end testAdditionsAccumulatePerCounter()

	/**
	 * A scan that found fewer rows than the batch leaves the sweep complete.
	 *
	 * @return void
	 */
	public function testAShortScanIsNotTruncated(): void {
		$tally = new FlowTimerSweepTally(now: new DateTimeImmutable(), batch: 10);

		$tally->scanned(found: 9);

		$this->assertFalse($tally->result()['truncated']);

	}//end testAShortScanIsNotTruncated()

	/**
	 * A scan that filled the batch marks the sweep truncated, and a later short scan does not clear it.
	 *
	 * @return void
	 */
	public function testAFullScanTruncatesForTheRestOfTheSweep(): void {
		$tally = new FlowTimerSweepTally(now: new DateTimeImmutable(), batch: 10);

		$tally->scanned(found: 10);
		$tally->scanned(found: 0);

		$this->assertTrue($tally->result()['truncated']);

	}//end testAFullScanTruncatesForTheRestOfTheSweep()
}//end class
