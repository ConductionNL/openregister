<?php

/**
 * Unit test for MovedJobState.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Operations;

use OCA\OpenRegister\Db\JobRunMapper;
use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCA\OpenRegister\Service\Operations\MovedJobState;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Operations\MovedJobState
 */
final class MovedJobStateTest extends TestCase {

	/**
	 * Both the schedule and the run log move from the old name to the new one.
	 *
	 * @return void
	 */
	public function testScheduleAndRunLogFollowTheJob(): void {
		$schedules = $this->createMock(JobScheduleService::class);
		$schedules->expects($this->once())->method('moveSchedule')->with('Old\\Job', 'New\\Job');

		$runs = $this->createMock(JobRunMapper::class);
		$runs->expects($this->once())->method('renameJobClass')->with('Old\\Job', 'New\\Job')->willReturn(2);

		(new MovedJobState(schedules: $schedules, runs: $runs))->carry(from: 'Old\\Job', to: 'New\\Job');
	}//end testScheduleAndRunLogFollowTheJob()
}//end class
