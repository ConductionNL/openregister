<?php

/**
 * Tests for Version1Date20261124193002: the audit seal cutover marker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-verification-shall-distinguish-legacy-unsealed-rows-from-unsealed-rows-written-after-the-cutover
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261124193002;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The marker is the first id after the existing rows, written once.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261124193002
 */
class Version1Date20261124193002Test extends TestCase {

	/**
	 * The marker is one past the highest existing id.
	 *
	 * @return void
	 */
	public function testTheMarkerIsTheFirstIdAfterTheExistingRows(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->with('openregister', 'audit_seal_cutover_id', 0)->willReturn(0);
		$appConfig->expects($this->once())->method('setValueInt')->with('openregister', 'audit_seal_cutover_id', 4712)->willReturn(true);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Recorded the audit seal cutover: rows from id 4712 on must be sealed.');

		(new Version1Date20261124193002($this->connection(maxId: '4711'), $appConfig))->postSchemaChange($output, static fn () => null, []);
	}

	/**
	 * On an empty trail every row to come is post-cutover.
	 *
	 * @return void
	 */
	public function testAnEmptyTrailMakesEveryRowPostCutover(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(0);
		$appConfig->expects($this->once())->method('setValueInt')->with('openregister', 'audit_seal_cutover_id', 1)->willReturn(true);

		$step = new Version1Date20261124193002($this->connection(maxId: null), $appConfig);
		$step->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	/**
	 * A marker already recorded stays, and the trail is not even read.
	 *
	 * @return void
	 */
	public function testARecordedMarkerIsNeverMoved(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(300);
		$appConfig->expects($this->never())->method('setValueInt');
		$connection = $this->createMock(IDBConnection::class);
		$connection->expects($this->never())->method('getQueryBuilder');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Audit seal cutover already recorded at id 300.');

		(new Version1Date20261124193002($connection, $appConfig))->postSchemaChange($output, static fn () => null, []);
	}

	/**
	 * A connection whose MAX(id) query answers the given value.
	 *
	 * @param string|null $maxId What fetchOne() returns (null: an empty table).
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function connection(?string $maxId): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($maxId);
		$func = $this->createMock(IFunctionBuilder::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('func')->willReturn($func);
		$qb->method('select')->willReturnSelf();
		$qb->expects($this->once())->method('from')->with('openregister_audit_trails')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($qb);

		return $connection;
	}
}
