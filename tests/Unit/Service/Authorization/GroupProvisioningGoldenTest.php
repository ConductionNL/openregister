<?php

/**
 * Golden characterisation tests for GroupProvisioner and DeclaredGroupInventoryService.
 *
 * Pins, on the code before its provisioning and inventory loops were split into
 * named steps: the full created / existing / failed outcome of provision() in
 * declaration order (a failed creation and a failed existence check both refuse
 * only that group), every log line with its level, message and context, the
 * order of the group-manager calls, the live inventory of present, empty,
 * uncountable and absent groups, and the complete declared-group report with
 * its key order and counts.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Authorization\DeclaredGroupInventoryService;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Authorization\GroupReconciler;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class GroupProvisioningGoldenTest extends TestCase {

	/**
	 * Group-manager calls and log lines, in the order they happened.
	 *
	 * @var array<int, mixed>
	 */
	public array $trace = [];

	private function logger(): AbstractLogger {
		$test = $this;

		return new class ($test) extends AbstractLogger {
			public function __construct(private GroupProvisioningGoldenTest $test) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->trace[] = ['log' => (string)$level, 'message' => (string)$message, 'context' => $context];
			}
		};
	}

	public function testProvisionCreatesMissingGroupsAndRefusesOnlyTheOnesThatFail(): void {
		$createFailure = new RuntimeException('read-only backend');
		$checkFailure  = new RuntimeException('backend offline');

		$manager = $this->createMock(IGroupManager::class);
		$manager->method('groupExists')->willReturnCallback(
			function (string $gid) use ($checkFailure): bool {
				$this->trace[] = 'groupExists:' . $gid;
				if ($gid === 'kapot') {
					throw $checkFailure;
				}

				return $gid === 'bestaat';
			}
		);
		$manager->method('createGroup')->willReturnCallback(
			function (string $gid) use ($createFailure): ?IGroup {
				$this->trace[] = 'createGroup:' . $gid;
				if ($gid === 'stuk') {
					throw $createFailure;
				}

				return null;
			}
		);

		$result = (new GroupProvisioner($manager, $this->logger()))
			->provision(groups: ['nieuw', 'bestaat', 'stuk', 'kapot', 'ook-nieuw'], declaredBy: 'testapp');

		$this->assertSame(
			[
				'created' => ['nieuw', 'ook-nieuw'],
				'existing' => ['bestaat'],
				'failed' => ['stuk', 'kapot'],
			],
			$result
		);
		$this->assertSame(
			[
				'groupExists:nieuw',
				'createGroup:nieuw',
				'groupExists:bestaat',
				'groupExists:stuk',
				'createGroup:stuk',
				[
					'log' => 'error',
					'message' => '[GroupProvisioner] could not create group "stuk" declared by "testapp": read-only backend',
					'context' => ['exception' => $createFailure],
				],
				'groupExists:kapot',
				[
					'log' => 'error',
					'message' => '[GroupProvisioner] could not create group "kapot" declared by "testapp": backend offline',
					'context' => ['exception' => $checkFailure],
				],
				'groupExists:ook-nieuw',
				'createGroup:ook-nieuw',
				[
					'log' => 'info',
					'message' => '[GroupProvisioner] created 2 declared group(s) for "testapp": nieuw, ook-nieuw — each starts EMPTY and '
						. 'therefore denies every caller until an administrator adds members',
					'context' => [],
				],
			],
			$this->trace
		);
	}

	public function testProvisionWithNothingCreatedLogsNoAnnouncement(): void {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('groupExists')->willReturn(true);
		$manager->expects($this->never())->method('createGroup');

		$result = (new GroupProvisioner($manager, $this->logger()))
			->provision(groups: ['a', 'b'], declaredBy: 'testapp');

		$this->assertSame(['created' => [], 'existing' => ['a', 'b'], 'failed' => []], $result);
		$this->assertSame([], $this->trace);
	}

	public function testProvisionOfNoGroupsIsEmpty(): void {
		$result = (new GroupProvisioner($this->createMock(IGroupManager::class), $this->logger()))
			->provision(groups: [], declaredBy: 'testapp');

		$this->assertSame(['created' => [], 'existing' => [], 'failed' => []], $result);
		$this->assertSame([], $this->trace);
	}

	public function testInventoryReportsEveryLiveState(): void {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('get')->willReturnMap(
			[
				['bemand', $this->group(count: 3)],
				['leeg', $this->group(count: 0)],
				['ontelbaar', $this->group(count: false)],
				['waar', $this->group(count: true)],
				['weg', null],
			]
		);

		$inventory = (new GroupProvisioner($manager, $this->logger()))
			->inventory(groups: ['weg', 'bemand', 'leeg', 'ontelbaar', 'waar']);

		$this->assertSame(
			[
				'weg' => ['exists' => false, 'members' => null],
				'bemand' => ['exists' => true, 'members' => 3],
				'leeg' => ['exists' => true, 'members' => 0],
				'ontelbaar' => ['exists' => true, 'members' => null],
				'waar' => ['exists' => true, 'members' => null],
			],
			$inventory
		);
	}

	public function testDeclaredInventoryReportIsComplete(): void {
		$reconciler = $this->createMock(GroupReconciler::class);
		$reconciler->method('collectDeclared')->willReturn(['bemand', 'leeg', 'ontbreekt', 'ontelbaar', 'ongemeld', 'ook-leeg']);

		$provisioner = $this->createMock(GroupProvisioner::class);
		$provisioner->expects($this->once())->method('inventory')
			->with(['bemand', 'leeg', 'ontbreekt', 'ontelbaar', 'ongemeld', 'ook-leeg'])
			->willReturn(
				[
					'ook-leeg' => ['exists' => true, 'members' => 0],
					'bemand' => ['exists' => true, 'members' => 3],
					'leeg' => ['exists' => true, 'members' => 0],
					'ontbreekt' => ['exists' => false, 'members' => null],
					'ontelbaar' => ['exists' => true, 'members' => null],
				]
			);

		$report = (new DeclaredGroupInventoryService($reconciler, $provisioner))->inventory();

		$this->assertSame(
			[
				'groups' => [
					['group' => 'bemand', 'exists' => true, 'members' => 3, 'grantsNobody' => false],
					['group' => 'leeg', 'exists' => true, 'members' => 0, 'grantsNobody' => true],
					['group' => 'ontbreekt', 'exists' => false, 'members' => null, 'grantsNobody' => true],
					['group' => 'ontelbaar', 'exists' => true, 'members' => null, 'grantsNobody' => false],
					['group' => 'ongemeld', 'exists' => false, 'members' => null, 'grantsNobody' => true],
					['group' => 'ook-leeg', 'exists' => true, 'members' => 0, 'grantsNobody' => true],
				],
				'declared' => 6,
				'missing' => 2,
				'empty' => 2,
				'unknown' => 1,
			],
			$report
		);
	}

	private function group(int|bool $count): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn($count);

		return $group;
	}
}//end class
