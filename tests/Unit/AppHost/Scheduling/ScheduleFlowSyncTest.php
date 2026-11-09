<?php

/**
 * Tests for ScheduleFlowSync: a manifest's invalid entries are each refused, and with nothing valid left the stored flows are removed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleAuditTrail;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowSync;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowTenantMembershipInterface;
use PHPUnit\Framework\TestCase;

/**
 * ScheduleFlowSync over its mocked collaborators.
 */
class ScheduleFlowSyncTest extends TestCase {

	/**
	 * A release of application "crm" with the given manifest.
	 *
	 * @param array<string, mixed> $manifest The production manifest.
	 *
	 * @return ScheduleRelease
	 */
	private function release(array $manifest): ScheduleRelease {
		$object = new ObjectEntity();
		$object->setUuid('app-uuid');
		$object->setObject(['slug' => 'crm']);
		$object->setOrganisation('org-1');
		return new ScheduleRelease($object, $manifest, 'admin');
	}

	public function testEachInvalidEntryIsRefusedAndTheStoredFlowsAreRemoved(): void {
		$release = $this->release(['schedules' => ['not-an-object', ['id' => 'bad', 'cron' => 'nope', 'action' => 'openconnector:synchronization']]]);
		$stale = new Flow();

		$store = $this->createMock(ScheduleFlowStore::class);
		$store->expects($this->once())->method('ofApplication')->with('app-uuid')->willReturn(['x' => $stale]);
		$store->expects($this->once())->method('remove')->with($this->identicalTo($stale));
		$store->expects($this->never())->method('write');
		$refusals = $this->createMock(ScheduleRefusals::class);
		$reasons = [];
		$refusals->expects($this->exactly(2))->method('refuse')->willReturnCallback(
			function (ScheduleRelease $refused, string $reason) use ($release, &$reasons): void {
				$this->assertSame($release, $refused);
				$reasons[] = $reason;
			}
		);
		// With no valid schedule left, membership is never asked.
		$membership = $this->createMock(FlowTenantMembershipInterface::class);
		$membership->expects($this->never())->method('isMember');

		(new ScheduleFlowSync($store, $membership, $this->createMock(ScheduleAuditTrail::class), $refusals))->apply($release);

		$this->assertSame(['Schedule #0 is not an object.', 'Schedule "bad" has an unparseable cron expression.'], $reasons);
	}

	public function testAManifestWithoutSchedulesRefusesNothingAndRemovesStoredFlows(): void {
		$release = $this->release([]);
		$stale = new Flow();

		$store = $this->createMock(ScheduleFlowStore::class);
		$store->expects($this->once())->method('ofApplication')->with('app-uuid')->willReturn(['x' => $stale]);
		$store->expects($this->once())->method('remove')->with($this->identicalTo($stale));
		$refusals = $this->createMock(ScheduleRefusals::class);
		$refusals->expects($this->never())->method('refuse');

		(new ScheduleFlowSync($store, $this->createMock(FlowTenantMembershipInterface::class), $this->createMock(ScheduleAuditTrail::class), $refusals))->apply($release);
	}
}
