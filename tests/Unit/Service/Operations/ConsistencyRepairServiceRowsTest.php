<?php

/**
 * Which rows a consistency repair deletes when the check's findings carry
 * no id, or only some of them do.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Operations;

use OCA\OpenRegister\Service\Operations\OperatorAct;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Row-selection tests for {@see ConsistencyRepairService}.
 */
class ConsistencyRepairServiceRowsTest extends TestCase {

	/**
	 * The ids the delete was bound to, when one ran.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $boundIds = null;

	/**
	 * A repair service whose check finds the given rows.
	 *
	 * @param array<int, array<string, mixed>> $objects The rows found.
	 * @param IDBConnection $db The connection.
	 *
	 * @return ConsistencyRepairService The service.
	 */
	private function service(array $objects, IDBConnection $db): ConsistencyRepairService {
		$check = $this->createMock(ConsistencyCheckService::class);
		$check->method('repairPlan')->willReturn(['slug' => 'orphans', 'table' => 'openregister_things', 'action' => 'Delete.']);
		$check->method('checkOne')->willReturn(['slug' => 'orphans', 'count' => count($objects), 'objects' => $objects]);
		$recorder = $this->createMock(JobRunRecorder::class);
		$recorder->expects($this->once())
			->method('recordAct')
			->with(
				$this->callback(static fn (OperatorAct $act): bool => $act->actor === 'noor' && $act->details['objects'] === $objects)
			);

		return new ConsistencyRepairService($db, $check, $recorder);
	}//end service()

	/**
	 * Findings without an id delete nothing, and no statement is built; the
	 * act is still recorded with what was shown.
	 *
	 * @return void
	 */
	public function testFindingsWithoutAnIdDeleteNothing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$applied = $this->service(objects: [['name' => 'ghost'], ['uuid' => 'u-1']], db: $db)->apply(slug: 'orphans', actor: 'noor');

		$this->assertSame(0, $applied['changed']);
		$this->assertTrue($applied['recorded']);
	}//end testFindingsWithoutAnIdDeleteNothing()

	/**
	 * Only the findings that carry an id are deleted, cast to integers.
	 *
	 * @return void
	 */
	public function testOnlyFindingsWithAnIdAreDeleted(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('id IN (:ids)');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('delete')->with('openregister_things')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->boundIds = $value;

				return ':ids';
			}
		);
		$qb->method('executeStatement')->willReturn(1);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$applied = $this->service(objects: [['id' => '12'], ['name' => 'ghost']], db: $db)->apply(slug: 'orphans', actor: 'noor');

		$this->assertSame([12], $this->boundIds);
		$this->assertSame(1, $applied['changed']);
	}//end testOnlyFindingsWithAnIdAreDeleted()
}//end class
