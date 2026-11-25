<?php

/**
 * The document store move mapping: the row, and the queries a move relies on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\DocumentMove;
use OCA\OpenRegister\Db\DocumentMoveMapper;
use OCA\OpenRegister\Db\TypedFieldsJson;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Builds the mapper over a recording query builder.
 */
#[CoversClass(DocumentMoveMapper::class)]
#[CoversClass(DocumentMove::class)]
#[UsesClass(TypedFieldsJson::class)]
class DocumentMoveMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(): DocumentMoveMapper {
		$this->queryLog = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return new DocumentMoveMapper($db);
	}//end mapper()

	public function testAMappingRoundTrips(): void {
		$row = [
			'id' => 7,
			'register_id' => 3,
			'old_id' => 101,
			'new_id' => 901,
			'kind' => 'version',
			'version_time' => 1731500000,
			'sha256' => hash('sha256', 'v1'),
			'state' => 'verified',
			'reason' => null,
			'moved_at' => '2026-11-14 10:00:00',
		];

		$move = DocumentMove::fromRow($row);
		$json = $move->jsonSerialize();

		$this->assertSame(3, $move->getRegisterId());
		$this->assertSame(101, $move->getOldId());
		$this->assertSame(901, $move->getNewId());
		$this->assertSame(DocumentMove::KIND_VERSION, $move->getKind());
		$this->assertSame(1731500000, $move->getVersionTime());
		$this->assertSame(DocumentMove::STATE_VERIFIED, $move->getState());
		$this->assertSame(hash('sha256', 'v1'), $json['sha256']);
		$this->assertInstanceOf(DateTime::class, $move->getMovedAt());
		$this->assertSame('2026-11-14', $move->getMovedAt()->format('Y-m-d'));
		$this->assertNull($json['reason']);
	}//end testAMappingRoundTrips()

	public function testARegistersRowsAreReadOldestFirst(): void {
		$this->mapper()->forRegister(registerId: 3);

		$this->assertContains('#1 expr.eq(register_id, :p1, NULL)', $this->queryLog);
		$this->assertContains('#1 orderBy(id, ASC)', $this->queryLog);
	}//end testARegistersRowsAreReadOldestFirst()

	public function testSwitchingTouchesOnlyVerifiedRowsOfThatRegister(): void {
		$this->mapper()->switchRegister(registerId: 3, at: new DateTime('2026-11-14 10:00:00'));

		$this->assertSame(
			[
				'#1 update(openregister_document_moves, NULL)',
				'#1 param :p1 = switched, 2',
				'#1 set(state, :p1)',
				'#1 param :p2 = DateTime, datetime',
				'#1 set(moved_at, :p2)',
				'#1 param :p3 = 3, 1',
				'#1 expr.eq(register_id, :p3, NULL)',
				'#1 where(eq(register_id, :p3, NULL))',
				'#1 param :p4 = verified, 2',
				'#1 expr.eq(state, :p4, NULL)',
				'#1 andWhere(eq(state, :p4, NULL))',
				'#1 executeStatement()',
			],
			$this->queryLog
		);
	}//end testSwitchingTouchesOnlyVerifiedRowsOfThatRegister()

	public function testSwitchedOldIdsLeaveVersionsOut(): void {
		$this->queuedRows = [[['old_id' => '101'], ['old_id' => 102]]];

		$ids = $this->mapper()->switchedOldIds();

		$this->assertSame([101 => true, 102 => true], $ids);
		$this->assertContains('#1 param :p2 = version, 2', $this->queryLog);
		$this->assertContains('#1 expr.neq(kind, :p2, NULL)', $this->queryLog);
	}//end testSwitchedOldIdsLeaveVersionsOut()
}//end class
