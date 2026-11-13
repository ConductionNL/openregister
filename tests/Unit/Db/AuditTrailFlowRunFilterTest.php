<?php

/**
 * The audit-trail query filters on a row's flow attribution.
 *
 * Runs AuditTrailMapper::findTrail() over a migrated in-memory SQLite table,
 * so the assertion is on the rows a run filter selects and on the attribution
 * each returned row exposes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-object-attribution
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Filtering the audit trail by run, and reading the attribution back.
 */
class AuditTrailFlowRunFilterTest extends TestCase {

	private const RUN_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

	/**
	 * A run uuid with no flow_runs row: the run was pruned, its stamps remain.
	 */
	private const RUN_PRUNED = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

	private MigratedSqliteDatabase $database;

	private AuditTrailMapper $mapper;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase(test: $this, tables: ['openregister_audit_trails']);
		$this->mapper   = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			services: new AuditTrailMapperServices(container: $this->createMock(ContainerInterface::class), logger: new NullLogger(), userSession: $this->createMock(IUserSession::class), request: $this->createMock(IRequest::class))
		);

		$rows = [
			['object' => 'obj-1', 'run' => self::RUN_A, 'node' => 'enrich', 'step' => 1],
			['object' => 'obj-2', 'run' => null, 'node' => null, 'step' => null],
			['object' => 'obj-1', 'run' => self::RUN_PRUNED, 'node' => 'notify', 'step' => 3],
			['object' => 'obj-2', 'run' => self::RUN_A, 'node' => 'publish', 'step' => 2],
			['object' => 'obj-1', 'run' => null, 'node' => null, 'step' => null],
		];
		foreach ($rows as $index => $row) {
			$id = $index + 1;
			$this->database->insert(
				'openregister_audit_trails',
				[
					'id'          => $id,
					'uuid'        => sprintf('cccccccc-cccc-4ccc-8ccc-%012d', $id),
					'object_uuid' => $row['object'],
					'action'      => 'update',
					'created'     => sprintf('2026-10-14 11:%02d:00', $id),
					'changed'     => '{}',
					'flow_run'    => $row['run'],
					'flow_node'   => $row['node'],
					'flow_step'   => $row['step'],
				]
			);
		}
	}

	/**
	 * The rows a filter selects, in id order.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, AuditTrail>
	 */
	private function rows(array $filters): array {
		return $this->mapper->findTrail(ListQuery::unbounded($filters, ['id' => 'ASC']));
	}

	/**
	 * The ids of the rows a filter selects, in id order.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, int>
	 */
	private function ids(array $filters): array {
		return array_map(static fn (AuditTrail $row): int => (int)$row->getId(), $this->rows($filters));
	}

	public function testFilteringByRunReturnsOnlyThatRunsRows(): void {
		$this->assertSame([1, 4], $this->ids(['flowRun' => self::RUN_A]));
	}

	public function testTheColumnNameFiltersTheSameRows(): void {
		$this->assertSame([1, 4], $this->ids(['flow_run' => self::RUN_A]));
	}

	public function testARunThatTouchedNothingSelectsNoRows(): void {
		$this->assertSame([], $this->ids(['flowRun' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee']));
	}

	public function testTheRunFilterNarrowsAnObjectsHistory(): void {
		$this->assertSame([4], $this->ids(['object_uuid' => 'obj-2', 'flowRun' => self::RUN_A]));
	}

	public function testNodeAndStepFilterByTheirOwnColumns(): void {
		$this->assertSame([4], $this->ids(['flowNode' => 'publish']));
		$this->assertSame([3], $this->ids(['flowStep' => 3]));
	}

	public function testUnattributedRowsAreSelectableAsNull(): void {
		$this->assertSame([2, 5], $this->ids(['flowRun' => 'IS NULL']));
	}

	public function testAPrunedRunsRowsStillReadWithTheirStamp(): void {
		$rows = $this->rows(['flowRun' => self::RUN_PRUNED]);

		$this->assertCount(1, $rows);
		$serialised = $rows[0]->jsonSerialize();
		$this->assertSame(self::RUN_PRUNED, $serialised['flowRun']);
		$this->assertSame('notify', $serialised['flowNode']);
		$this->assertSame(3, $serialised['flowStep']);
	}

	public function testAnUnattributedRowSerialisesTheKeysAsNull(): void {
		$serialised = $this->rows(['id' => 2])[0]->jsonSerialize();

		$this->assertArrayHasKey('flowRun', $serialised);
		$this->assertNull($serialised['flowRun']);
		$this->assertNull($serialised['flowNode']);
		$this->assertNull($serialised['flowStep']);
	}
}
