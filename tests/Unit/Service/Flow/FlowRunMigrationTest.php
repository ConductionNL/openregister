<?php

/**
 * FlowRunMigration carries a migration request and a plan applied to a copy, never to the original.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowRunMigration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunMigration
 */
class FlowRunMigrationTest extends TestCase {
	/**
	 * A fresh request has no mapping and is a real (not dry) run.
	 *
	 * @return void
	 */
	public function testFreshRequestHasNoPlan(): void {
		$migration = new FlowRunMigration(runUuid: 'run-1', targetVersion: 3, reason: 'fix', actor: 'admin');
		$this->assertSame([], $migration->mapping());
		$this->assertFalse($migration->isDryRun());
		$this->assertSame(['run-1', 3, 'fix', 'admin'], [$migration->runUuid, $migration->targetVersion, $migration->reason, $migration->actor]);
	}

	/**
	 * WithPlan returns a copy holding the mapping and dry-run flag, leaving the original untouched.
	 *
	 * @return void
	 */
	public function testWithPlanReturnsPlannedCopy(): void {
		$migration = new FlowRunMigration(runUuid: 'run-1', targetVersion: 3, reason: 'fix', actor: 'admin');
		$planned   = $migration->withPlan(mapping: ['old' => 'new'], dryRun: true);

		$this->assertNotSame($migration, $planned);
		$this->assertSame(['old' => 'new'], $planned->mapping());
		$this->assertTrue($planned->isDryRun());
		$this->assertSame('run-1', $planned->runUuid);
		$this->assertSame([], $migration->mapping());
		$this->assertFalse($migration->isDryRun());
	}

	/**
	 * A plan with dryRun false is a real run.
	 *
	 * @return void
	 */
	public function testWithPlanRealRun(): void {
		$planned = (new FlowRunMigration(runUuid: 'r', targetVersion: 1, reason: '', actor: ''))->withPlan(mapping: ['a' => 'b'], dryRun: false);
		$this->assertFalse($planned->isDryRun());
	}
}
