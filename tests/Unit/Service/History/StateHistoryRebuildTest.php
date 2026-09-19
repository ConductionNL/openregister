<?php

/**
 * Unit tests for the state-history rebuild.
 *
 * The projection is written forward from the transition that produced it, so
 * every object that moved before it shipped has no line. The rebuild derives
 * one from the audit trail, and derivation is where the mistakes live: an
 * interval too many, an interval missing, or one filed under a key no schema
 * declares as a state.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\History;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\StateHistory;
use OCA\OpenRegister\Db\StateHistoryMapper;
use OCA\OpenRegister\Service\History\StateHistoryRebuild;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StateHistoryRebuildTest extends TestCase {

	private StateHistoryMapper&MockObject $intervals;

	private AuditTrailMapper&MockObject $audit;

	private StateHistoryRebuild $rebuild;

	protected function setUp(): void {
		parent::setUp();

		$this->intervals = $this->createMock(StateHistoryMapper::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->rebuild = new StateHistoryRebuild(
			$this->intervals,
			$this->audit,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A case that moved open → bezwaar → gesloten.
	 *
	 * @return array The change rows.
	 */
	private function changes(): array {
		return [
			[
				'created' => '2026-01-10 09:00:00',
				'changed' => ['status' => ['old' => 'open', 'new' => 'bezwaar'], 'title' => ['old' => 'a', 'new' => 'b']],
			],
			[
				'created' => '2026-03-01 11:00:00',
				'changed' => ['status' => ['old' => 'bezwaar', 'new' => 'gesloten']],
			],
		];
	}//end changes()

	/**
	 * The line is every state the object held, with the last one still open.
	 *
	 * @return void
	 */
	public function testTheLineCoversEveryStateTheObjectHeld(): void {
		$intervals = $this->rebuild->intervalsFor($this->changes(), 'status');

		$this->assertSame(
			['open', 'bezwaar', 'gesloten'],
			array_column($intervals, 'value')
		);
		$this->assertNull($intervals[2]['leftAt'], 'the state it is in now has no end');
		$this->assertSame('2026-03-01', $intervals[1]['leftAt']->format('Y-m-d'));
		$this->assertSame('2026-01-10', $intervals[1]['enteredAt']->format('Y-m-d'));
	}//end testTheLineCoversEveryStateTheObjectHeld()

	/**
	 * The state the object was in BEFORE its first recorded change is part of
	 * the line, with no start.
	 *
	 * Dropping it would lose every state held before the first transition,
	 * which is the exact set a rebuild exists to recover: `was ever in open`
	 * would answer no for a case that spent a year there.
	 *
	 * @return void
	 */
	public function testTheStateBeforeTheFirstChangeIsRecovered(): void {
		$intervals = $this->rebuild->intervalsFor($this->changes(), 'status');

		$this->assertSame('open', $intervals[0]['value']);
		$this->assertNull($intervals[0]['enteredAt'], 'its start is unknown, which is a fact, not a zero');
		$this->assertSame('2026-01-10', $intervals[0]['leftAt']->format('Y-m-d'));
	}//end testTheStateBeforeTheFirstChangeIsRecovered()

	/**
	 * Only the named property is projected.
	 *
	 * The trail records every changed field. A rebuild reading "whatever
	 * changed" would file intervals under keys no schema declares as states,
	 * and a filter could then reach them.
	 *
	 * @return void
	 */
	public function testOnlyTheDeclaredPropertyIsProjected(): void {
		$this->assertSame([], $this->rebuild->intervalsFor($this->changes(), 'behandelaar'));

		$titles = $this->rebuild->intervalsFor($this->changes(), 'title');
		$this->assertSame(['a', 'b'], array_column($titles, 'value'));
	}//end testOnlyTheDeclaredPropertyIsProjected()

	/**
	 * A row that does not touch the property contributes no boundary.
	 *
	 * @return void
	 */
	public function testAnUnrelatedChangeDoesNotSplitAnInterval(): void {
		$changes = [
			['created' => '2026-01-10 09:00:00', 'changed' => ['status' => ['old' => 'open', 'new' => 'bezwaar']]],
			['created' => '2026-02-01 09:00:00', 'changed' => ['title' => ['old' => 'a', 'new' => 'b']]],
		];

		$intervals = $this->rebuild->intervalsFor($changes, 'status');

		$this->assertCount(2, $intervals);
		$this->assertNull($intervals[1]['leftAt']);
	}//end testAnUnrelatedChangeDoesNotSplitAnInterval()

	/**
	 * An object with no recorded change of the property has no line, rather
	 * than an empty interval standing in for one.
	 *
	 * @return void
	 */
	public function testNoRecordedChangeMeansNoLine(): void {
		$this->assertSame([], $this->rebuild->intervalsFor([], 'status'));
	}//end testNoRecordedChangeMeansNoLine()

	/**
	 * A row with no readable moment is skipped rather than dated to now.
	 *
	 * @return void
	 */
	public function testARowWithNoReadableMomentIsSkipped(): void {
		$changes = [
			['created' => 'not a date', 'changed' => ['status' => ['old' => 'open', 'new' => 'bezwaar']]],
			['created' => '2026-03-01 11:00:00', 'changed' => ['status' => ['old' => 'bezwaar', 'new' => 'gesloten']]],
		];

		$intervals = $this->rebuild->intervalsFor($changes, 'status');

		$this->assertSame(['bezwaar', 'gesloten'], array_column($intervals, 'value'));
	}//end testARowWithNoReadableMomentIsSkipped()

	/**
	 * Rebuilding REPLACES the object's line.
	 *
	 * A second pass that appended would double every interval, and "was ever
	 * in bezwaar" would be true twice for a case that was there once.
	 *
	 * @return void
	 */
	public function testRebuildingReplacesTheLineRatherThanAddingToIt(): void {
		$this->audit->method('findChangesForObject')->willReturn($this->changes());

		$order = [];
		$this->intervals->method('deleteForObject')->willReturnCallback(
			static function () use (&$order): int {
				$order[] = 'delete';
				return 3;
			}
		);
		$this->intervals->method('insert')->willReturnCallback(
			static function (StateHistory $row) use (&$order): StateHistory {
				$order[] = 'insert:' . (string)$row->getValue();
				return $row;
			}
		);

		$written = $this->rebuild->rebuildObject('uuid-1', 'status', 'zaken', 'zaak');

		$this->assertSame(3, $written);
		$this->assertSame(['delete', 'insert:open', 'insert:bezwaar', 'insert:gesloten'], $order);
	}//end testRebuildingReplacesTheLineRatherThanAddingToIt()

	/**
	 * A rebuild that cannot read the trail writes nothing and does not throw.
	 *
	 * @return void
	 */
	public function testAFailingRebuildWritesNothingAndDoesNotThrow(): void {
		$this->audit->method('findChangesForObject')->willThrowException(new \RuntimeException('gone'));
		$this->intervals->expects($this->never())->method('insert');

		$this->assertSame(0, $this->rebuild->rebuildObject('uuid-1', 'status', 'zaken', 'zaak'));
	}//end testAFailingRebuildWritesNothingAndDoesNotThrow()
}//end class
