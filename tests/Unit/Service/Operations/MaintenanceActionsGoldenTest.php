<?php

/**
 * Golden test pinning the job classes the console's maintenance actions start.
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

use OCA\OpenRegister\Service\Operations\OperationsJobsService;
use PHPUnit\Framework\TestCase;

/**
 * Run now instantiates a class named here, so the list is pinned as plain strings: what lands on the job list must
 * not change when the job classes are reached through another name.
 *
 * @covers \OCA\OpenRegister\Service\Operations\OperationsJobsService
 */
final class MaintenanceActionsGoldenTest extends TestCase {

	/**
	 * Each maintenance action names exactly this job class, and there are no others.
	 *
	 * @return void
	 */
	public function testEachActionNamesItsJobClass(): void {
		$this->assertSame(
			[
				'search-index-rebuild' => 'OCA\OpenRegister\Search\BackgroundJob\SearchIndexRebuildJob',
				'cache-clear-and-warm' => 'OCA\OpenRegister\BackgroundJob\CacheClearAndWarmJob',
				'consistency-check' => 'OCA\OpenRegister\BackgroundJob\ConsistencyCheckJob',
			],
			OperationsJobsService::MAINTENANCE_ACTIONS
		);
	}//end testEachActionNamesItsJobClass()
}//end class
