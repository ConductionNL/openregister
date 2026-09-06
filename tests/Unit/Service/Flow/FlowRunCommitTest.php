<?php

/**
 * FlowRunCommit: the locked delta commit and the status projection.
 *
 * The database is replaced by an in-memory "row" behind mocked mappers, so the
 * property under test is the ARGUMENT — every value written is computed from
 * the row read inside the lock, and the delta mentions no other place — not
 * the database's locking. A green run here is not evidence that `FOR UPDATE`
 * works on any engine (SQLite has no row locks at all); it is evidence that
 * the commit computes the right thing from the locked read it is given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Service\Flow\FlowFiring;
use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The commit path over an in-memory row.
 */
class FlowRunCommitTest extends TestCase {

	/**
	 * The "database": one run row, its streams, its claims, its steps.
	 */
	private FlowRun $row;

	/** @var array<string, FlowStream> */
	private array $streams = [];

	/** @var array<int, FlowClaim> */
	private array $claims = [];

	/** @var array<int, FlowRunStep> */
	private array $steps = [];

	private int $transactions = 0;

	private FlowRunCommit $commit;

	protected function setUp(): void {
		parent::setUp();
		$this->row = new FlowRun();
		$this->row->setUuid('run-1');
		$this->row->setFlowId('flow-1');
		$this->row->setStatus(FlowRun::STATUS_RUNNING);
		$this->row->setMarking(['a' => 1, 'b' => 1]);
		$this->row->setFirings(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->transactions++;
		});

		$runs = $this->createMock(FlowRunMapper::class);
		// The LOCKED read: always the row as it stands NOW, never a copy taken earlier.
		$runs->method('lockByUuid')->willReturnCallback(fn (): FlowRun => $this->row);
		$runs->method('update')->willReturnCallback(fn (FlowRun $run): FlowRun => $run);

		$streams = $this->createMock(FlowStreamMapper::class);
		$streams->method('findByRun')->willReturnCallback(function (): array {
			$list = array_values($this->streams);
			usort($list, static fn (FlowStream $a, FlowStream $b): int => strcmp((string)$a->getOrdinalPath(), (string)$b->getOrdinalPath()));
			return $list;
		});
		$streams->method('findByRunAndStream')->willReturnCallback(fn (string $runUuid, string $streamId): ?FlowStream => ($this->streams[$streamId] ?? null));
		$streams->method('insert')->willReturnCallback(function (FlowStream $stream): FlowStream {
			$this->streams[(string)$stream->getStreamId()] = $stream;
			return $stream;
		});
		$streams->method('update')->willReturnCallback(function (FlowStream $stream): FlowStream {
			$this->streams[(string)$stream->getStreamId()] = $stream;
			return $stream;
		});
		$streams->method('allocateNextSequence')->willReturnCallback(function (string $runUuid, string $streamId): int {
			$stream = ($this->streams[$streamId] ?? null);
			if ($stream === null) {
				return 0;
			}

			$next = (int)$stream->getNextSequence();
			$stream->setNextSequence($next + 1);
			return $next;
		});

		$claims = $this->createMock(FlowClaimMapper::class);
		$claims->method('findByRun')->willReturnCallback(fn (): array => array_values($this->claims));
		$claims->method('release')->willReturnCallback(function (string $runUuid, array $places): int {
			$before = count($this->claims);
			$this->claims = array_values(array_filter($this->claims, static fn (FlowClaim $c): bool => in_array($c->getPlace(), $places, true) === false));
			return ($before - count($this->claims));
		});
		$claims->method('releaseByOwner')->willReturnCallback(function (string $runUuid, string $owner): int {
			$before = count($this->claims);
			$this->claims = array_values(array_filter($this->claims, static fn (FlowClaim $c): bool => $c->getOwner() !== $owner));
			return ($before - count($this->claims));
		});

		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(0);
		$steps->method('insert')->willReturnCallback(function (FlowRunStep $step): FlowRunStep {
			$this->steps[] = $step;
			return $step;
		});

		$this->commit = new FlowRunCommit(
			db: $db,
			runs: $runs,
			streams: $streams,
			claims: $claims,
			steps: $steps,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * Seed a stream row.
	 *
	 * @param string $id The stream id.
	 * @param string $path Its ordinal path.
	 * @param string $place Its place.
	 * @param string $status Its status.
	 * @param DateTime|null $resumeAt Its wake time.
	 *
	 * @return void
	 */
	private function stream(string $id, string $path, string $place, string $status = FlowRun::STATUS_RUNNING, ?DateTime $resumeAt = null): void {
		$stream = new FlowStream();
		$stream->setRunUuid('run-1');
		$stream->setStreamId($id);
		$stream->setOrdinalPath($path);
		$stream->setPlace($place);
		$stream->setStatus($status);
		$stream->setResumeAt($resumeAt);
		$stream->setNextSequence(1);
		$this->streams[$id] = $stream;
	}//end stream()

	/**
	 * Hold a claim.
	 *
	 * @param string $place The place.
	 * @param string $owner The holder.
	 *
	 * @return void
	 */
	private function claim(string $place, string $owner): void {
		$claim = new FlowClaim();
		$claim->setRunUuid('run-1');
		$claim->setPlace($place);
		$claim->setOwner($owner);
		$this->claims[] = $claim;
	}//end claim()

	/**
	 * A firing on a stream.
	 *
	 * @param string $stream The stream id.
	 * @param string $transition The transition.
	 * @param array<int, string> $froms Consumed places.
	 * @param array<int, string> $taken Produced places.
	 * @param bool $enabledAfter Whether anything is enabled afterwards.
	 *
	 * @return FlowFiring The firing.
	 */
	private function firing(string $stream, string $transition, array $froms, array $taken, bool $enabledAfter = false): FlowFiring {
		$items = [];
		foreach ($taken as $to) {
			$items[$to] = [['json' => ['via' => $transition]]];
		}

		return new FlowFiring(
			streamId: $stream,
			transition: $transition,
			froms: $froms,
			taken: $taken,
			itemsByPlace: $items,
			claimedPlaces: array_merge($froms, $taken),
			consumedStreamIds: [],
			logEntry: ['transition' => $transition, 'type' => 'x', 'status' => 'completed', 'itemsIn' => 1, 'itemsOut' => 1],
			enabledAfter: $enabledAfter
		);
	}//end firing()

	public function testTwoInterleavedCommitsKeepBothEffects(): void {
		// design.md Decision 3, t=1..24: A and B both read {a:1, b:1} before
		// either writes. B commits T2 (-b +d) first; A commits T1 (-a +c) from
		// a locked RE-READ that already contains d.
		$this->stream('sA', '0001.0001', 'a');
		$this->stream('sB', '0001.0002', 'b');
		$this->claim('a', 'A');
		$this->claim('c', 'A');
		$this->claim('b', 'B');
		$this->claim('d', 'B');

		$runA = clone $this->row;
		$runB = clone $this->row;

		$resultB = $this->commit->commitFiring(run: $runB, firing: $this->firing('sB', 'T2', ['b'], ['d']), owner: 'B');
		$this->assertEqualsCanonicalizing(['a' => 1, 'd' => 1], $resultB->marking);

		$resultA = $this->commit->commitFiring(run: $runA, firing: $this->firing('sA', 'T1', ['a'], ['c']), owner: 'A');

		// Both consumed, both successors marked, nothing neither produced.
		$this->assertEqualsCanonicalizing(['c' => 1, 'd' => 1], $resultA->marking);
		$this->assertEqualsCanonicalizing(['c' => 1, 'd' => 1], $this->row->getMarking());
		// Marking and items committed together: c's items are readable in the same state.
		$this->assertSame([['json' => ['via' => 'T1']]], $this->row->getPlaceItems()['c']);
		$this->assertSame([['json' => ['via' => 'T2']]], $this->row->getPlaceItems()['d']);
		// One firing per commit, counted on the run.
		$this->assertSame(2, (int)$this->row->getFirings());
		// The caller's entity was refreshed and its change tracking reset, so a
		// later whole-entity update cannot carry a stale marking.
		$this->assertEqualsCanonicalizing(['c' => 1, 'd' => 1], $runA->getMarking());
		$this->assertSame([], $runA->getUpdatedFields());
		// Each stream got exactly one step row at its own position 1.
		$this->assertCount(2, $this->steps);
		$this->assertSame([1, 1], array_map(static fn (FlowRunStep $s): int => (int)$s->getSequence(), $this->steps));
		$this->assertSame(['0001.0002', '0001.0001'], array_map(static fn (FlowRunStep $s): string => (string)$s->getOrdinalPath(), $this->steps));
		// Claims released inside the commits; two transactions, one per firing.
		$this->assertSame([], $this->claims);
		$this->assertSame(2, $this->transactions);
	}//end testTwoInterleavedCommitsKeepBothEffects()

	public function testASplitMintsChildrenInDeclarationOrderAndAJoinFoldsBack(): void {
		$this->row->setMarking(['s' => 1]);
		$this->stream('root', '0001', 's');

		$this->commit->commitFiring(run: $this->row, firing: $this->firing('root', 'split', ['s'], ['x', 'y'], true), owner: 'W');

		$paths = array_map(static fn (FlowStream $s): string => (string)$s->getOrdinalPath(), array_values($this->streams));
		sort($paths);
		$this->assertSame(['0001', '0001.0001', '0001.0002'], $paths);
		$this->assertSame(FlowRun::STATUS_COMPLETED, $this->streams['root']->getStatus());

		$childX = FlowRunCommit::streamIdFor(runUuid: 'run-1', path: '0001.0001');
		$childY = FlowRunCommit::streamIdFor(runUuid: 'run-1', path: '0001.0002');
		$this->assertSame('x', $this->streams[$childX]->getPlace());
		$this->assertSame('y', $this->streams[$childY]->getPlace());

		// Both branches arrive at the join's two input places, then the join
		// fires ONCE consuming both, and folds onto the common prefix — the
		// root — resuming ITS sequence.
		$this->commit->commitFiring(run: $this->row, firing: $this->firing($childX, 'ex', ['x'], ['j#e1'], false), owner: 'W');
		$this->commit->commitFiring(run: $this->row, firing: $this->firing($childY, 'ey', ['y'], ['j#e2'], true), owner: 'W');
		$this->assertEqualsCanonicalizing(['j#e1' => 1, 'j#e2' => 1], $this->row->getMarking());

		$join = new FlowFiring(
			streamId: $childX,
			transition: 'j',
			froms: ['j#e1', 'j#e2'],
			taken: ['after'],
			itemsByPlace: ['after' => []],
			claimedPlaces: ['j#e1', 'j#e2', 'after'],
			consumedStreamIds: [$childY],
			logEntry: ['transition' => 'j', 'status' => 'completed'],
			enabledAfter: false
		);
		$this->commit->commitFiring(run: $this->row, firing: $join, owner: 'W');

		$this->assertSame(['after' => 1], $this->row->getMarking());
		$this->assertSame(FlowRun::STATUS_RUNNING, $this->streams['root']->getStatus());
		$this->assertSame('after', $this->streams['root']->getPlace());
		$this->assertSame(FlowRun::STATUS_COMPLETED, $this->streams[$childX]->getStatus());
		$this->assertSame(FlowRun::STATUS_COMPLETED, $this->streams[$childY]->getStatus());
		// The join's step row sits on the root's path at the root's next position (2, after the split).
		$last = $this->steps[count($this->steps) - 1];
		$this->assertSame('0001', $last->getOrdinalPath());
		$this->assertSame(2, (int)$last->getSequence());
		$this->assertSame(4, (int)$this->row->getFirings());
	}//end testASplitMintsChildrenInDeclarationOrderAndAJoinFoldsBack()

	public function testStatusIsRunningWhileAnotherPassHoldsAClaim(): void {
		$this->stream('s1', '0001', 'a');
		$this->claim('a', 'other-pass');

		$status = $this->commit->finalize(run: $this->row, owner: 'me', enabled: false);

		$this->assertSame(FlowRun::STATUS_RUNNING, $status);
	}//end testStatusIsRunningWhileAnotherPassHoldsAClaim()

	public function testAnEnabledUnclaimedFiringLeavesTheRunQueuedNeverCompleted(): void {
		// The lost-wake-up guard: a join enabled by the last commit of a pass
		// is fired by the next pass, and the run is never reported completed
		// in between.
		$this->stream('s1', '0001', 'j#e1');
		$status = $this->commit->finalize(run: $this->row, owner: 'me', enabled: true);
		$this->assertSame(FlowRun::STATUS_QUEUED, $status);
	}//end testAnEnabledUnclaimedFiringLeavesTheRunQueuedNeverCompleted()

	public function testAllParkedReadsSuspendedWithTheEarliestNonNullWake(): void {
		// One stream waits on a signal (null), one on a timer: a plain MIN
		// would hide the due timer behind the null.
		$timer = new DateTime('+10 minutes');
		$this->stream('s1', '0001.0001', 'w', FlowRun::STATUS_SUSPENDED, null);
		$this->stream('s2', '0001.0002', 't', FlowRun::STATUS_SUSPENDED, $timer);

		$status = $this->commit->finalize(run: $this->row, owner: 'me', enabled: false);

		$this->assertSame(FlowRun::STATUS_SUSPENDED, $status);
		$this->assertEquals($timer, $this->row->getResumeAt());
	}//end testAllParkedReadsSuspendedWithTheEarliestNonNullWake()

	public function testOnlySignalWaitersLeavesResumeAtNull(): void {
		$this->stream('s1', '0001', 'w', FlowRun::STATUS_SUSPENDED, null);
		$this->commit->finalize(run: $this->row, owner: 'me', enabled: false);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $this->row->getStatus());
		$this->assertNull($this->row->getResumeAt());
	}//end testOnlySignalWaitersLeavesResumeAtNull()

	public function testOneWaitingAndOneWorkingReadsAsRunningViaItsClaim(): void {
		$this->stream('s1', '0001.0001', 'w', FlowRun::STATUS_SUSPENDED, null);
		$this->stream('s2', '0001.0002', 'x', FlowRun::STATUS_RUNNING);
		$this->claim('x', 'sibling-pass');

		$status = $this->commit->finalize(run: $this->row, owner: 'me', enabled: false);

		$this->assertSame(FlowRun::STATUS_RUNNING, $status);
		// The per-branch detail still says which branch waits.
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $this->streams['s1']->getStatus());
	}//end testOneWaitingAndOneWorkingReadsAsRunningViaItsClaim()

	public function testTerminalProjectionTakesTheMostSevereStream(): void {
		$this->stream('s1', '0001.0001', 'e', FlowRun::STATUS_COMPLETED);
		$this->stream('s2', '0001.0002', 'f', FlowRun::STATUS_STOPPED);
		$this->stream('s3', '0001.0003', 'g', FlowRun::STATUS_FAILED);

		$this->assertSame(FlowRun::STATUS_FAILED, $this->commit->finalize(run: $this->row, owner: 'me', enabled: false));

		$this->streams['s3']->setStatus(FlowRun::STATUS_COMPLETED);
		$this->assertSame(FlowRun::STATUS_STOPPED, $this->commit->finalize(run: $this->row, owner: 'me', enabled: false));

		$this->streams['s2']->setStatus(FlowRun::STATUS_COMPLETED);
		$this->assertSame(FlowRun::STATUS_COMPLETED, $this->commit->finalize(run: $this->row, owner: 'me', enabled: false));
	}//end testTerminalProjectionTakesTheMostSevereStream()

	public function testARunLevelStopEndsEveryStream(): void {
		$this->stream('s1', '0001.0001', 'a');
		$this->stream('s2', '0001.0002', 'b', FlowRun::STATUS_SUSPENDED);
		$this->claim('a', 'me');

		$status = $this->commit->finalize(run: $this->row, owner: 'me', enabled: true, forcedTerminal: FlowRun::STATUS_STOPPED);

		$this->assertSame(FlowRun::STATUS_STOPPED, $status);
		$this->assertSame(FlowRun::STATUS_STOPPED, $this->streams['s1']->getStatus());
		$this->assertSame(FlowRun::STATUS_STOPPED, $this->streams['s2']->getStatus());
		// This pass's own claims were released inside the same lock.
		$this->assertSame([], $this->claims);
	}//end testARunLevelStopEndsEveryStream()

	public function testFinalizeReleasesOwnClaimsBeforeDerivingSoNoRunIsLeftRunningWithNone(): void {
		$this->stream('s1', '0001', 'a', FlowRun::STATUS_COMPLETED);
		$this->claim('a', 'me');

		$status = $this->commit->finalize(run: $this->row, owner: 'me', enabled: false);

		$this->assertSame(FlowRun::STATUS_COMPLETED, $status);
		$this->assertSame([], $this->claims);
	}//end testFinalizeReleasesOwnClaimsBeforeDerivingSoNoRunIsLeftRunningWithNone()

	public function testAStatusValueOutsideTheSevenIsNeverProduced(): void {
		$allowed = [FlowRun::STATUS_QUEUED, FlowRun::STATUS_RUNNING, FlowRun::STATUS_SUSPENDED, FlowRun::STATUS_COMPLETED, FlowRun::STATUS_STOPPED, FlowRun::STATUS_DEAD_LETTER, FlowRun::STATUS_FAILED];
		$this->stream('s1', '0001.0001', 'a', FlowRun::STATUS_DEAD_LETTER);
		$this->stream('s2', '0001.0002', 'b', FlowRun::STATUS_SUSPENDED, new DateTime('+1 hour'));
		$this->assertContains($this->commit->finalize(run: $this->row, owner: 'me', enabled: false), $allowed);
		$this->streams['s2']->setStatus(FlowRun::STATUS_COMPLETED);
		$this->assertContains($this->commit->finalize(run: $this->row, owner: 'me', enabled: false), $allowed);
	}//end testAStatusValueOutsideTheSevenIsNeverProduced()
}//end class
