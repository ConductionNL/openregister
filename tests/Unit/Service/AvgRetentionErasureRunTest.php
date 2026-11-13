<?php

/**
 * An AVG retention erasure run stamps each object with the deletion data and the activity, and reports its tallies.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AvgRetentionErasureRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\AvgRetentionErasureRun
 */
class AvgRetentionErasureRunTest extends TestCase {

	public function testStampWritesTheDeletionDataAndTheActivity(): void {
		$run = new AvgRetentionErasureRun(deletionData: ['reason' => 'retention'], activityUuid: 'act-9');

		$object = new ObjectEntity();
		$run->stamp($object);

		$this->assertSame(['reason' => 'retention'], $object->getDeleted());
		$this->assertSame('act-9', $object->getProcessingActivityId());
	}//end testStampWritesTheDeletionDataAndTheActivity()

	public function testOutcomeReportsTheErasedCountAndWithheld(): void {
		$run = new AvgRetentionErasureRun(deletionData: [], activityUuid: 'act-9');
		$run->erased = 3;
		$run->withheld = [['id' => 1, 'reason' => 'legal-hold']];

		$this->assertSame(['erased' => 3, 'withheld' => [['id' => 1, 'reason' => 'legal-hold']]], $run->outcome());
	}//end testOutcomeReportsTheErasedCountAndWithheld()
}//end class
