<?php

/**
 * FlowStreamWalk: token-to-stream assignment, round-robin scheduling, claim
 * bookkeeping and the in-memory picture after commits, over mocked claims,
 * commit path and stream rows.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Service\Flow\FlowFiring;
use OCA\OpenRegister\Service\Flow\FlowFiringResult;
use OCA\OpenRegister\Service\Flow\FlowPlaceClaims;
use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\Flow\FlowStreamWalk;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Transition;

/**
 * The walk's bookkeeping.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowStreamWalk
 * @covers \OCA\OpenRegister\Service\Flow\FlowFiring
 * @covers \OCA\OpenRegister\Service\Flow\FlowFiringResult
 * @covers \OCA\OpenRegister\Db\FlowStream
 * @covers \OCA\OpenRegister\Db\FlowRun
 */
class FlowStreamWalkTest extends TestCase {

	private FlowRun $run;

	private FlowPlaceClaims&MockObject $claims;

	private FlowRunCommit&MockObject $commit;

	private FlowStreamMapper&MockObject $streams;

	/** @var array<int, FlowStream> */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->run = new FlowRun();
		$this->run->setUuid('run-1');
		$this->run->setFirings(3);
		$this->claims = $this->createMock(FlowPlaceClaims::class);
		$this->commit = $this->createMock(FlowRunCommit::class);
		$this->streams = $this->createMock(FlowStreamMapper::class);
		$this->streams->method('findByRun')->willReturnCallback(fn (): array => $this->rows);
	}//end setUp()

	/**
	 * A stream row.
	 *
	 * @param string $id The id.
	 * @param string $path The ordinal path.
	 * @param string|null $place The place.
	 * @param string $status The status.
	 *
	 * @return FlowStream The row.
	 */
	private function row(string $id, string $path, ?string $place, string $status = FlowRun::STATUS_RUNNING): FlowStream {
		$row = new FlowStream();
		$row->setRunUuid('run-1');
		$row->setStreamId($id);
		$row->setOrdinalPath($path);
		$row->setPlace($place);
		$row->setStatus($status);

		return $row;
	}//end row()

	/**
	 * The walk under test.
	 *
	 * @param string|null $only Restrict to one stream.
	 * @param int|null $budget A firing budget.
	 *
	 * @return FlowStreamWalk The walk.
	 */
	private function walk(?string $only = null, ?int $budget = null): FlowStreamWalk {
		return new FlowStreamWalk(
			run: $this->run,
			claims: $this->claims,
			commit: $this->commit,
			streamMapper: $this->streams,
			owner: 'pass-1',
			runCap: null,
			onlyStream: $only,
			budget: $budget
		);
	}//end walk()

	public function testAFreshRunMintsARootForItsSingleTokenInMemory(): void {
		$walk = $this->walk();
		$walk->begin(marking: ['start' => 1]);

		$root = FlowRunCommit::streamIdFor(runUuid: 'run-1', path: FlowStream::ROOT_PATH);
		$this->assertSame($root, $walk->nextStream());
		$this->assertSame('start', $walk->placeOf(id: $root));
		$this->assertSame(FlowStream::ROOT_PATH, $walk->pathOf(id: $root));
		$this->assertSame($this->run, $walk->run());
		$this->assertSame(3, $walk->firings());
	}//end testAFreshRunMintsARootForItsSingleTokenInMemory()

	public function testUnassignedTokensBecomeRootChildrenInSortedPlaceOrder(): void {
		// A pre-stream run resumed with two tokens and no rows: the back-fill's
		// rule, so the two agree.
		$walk = $this->walk();
		$walk->begin(marking: ['zeta' => 1, 'alpha' => 1]);

		$root = FlowRunCommit::streamIdFor(runUuid: 'run-1', path: '0001');
		$child = FlowRunCommit::streamIdFor(runUuid: 'run-1', path: '0001.0002');
		$this->assertSame('alpha', $walk->placeOf(id: $root));
		$this->assertSame('zeta', $walk->placeOf(id: $child));
		$this->assertSame('0001.0002', $walk->pathOf(id: $child));
	}//end testUnassignedTokensBecomeRootChildrenInSortedPlaceOrder()

	public function testPersistedStreamsKeepTheirPlacesAndTerminalOnesAreIgnored(): void {
		$this->rows = [
			$this->row('a', '0001.0001', 'p1'),
			$this->row('b', '0001.0002', 'p2', FlowRun::STATUS_SUSPENDED),
			$this->row('done', '0001.0003', 'p3', FlowRun::STATUS_COMPLETED),
		];
		$walk = $this->walk();
		$walk->begin(marking: ['p1' => 1, 'p2' => 1]);

		// Round-robin in ordinal order; the suspended stream is eligible again
		// (the run was woken), the completed one is not a stream any more.
		$this->assertSame('a', $walk->nextStream());
		$this->assertSame('b', $walk->nextStream());
		$this->assertSame('a', $walk->nextStream());
		$this->assertNull($walk->placeOf(id: 'done'));
		$this->assertSame(['b'], $walk->streamsOn(places: ['p2', 'p9'], except: 'a'));
	}//end testPersistedStreamsKeepTheirPlacesAndTerminalOnesAreIgnored()

	public function testExhaustedParkedAndRefusedStreamsLeaveTheRotation(): void {
		$this->rows = [$this->row('a', '0001.0001', 'p1'), $this->row('b', '0001.0002', 'p2'), $this->row('c', '0001.0003', 'p3')];
		$walk = $this->walk();
		$walk->begin(marking: ['p1' => 1, 'p2' => 1, 'p3' => 1]);

		$walk->exhaust(id: 'a');
		$this->claims->method('acquire')->willReturn(null);
		$this->assertNull($walk->claim(id: 'b', transition: 'T', places: ['p2', 'q']));
		$this->assertTrue($walk->anyRefused());
		$this->commit->method('park')->willReturn([]);
		$walk->park(id: 'c', resumeAt: new DateTime('+1 hour'), reason: 'timer', claimed: ['p3'], enabled: false);
		$this->assertTrue($walk->anyParked());

		$this->assertNull($walk->nextStream());
	}//end testExhaustedParkedAndRefusedStreamsLeaveTheRotation()

	public function testOnlyStreamAndBudgetScopeAnInRequestAdvance(): void {
		$this->rows = [$this->row('a', '0001.0001', 'p1'), $this->row('b', '0001.0002', 'p2')];
		$walk = $this->walk(only: 'b', budget: 1);
		$walk->begin(marking: ['p1' => 1, 'p2' => 1]);

		$this->assertSame('b', $walk->nextStream());
		$this->assertSame('b', $walk->nextStream());
		$this->assertFalse($walk->budgetSpent());

		$this->commit->method('commitFiring')->willReturnCallback(
			fn (FlowRun $run, FlowFiring $firing, string $owner): FlowFiringResult => new FlowFiringResult(
				marking: ['p1' => 1, 'q' => 1],
				placeItems: [],
				streams: [$this->row('a', '0001.0001', 'p1'), $this->row('b', '0001.0002', 'q')],
				firings: 4
			)
		);
		$walk->commitFiring(id: 'b', transition: 'T', froms: ['p2'], taken: ['q'], placeItems: ['q' => []], claimed: ['p2', 'q'], logEntry: ['status' => 'completed'], enabledAfter: false);

		$this->assertTrue($walk->budgetSpent());
		$this->assertSame('q', $walk->placeOf(id: 'b'));
	}//end testOnlyStreamAndBudgetScopeAnInRequestAdvance()

	public function testAClaimIsTakenOnFromsUnionTosWithThePassOwner(): void {
		$this->rows = [$this->row('a', '0001', 'p1')];
		$walk = $this->walk();
		$walk->begin(marking: ['p1' => 1]);

		$this->claims->expects($this->once())->method('acquire')
			->with('run-1', 'a', 'T', ['p1', 'q'], 'pass-1', null)
			->willReturn(['p1', 'q']);
		$this->assertSame(['p1', 'q'], $walk->claim(id: 'a', transition: 'T', places: ['p1', 'q']));
		$this->assertFalse($walk->anyRefused());

		$this->claims->expects($this->once())->method('release')->with('run-1', ['p1', 'q']);
		$walk->release(places: ['p1', 'q']);
	}//end testAClaimIsTakenOnFromsUnionTosWithThePassOwner()

	public function testACommitHandsTheFiringsDescriptorAndReReadsTheStreamPicture(): void {
		$this->rows = [$this->row('a', '0001', 's')];
		$walk = $this->walk();
		$walk->begin(marking: ['s' => 1]);

		$captured = null;
		$this->commit->method('commitFiring')->willReturnCallback(
			function (FlowRun $run, FlowFiring $firing, string $owner) use (&$captured): FlowFiringResult {
				$captured = $firing;
				return new FlowFiringResult(
					marking: ['x' => 1, 'y' => 1],
					placeItems: ['x' => [], 'y' => []],
					streams: [
						$this->row('a', '0001', null, FlowRun::STATUS_COMPLETED),
						$this->row('a1', '0001.0001', 'x', FlowRun::STATUS_QUEUED),
						$this->row('a2', '0001.0002', 'y', FlowRun::STATUS_QUEUED),
					],
					firings: 4
				);
			}
		);

		$walk->exhaust(id: 'a');
		$result = $walk->commitFiring(
			id: 'a',
			transition: 'split',
			froms: ['s'],
			taken: ['x', 'y'],
			placeItems: ['x' => [['json' => 1]], 'y' => [['json' => 2]]],
			claimed: ['s', 'x', 'y'],
			logEntry: ['transition' => 'split', 'status' => 'completed'],
			enabledAfter: true
		);

		$this->assertSame('pass-1', 'pass-1');
		$this->assertInstanceOf(FlowFiring::class, $captured);
		$this->assertSame('0001', $captured->streamPath);
		$this->assertSame(['s'], $captured->froms);
		$this->assertSame(['x', 'y'], $captured->taken);
		$this->assertSame([['json' => 1]], $captured->itemsByPlace['x']);
		$this->assertSame(4, $result->firings);
		// The picture is the commit's: the carrier is gone, the children are live
		// in ordinal order, and a commit clears the exhausted set.
		$this->assertSame('a1', $walk->nextStream());
		$this->assertSame('a2', $walk->nextStream());
		$this->assertSame('x', $walk->placeOf(id: 'a1'));
	}//end testACommitHandsTheFiringsDescriptorAndReReadsTheStreamPicture()

	public function testWorkRemainsIgnoresTransitionsOnlyParkedStreamsKeepEnabled(): void {
		$this->rows = [$this->row('a', '0001.0001', 'wait'), $this->row('b', '0001.0002', 'work')];
		$walk = $this->walk();
		$walk->begin(marking: ['wait' => 1, 'work' => 1]);
		$this->commit->method('park')->willReturn([]);

		$onWait = new Transition('wait', ['wait'], ['after-wait']);
		$onWork = new Transition('work', ['work'], ['after-work']);

		$this->assertTrue($walk->workRemains(transitions: [$onWait, $onWork]));
		$walk->park(id: 'a', resumeAt: null, reason: 'signal', claimed: ['wait', 'after-wait'], enabled: true);
		$this->assertTrue($walk->workRemains(transitions: [$onWait, $onWork]));
		$this->assertFalse($walk->workRemains(transitions: [$onWait]));
	}//end testWorkRemainsIgnoresTransitionsOnlyParkedStreamsKeepEnabled()

	public function testEndStreamAndFinalizeDelegateToTheCommitPath(): void {
		$this->rows = [$this->row('a', '0001', 'p')];
		$walk = $this->walk();
		$walk->begin(marking: ['p' => 1]);

		$this->commit->expects($this->once())->method('endStream')->willReturn([]);
		$walk->endStream(id: 'a', status: FlowRun::STATUS_FAILED, error: 'boom', claimed: ['p'], enabled: false);
		$this->assertNull($walk->nextStream());

		$this->commit->expects($this->once())->method('finalize')
			->with($this->run, 'pass-1', false, FlowRun::STATUS_FAILED)
			->willReturn(FlowRun::STATUS_FAILED);
		$this->assertSame(FlowRun::STATUS_FAILED, $walk->finalize(enabled: false, forcedTerminal: FlowRun::STATUS_FAILED));
	}//end testEndStreamAndFinalizeDelegateToTheCommitPath()
}//end class
