<?php

/**
 * "Grants nobody anything" must mean exactly that — no more, no less.
 *
 * This surface exists to make an unpopulated group visible. It therefore has
 * two ways to be useless: staying quiet about a group that really does grant
 * nobody anything, and crying wolf about one that is fine. The second is the
 * likelier bug, because `IGroup::count()` returns `int|bool` and hands back
 * `false` on a backend that cannot count — treating that as 0 would flag every
 * group on such a backend as empty and train the reader to ignore the report.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use OCA\OpenRegister\Service\Authorization\DeclaredGroupInventoryService;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Authorization\GroupReconciler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DeclaredGroupInventoryService}.
 */
class DeclaredGroupInventoryServiceTest extends TestCase {

	/**
	 * Build the service over a fixed declared set and membership state.
	 *
	 * @param string[] $declared The declared group ids.
	 * @param array $state The provisioner's inventory result.
	 *
	 * @return DeclaredGroupInventoryService The service under test.
	 */
	private function service(array $declared, array $state): DeclaredGroupInventoryService {
		$reconciler = $this->createMock(GroupReconciler::class);
		$reconciler->method('collectDeclared')->willReturn($declared);

		$provisioner = $this->createMock(GroupProvisioner::class);
		$provisioner->method('inventory')->willReturn($state);

		return new DeclaredGroupInventoryService($reconciler, $provisioner);
	}//end service()

	/**
	 * Each of the four states is classified, and only the right ones are
	 * flagged as granting nobody anything.
	 *
	 * @return void
	 */
	public function testClassifiesEveryMembershipState(): void {
		$report = $this->service(
			declared: ['bemand', 'leeg', 'ontbreekt', 'ontelbaar'],
			state: [
				'bemand' => [
					'exists' => true,
					'members' => 3,
				],
				'leeg' => [
					'exists' => true,
					'members' => 0,
				],
				'ontbreekt' => [
					'exists' => false,
					'members' => null,
				],
				'ontelbaar' => [
					'exists' => true,
					'members' => null,
				],
			]
		)->inventory();

		$by = [];
		foreach ($report['groups'] as $row) {
			$by[$row['group']] = $row;
		}

		$this->assertFalse($by['bemand']['grantsNobody'], 'a populated group grants somebody something');
		$this->assertTrue($by['leeg']['grantsNobody'], 'present with zero members');
		$this->assertTrue($by['ontbreekt']['grantsNobody'], 'absent entirely');

		$this->assertSame(4, $report['declared']);
		$this->assertSame(1, $report['missing']);
		$this->assertSame(1, $report['empty']);
		$this->assertSame(1, $report['unknown']);
	}//end testClassifiesEveryMembershipState()

	/**
	 * An uncountable backend is never reported as empty.
	 *
	 * The false-alarm direction: a group whose backend cannot count may be fully
	 * populated. It must not be counted toward `empty`, and must not be flagged
	 * as granting nobody anything.
	 *
	 * @return void
	 */
	public function testUncountableBackendIsNotAnAlarm(): void {
		$report = $this->service(
			declared: ['ontelbaar'],
			state: [
				'ontelbaar' => [
					'exists' => true,
					'members' => null,
				],
			]
		)->inventory();

		$this->assertSame(0, $report['empty'], 'unknown is not empty');
		$this->assertSame(1, $report['unknown']);
		$this->assertFalse(
			$report['groups'][0]['grantsNobody'],
			'an uncountable group may be fully populated — flagging it would train the reader to ignore the report'
		);
	}//end testUncountableBackendIsNotAnAlarm()

	/**
	 * A group the provisioner reported nothing about is treated as missing,
	 * not silently dropped.
	 *
	 * @return void
	 */
	public function testDeclaredButUnreportedGroupIsMissingNotOmitted(): void {
		$report = $this->service(declared: ['spookgroep'], state: [])->inventory();

		$this->assertCount(1, $report['groups']);
		$this->assertSame(1, $report['missing']);
		$this->assertTrue($report['groups'][0]['grantsNobody']);
	}//end testDeclaredButUnreportedGroupIsMissingNotOmitted()

	/**
	 * Positive control for the empty report.
	 *
	 * An implementation that always returned an empty inventory would satisfy
	 * every "nothing was wrongly flagged" assertion above.
	 *
	 * @return void
	 */
	public function testEmptyInventoryIsEarnedNotDefault(): void {
		$this->assertSame(0, $this->service(declared: [], state: [])->inventory()['declared']);

		$populated = $this->service(
			declared: ['iets'],
			state: [
				'iets' => [
					'exists' => true,
					'members' => 1,
				],
			]
		)->inventory();

		$this->assertSame(1, $populated['declared'], 'the same call shape reports a group when one is declared');
	}//end testEmptyInventoryIsEarnedNotDefault()

}//end class
