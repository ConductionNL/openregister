<?php

/**
 * OpenRegister - the state of one bulk save chunk.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

use OCA\OpenRegister\Service\Object\SaveObjects\BulkChunkRun;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun;
use PHPUnit\Framework\TestCase;

/**
 * A chunk starts with the given result and records its phases in order.
 */
class BulkChunkRunTest extends TestCase {

	/**
	 * A new chunk holds its result and no timings or event targets.
	 *
	 * @return void
	 */
	public function testANewChunkHoldsItsResult(): void {
		$chunk = new BulkChunkRun(result: ['saved' => []], run: new BulkSaveRun());

		$this->assertSame(['saved' => []], $chunk->result);
		$this->assertSame([], $chunk->phaseMs);
		$this->assertSame([], $chunk->eventTargets);
		$this->assertLessThanOrEqual(microtime(true), $chunk->startTime);
	}//end testANewChunkHoldsItsResult()

	/**
	 * Every finished phase is recorded, in order, as non-negative milliseconds.
	 *
	 * @return void
	 */
	public function testPhasesAreRecordedInOrder(): void {
		$chunk = new BulkChunkRun(result: [], run: new BulkSaveRun());
		$chunk->finishPhase(phase: 'transform', start: microtime(true));
		$chunk->finishPhase(phase: 'guards', start: microtime(true));

		$this->assertSame(['transform', 'guards'], array_keys($chunk->phaseMs));
		$this->assertGreaterThanOrEqual(0.0, $chunk->phaseMs['guards']);
	}//end testPhasesAreRecordedInOrder()
}//end class
