<?php

/**
 * A DSAR erasure run stamps each object, collects failures and reports whether it completed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DsarErasureRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\DsarErasureRun
 */
class DsarErasureRunTest extends TestCase {

	public function testStampWritesTheDeletionDataAndTheActivityWhenSet(): void {
		$run = new DsarErasureRun(deletionData: ['reason' => 'dsar']);
		$run->activityUuid = 'act-1';

		$object = new ObjectEntity();
		$run->stamp($object);

		$this->assertSame(['reason' => 'dsar'], $object->getDeleted());
		$this->assertSame('act-1', $object->getProcessingActivityId());
	}//end testStampWritesTheDeletionDataAndTheActivityWhenSet()

	public function testStampLeavesTheActivityUntouchedWhenItIsNotSet(): void {
		$run = new DsarErasureRun(deletionData: ['reason' => 'dsar']);

		$object = new ObjectEntity();
		$run->stamp($object);

		$this->assertSame(['reason' => 'dsar'], $object->getDeleted());
		$this->assertNull($object->getProcessingActivityId());
	}//end testStampLeavesTheActivityUntouchedWhenItIsNotSet()

	public function testAnEmptyRunIsComplete(): void {
		$run = new DsarErasureRun();
		$run->erased = ['a', 'b'];

		$this->assertSame(
			['erased' => ['a', 'b'], 'failed' => [], 'withheld' => [], 'complete' => true, 'failedCount' => 0, 'withheldCount' => 0],
			$run->outcome()
		);
	}//end testAnEmptyRunIsComplete()

	public function testAFailureOrAWithholdingMakesTheRunIncomplete(): void {
		$run = new DsarErasureRun();
		$run->fail(['id' => 7], 'locked');
		$run->withheld = [['id' => 9]];

		$outcome = $run->outcome();
		$this->assertFalse($outcome['complete']);
		$this->assertSame(1, $outcome['failedCount']);
		$this->assertSame(1, $outcome['withheldCount']);
		$this->assertSame([['object' => ['id' => 7], 'error' => 'locked']], $outcome['failed']);
	}//end testAFailureOrAWithholdingMakesTheRunIncomplete()
}//end class
