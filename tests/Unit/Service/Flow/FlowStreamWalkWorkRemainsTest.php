<?php

/**
 * Unit tests for which enabled transitions still count as work, and which
 * streams the walk still visits.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Service\Flow\FlowFiring;
use OCA\OpenRegister\Service\Flow\FlowFiringResult;
use OCA\OpenRegister\Service\Flow\FlowPlaceClaims;
use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\Flow\FlowStreamHalt;
use OCA\OpenRegister\Service\Flow\FlowStreamWalk;
use OCA\OpenRegister\Service\Flow\FlowStreamWalkLimits;
use OCA\OpenRegister\Service\Flow\FlowStreamWalkParts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Transition;

class FlowStreamWalkWorkRemainsTest extends TestCase {

	/**
	 * Transitions, the commit's effect, parked streams and the answer.
	 *
	 * @return array<string, array{0: array<int, array{0: string, 1: array<int, string>}>, 1: array<int, string>, 2: string|null, 3: array<int, string>, 4: bool}>
	 */
	public static function cases(): array {
		$wait = ['wait', ['wait']];
		$work = ['work', ['work']];
		$after = ['after', ['after']];
		$numeric = ['numeric', ['7']];
		$join = ['join', ['wait', 'work']];
		return [
			'no transitions' => [[], [], null, [], false],
			'a live stream\'s transition' => [[$work], [], null, [], true],
			'an unmarked place' => [[$after], [], null, [], false],
			'a produced place' => [[$after], ['after'], null, [], true],
			'a produced numeric place' => [[$numeric], ['7'], null, [], true],
			'the settling stream\'s place' => [[$work], [], 'b', [], false],
			'settling elsewhere' => [[$work], [], 'a', [], true],
			'settling, but produced again' => [[$work], ['work'], 'b', [], true],
			'a parked stream\'s place' => [[$wait], [], null, ['a'], false],
			'a parked stream\'s place, produced again' => [[$wait], ['wait'], null, ['a'], true],
			'a join with one live input' => [[$join], [], null, ['a'], true],
			'a join, both inputs set aside' => [[$join], [], 'b', ['a'], false],
			'a numeric stream id is not matched by its string' => [[$numeric], [], '7', [], true],
			'a placeless stream counts nothing' => [[['none', ['']]], [], null, [], false],
		];
	}

	/**
	 * @param array<int, array{0: string, 1: array<int, string>}> $transitions The enabled transitions.
	 * @param array<int, string>                                   $produced    Places the commit marks.
	 * @param string|null                                          $settling    The stream that is parking or ending.
	 * @param array<int, string>                                   $parked      Streams parked this pass.
	 * @param bool                                                 $expected    Whether work remains.
	 */
	#[DataProvider('cases')]
	public function testWorkRemains(array $transitions, array $produced, ?string $settling, array $parked, bool $expected): void {
		$walk = $this->walk(only: null);
		foreach ($parked as $id) {
			$walk->park(halt: (new FlowStreamHalt(streamId: $id, claimed: [], enabled: true))->because(reason: 'wait'), resumeAt: null);
		}

		$enabled = array_map(static fn (array $t): Transition => new Transition($t[0], $t[1], ['out']), $transitions);
		$this->assertSame($expected, $walk->workRemains(transitions: $enabled, produced: $produced, settling: $settling));
	}

	public function testAnInRequestAdvanceOnAnUnknownStreamVisitsNothing(): void {
		$this->assertNull($this->walk(only: 'zz', third: 'c')->nextStream());
	}

	public function testAnInRequestAdvanceVisitsOnlyItsStream(): void {
		$walk = $this->walk(only: 'b', third: 'c');
		$this->assertSame('b', $walk->nextStream());
		$walk->exhaust(id: 'b');
		$this->assertNull($walk->nextStream());
	}

	public function testTheRotationSkipsEachSetAsideStream(): void {
		$walk = $this->walk(only: null, third: 'c');
		$this->assertSame(['a', 'b', 'c', 'a'], [$walk->nextStream(), $walk->nextStream(), $walk->nextStream(), $walk->nextStream()]);
		$walk->exhaust(id: 'b');
		$this->assertSame(['c', 'a', 'c'], [$walk->nextStream(), $walk->nextStream(), $walk->nextStream()]);
	}

	public function testAPlacelessStreamMakesNoPlaceLive(): void {
		$walk = $this->walk(only: null, third: 'c', extra: $this->row('d', '0001.0004', null));
		$this->assertFalse($walk->workRemains(transitions: [new Transition('none', [''], ['out'])]));
	}

	public function testACommitMakesExhaustedStreamsVisitableAgain(): void {
		$walk = $this->walk(only: null, third: 'c');
		$walk->exhaust(id: 'a');
		$walk->exhaust(id: 'b');
		$walk->exhaust(id: 'c');
		$this->assertNull($walk->nextStream());

		$walk->commitFiring(firing: (new FlowFiring(streamId: 'c', transition: 'T', froms: ['c'], taken: ['c']))->withLogEntry(logEntry: [], enabledAfter: true));
		$this->assertSame('a', $walk->nextStream());
	}

	/**
	 * A walk over streams `a` on `wait`, `b` on `work` and a third stream on
	 * the place of its own name.
	 */
	private function walk(?string $only, string $third = '7', ?FlowStream $extra = null): FlowStreamWalk {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$rows = [$this->row('a', '0001.0001', 'wait'), $this->row('b', '0001.0002', 'work'), $this->row($third, '0001.0003', $third)];
		if ($extra !== null) {
			$rows[] = $extra;
		}

		$mapper = $this->createMock(FlowStreamMapper::class);
		$mapper->method('findByRun')->willReturn($rows);
		$commit = $this->createMock(FlowRunCommit::class);
		$commit->method('closeStream')->willReturn([]);
		$commit->method('commitFiring')->willReturn(new FlowFiringResult(marking: [], placeItems: [], streams: $rows, firings: 1));

		$walk = new FlowStreamWalk(
			run: $run,
			parts: new FlowStreamWalkParts(claims: $this->createMock(FlowPlaceClaims::class), commit: $commit, streamMapper: $mapper),
			limits: new FlowStreamWalkLimits(owner: 'pass-1', onlyStream: $only)
		);
		$walk->begin(marking: ['wait' => 1, 'work' => 1, $third => 1]);

		return $walk;
	}

	private function row(string $id, string $path, ?string $place): FlowStream {
		$row = new FlowStream();
		$row->setRunUuid('run-1');
		$row->setStreamId($id);
		$row->setOrdinalPath($path);
		$row->setPlace($place);
		$row->setStatus(FlowRun::STATUS_RUNNING);

		return $row;
	}
}
