<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

use OCA\OpenRegister\Notifications\Db\EntryClosure;
use OCA\OpenRegister\Notifications\Db\EntryClosureMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The closures table is read and cleared by object uuid only (object-archive-state C29.4).
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
#[CoversClass(EntryClosureMapper::class)]
#[UsesClass(EntryClosure::class)]
final class EntryClosureMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(): EntryClosureMapper {
		$this->queryLog = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		return new EntryClosureMapper($db);
	}

	public function testAClosureIsFoundByItsObject(): void {
		$mapper = $this->mapper();
		$this->queuedRows = [[['id' => 4, 'object_uuid' => 'case-1', 'closed_by' => 'behandelaar', 'closed_at' => '2026-11-25 10:00:00', 'reason' => 'beroep']]];

		$closure = $mapper->findForObject(objectUuid: 'case-1');

		$this->assertInstanceOf(EntryClosure::class, $closure);
		$this->assertSame(['behandelaar', 'beroep'], [$closure->getClosedBy(), $closure->getReason()]);
		$this->assertSame('2026-11-25', $closure->getClosedAt()->format('Y-m-d'));
		$this->assertContains('#1 from(openregister_entry_closures, NULL)', $this->queryLog);
		$this->assertContains('#1 param :p1 = case-1, 2', $this->queryLog);
		$this->assertContains('#1 expr.eq(object_uuid, :p1, NULL)', $this->queryLog);
	}

	public function testAnOpenObjectHasNoClosure(): void {
		$mapper = $this->mapper();
		$this->queuedRows = [[]];

		$this->assertNull($mapper->findForObject(objectUuid: 'case-1'));
	}

	public function testReopeningDeletesOnlyThatObjectsRow(): void {
		$mapper = $this->mapper();

		$mapper->deleteForObject(objectUuid: 'case-1');

		$this->assertContains('#1 delete(openregister_entry_closures, NULL)', $this->queryLog);
		$this->assertContains('#1 expr.eq(object_uuid, :p1, NULL)', $this->queryLog);
		$this->assertContains('#1 executeStatement()', $this->queryLog);
	}
}
