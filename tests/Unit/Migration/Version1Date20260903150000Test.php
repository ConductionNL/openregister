<?php

/**
 * Unit tests for dropping the external workflow-engine tables.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20260903150000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Locks the drop, its idempotency, and the report that runs before it.
 */
class Version1Date20260903150000Test extends TestCase {

	/**
	 * The tables the step owns.
	 *
	 * @var array<int, string>
	 */
	private const TABLES = [
		'openregister_workflow_engines',
		'openregister_deployed_workflows',
		'openregister_scheduled_workflows',
		'openregister_workflow_executions',
		'openregister_actions',
		'openregister_action_logs',
	];

	/**
	 * Every table is dropped when present.
	 *
	 * @return void
	 */
	public function testEveryTableIsDropped(): void {
		$dropped = [];
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('dropTable')->willReturnCallback(
			function (string $table) use (&$dropped) {
				$dropped[] = $table;
			}
		);

		$step = new Version1Date20260903150000($this->createMock(IDBConnection::class));
		$result = $step->changeSchema($this->createMock(IOutput::class), fn() => $schema, []);

		$this->assertSame(self::TABLES, $dropped);
		$this->assertSame($schema, $result);

	}//end testEveryTableIsDropped()

	/**
	 * A table that is already gone is not dropped again, and a run where none
	 * is present returns null so Nextcloud records no schema change.
	 *
	 * @return void
	 */
	public function testAnAlreadyDroppedTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('dropTable');

		$step = new Version1Date20260903150000($this->createMock(IDBConnection::class));

		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), fn() => $schema, []));

	}//end testAnAlreadyDroppedTableIsLeftAlone()

	/**
	 * Only the tables that are present get dropped, so a partially-migrated
	 * instance does not fail on the ones it has already lost.
	 *
	 * @return void
	 */
	public function testOnlyThePresentTablesAreDropped(): void {
		$dropped = [];
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $table) => ($table === 'openregister_actions')
		);
		$schema->method('dropTable')->willReturnCallback(
			function (string $table) use (&$dropped) {
				$dropped[] = $table;
			}
		);

		$step = new Version1Date20260903150000($this->createMock(IDBConnection::class));
		$step->changeSchema($this->createMock(IOutput::class), fn() => $schema, []);

		$this->assertSame(['openregister_actions'], $dropped);

	}//end testOnlyThePresentTablesAreDropped()

	/**
	 * Every scheduled workflow is NAMED before anything is destroyed.
	 *
	 * This is the point of the whole step: a schedule records intent somebody
	 * wrote down, and dropping the row without printing it leaves nothing to
	 * re-create it from.
	 *
	 * @return void
	 */
	public function testEveryScheduledWorkflowIsReportedBeforeTheDrop(): void {
		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings) {
				$warnings[] = $message;
			}
		);

		$step = new Version1Date20260903150000(
			$this->connectionReturning(
				[
					['name' => 'quarterly-cbs-submission', 'engine' => 'openconnector', 'interval_sec' => 7776000],
					['name' => 'monthly-depreciation', 'engine' => 'openconnector', 'interval_sec' => 2592000],
				]
			)
		);

		$step->preSchemaChange($output, fn() => $this->createMock(ISchemaWrapper::class), []);

		$joined = implode("\n", $warnings);
		$this->assertStringContainsString('2 scheduled workflow(s)', $joined);
		$this->assertStringContainsString('quarterly-cbs-submission', $joined);
		$this->assertStringContainsString('monthly-depreciation', $joined);
		$this->assertStringContainsString('7776000s', $joined);

	}//end testEveryScheduledWorkflowIsReportedBeforeTheDrop()

	/**
	 * Nothing to report is silent, rather than announcing an empty list.
	 *
	 * @return void
	 */
	public function testNoScheduledWorkflowsReportsNothing(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		$step = new Version1Date20260903150000($this->connectionReturning([]));
		$step->preSchemaChange($output, fn() => $this->createMock(ISchemaWrapper::class), []);

	}//end testNoScheduledWorkflowsReportsNothing()

	/**
	 * An unreadable table does not abort the upgrade. The table may already be
	 * gone, and an upgrade that dies because it could not print a warning would
	 * be a worse outcome than the missing warning.
	 *
	 * @return void
	 */
	public function testAnUnreadableTableDoesNotAbortTheUpgrade(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('gone'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		$step = new Version1Date20260903150000($db);
		$step->preSchemaChange($output, fn() => $this->createMock(ISchemaWrapper::class), []);

		$this->addToAssertionCount(1);

	}//end testAnUnreadableTableDoesNotAbortTheUpgrade()

	/**
	 * A connection whose query builder yields the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to return.
	 *
	 * @return IDBConnection The connection.
	 */
	private function connectionReturning(array $rows): IDBConnection {
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;

	}//end connectionReturning()
}//end class
