<?php

/**
 * Unit tests for PrepareAuditSealing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Migration\Version1Date20261113210000;
use OCA\OpenRegister\Migration\Version1Date20261124193002;
use OCA\OpenRegister\Repair\PrepareAuditSealing;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The step runs the post-schema halves a schema-only install skips.
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */
class PrepareAuditSealingTest extends TestCase {

	/**
	 * Statements the connection was asked to execute.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * On PostgreSQL with no marker, the partial index is created and the cutover is the first id after the trail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
	 */
	public function testAFreshInstanceGetsTheIndexAndTheCutover(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->with('openregister', 'audit_seal_cutover_id', 0)->willReturn(0);
		$appConfig->expects($this->once())->method('setValueInt')->with('openregister', 'audit_seal_cutover_id', 1)->willReturn(true);

		$this->step(provider: IDBConnection::PLATFORM_POSTGRES, maxId: null, appConfig: $appConfig)->run($this->createMock(IOutput::class));

		$this->assertSame(['CREATE INDEX IF NOT EXISTS idx_audit_unsealed ON oc_openregister_audit_trails (id) WHERE hash IS NULL'], $this->statements);
	}//end testAFreshInstanceGetsTheIndexAndTheCutover()

	/**
	 * A recorded marker stays where it is, and the index statement is still idempotent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
	 */
	public function testARecordedCutoverIsNeverMoved(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(300);
		$appConfig->expects($this->never())->method('setValueInt');

		$this->step(provider: IDBConnection::PLATFORM_POSTGRES, maxId: '4711', appConfig: $appConfig)->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->statements);
		$this->assertStringStartsWith('CREATE INDEX IF NOT EXISTS ', $this->statements[0]);
	}//end testARecordedCutoverIsNeverMoved()

	/**
	 * Without partial indexes (MySQL) only the cutover is recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
	 */
	public function testWithoutPartialIndexesOnlyTheCutoverIsRecorded(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(0);
		$appConfig->expects($this->once())->method('setValueInt')->with('openregister', 'audit_seal_cutover_id', 4712)->willReturn(true);

		$this->step(provider: IDBConnection::PLATFORM_MYSQL, maxId: '4711', appConfig: $appConfig)->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->statements);
	}//end testWithoutPartialIndexesOnlyTheCutoverIsRecorded()

	/**
	 * A failing step is reported and the other one still runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
	 */
	public function testAFailingStepIsReportedAndTheOtherStillRuns(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willThrowException(new RuntimeException('config unreadable'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Could not prepare the audit seal cutover: config unreadable');

		$this->step(provider: IDBConnection::PLATFORM_SQLITE, maxId: null, appConfig: $appConfig)->run($output);

		$this->assertCount(1, $this->statements, 'the index step ran before the cutover step failed');
	}//end testAFailingStepIsReportedAndTheOtherStillRuns()

	/**
	 * The step on a connection for one database provider.
	 *
	 * @param string      $provider  IDBConnection::PLATFORM_*.
	 * @param string|null $maxId     What MAX(id) answers (null: an empty trail).
	 * @param IAppConfig  $appConfig The app config.
	 *
	 * @return PrepareAuditSealing
	 */
	private function step(string $provider, ?string $maxId, IAppConfig $appConfig): PrepareAuditSealing {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($maxId);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('getTableName')->willReturnCallback(static fn (string $table): string => 'oc_' . $table);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($qb);
		$connection->method('getDatabaseProvider')->willReturn($provider);
		$connection->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				return 0;
			}
		);

		return new PrepareAuditSealing(
			unsealedIndex: new Version1Date20261113210000($connection),
			cutover: new Version1Date20261124193002($connection, $appConfig)
		);
	}//end step()
}//end class
