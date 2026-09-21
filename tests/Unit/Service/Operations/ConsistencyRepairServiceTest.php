<?php

/**
 * Unit tests for ConsistencyRepairService — the repair as its own act.
 *
 * The property that makes this a separate act rather than a second button is
 * that the administrator authorises a LIST, and that list is what gets acted
 * on. A repair that re-evaluated the condition at write time would act on
 * whatever matches now, which is not what anybody agreed to, so the delete is
 * asserted to be keyed on the ids the plan showed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ConsistencyRepairServiceTest extends TestCase {

	/**
	 * The ids the delete was keyed on.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $deletedIds = null;

	/**
	 * The table the delete named.
	 *
	 * @var string|null
	 */
	private ?string $deletedTable = null;

	/**
	 * How many rows the delete claimed.
	 *
	 * @var integer
	 */
	private int $deletedRows = 0;

	/**
	 * The acts the recorder was handed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * A connection whose delete remembers what it was asked to remove.
	 *
	 * @return IDBConnection The double.
	 */
	private function connection(): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('id IN (:ids)');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('delete')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->deletedTable = $table;

				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				if (is_array($value) === true) {
					$this->deletedIds = $value;
				}

				return ':ids';
			}
		);
		$qb->method('where')->willReturnSelf();
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->deletedRows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}

	/**
	 * The service, over a check reporting the given rows.
	 *
	 * @param array<int, array<string, mixed>>|null $objects What the check found, or null for no such probe.
	 *
	 * @return ConsistencyRepairService The service under test.
	 */
	private function service(?array $objects): ConsistencyRepairService {
		$check = $this->createMock(ConsistencyCheckService::class);

		if ($objects === null) {
			$check->method('repairPlan')->willReturn(null);
		} else {
			$check->method('repairPlan')->willReturn(
				[
					'slug' => 'orphan-relations',
					'table' => 'openregister_object_relations',
					'action' => 'Delete the relation rows.',
				]
			);
			$check->method('checkOne')->willReturn(
				[
					'slug' => 'orphan-relations',
					'count' => count($objects),
					'objects' => $objects,
				]
			);
		}

		$recorder = $this->createMock(JobRunRecorder::class);
		$recorder->method('recordAct')->willReturnCallback(
			function (string $jobClass, string $actor, array $details, ?string $message = null): null {
				$this->recorded[] = ['job' => $jobClass, 'actor' => $actor, 'details' => $details];

				return null;
			}
		);

		return new ConsistencyRepairService($this->connection(), $check, $recorder);
	}

	/**
	 * The plan names the objects the repair will touch, before it touches any.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testThePlanNamesWhatWouldChangeAndChangesNothing(): void {
		$plan = $this->service([['id' => 7], ['id' => 9]])->plan('orphan-relations');

		$this->assertSame(2, $plan['count']);
		$this->assertSame('openregister_object_relations', $plan['table']);
		$this->assertSame('Delete the relation rows.', $plan['action']);
		$this->assertNull($this->deletedIds, 'Planning the repair deleted rows.');
		$this->assertSame([], $this->recorded);
	}

	/**
	 * Applying it deletes exactly the rows the plan showed, and records the
	 * act with the actor and the objects.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testTheRepairActsOnTheRowsTheAdministratorWasShownAndIsRecorded(): void {
		$this->deletedRows = 2;

		$applied = $this->service([['id' => 7], ['id' => 9]])->apply('orphan-relations', 'noor');

		$this->assertSame(2, $applied['changed']);
		$this->assertSame([7, 9], $this->deletedIds);
		$this->assertSame('openregister_object_relations', $this->deletedTable);

		$this->assertCount(1, $this->recorded);
		$this->assertSame('noor', $this->recorded[0]['actor']);
		$this->assertSame('orphan-relations', $this->recorded[0]['details']['check']);
		$this->assertSame([['id' => 7], ['id' => 9]], $this->recorded[0]['details']['objects']);
	}

	/**
	 * A repair nobody is named for is refused, because a repair nobody is
	 * named for is a repair nobody can be asked about.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testARepairWithoutAnActorIsRefusedAndWritesNothing(): void {
		$refused = null;

		try {
			$this->service([['id' => 7]])->apply('orphan-relations', '');
		} catch (RepairRefusedException $refusal) {
			$refused = $refusal;
		}

		$this->assertNotNull($refused, 'An unattributable repair was applied.');
		$this->assertSame('no-actor', $refused->getReason());
		$this->assertNull($this->deletedIds);
	}

	/**
	 * A check this instance does not have cannot be repaired.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testAnUnknownCheckIsRefused(): void {
		$this->expectException(RepairRefusedException::class);

		$this->service(null)->apply('no-such-check', 'noor');
	}

	/**
	 * Nothing to repair writes nothing and records nothing: an act recorded
	 * for a repair that changed no row is an audit trail that cries wolf.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testACleanCheckRepairsNothingAndRecordsNothing(): void {
		$applied = $this->service([])->apply('orphan-relations', 'noor');

		$this->assertSame(0, $applied['changed']);
		$this->assertFalse($applied['recorded']);
		$this->assertNull($this->deletedIds);
		$this->assertSame([], $this->recorded);
	}
}
