<?php

/**
 * The engine's per-stream walk, driven through a FAKE stream collaborator.
 *
 * The fake stands in for claims and the commit path so the properties under
 * test are the WALK's: a suspension parks its stream and the sibling keeps
 * advancing; a refused claim skips the candidate without dispatching it and
 * the run ends the pass queued; the ceiling counts the run; an oversight
 * refusal ends the run; and a single-stream flow walks exactly as the legacy
 * in-memory walk does. The database, the lock and the unique index are not
 * exercised here.
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
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowFiringResult;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunMarkingStore;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\FlowStreamWalk;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;

/**
 * A dispatcher that suspends on a named step and records the rest.
 */
class StreamWalkDispatcher implements FlowStepDispatcher {
	/** @var array<int, string> */
	public array $dispatched = [];

	public function __construct(
		private readonly ?string $suspendOn = null,
		private readonly ?string $failOn = null,
	) {
	}

	public function dispatch(array $step, array $items, array $context): array {
		$id = (string)($step['id'] ?? '');
		$this->dispatched[] = $id;
		if ($id === $this->suspendOn) {
			throw new FlowSuspension(resumeAt: null, reason: 'waiting on a person');
		}

		if ($id === $this->failOn) {
			throw new \RuntimeException('boom');
		}

		return $items;
	}
}//end class

/**
 * The walk.
 */
class FlowEngineStreamWalkTest extends TestCase {

	private FlowEngine $engine;

	private FlowRun $run;

	/**
	 * What the fake collaborator saw.
	 *
	 * @var array<string, mixed>
	 */
	private array $seen = ['claims' => [], 'commits' => [], 'parked' => [], 'ended' => [], 'finalized' => null, 'released' => []];

	/**
	 * Transition names whose claims the fake refuses.
	 *
	 * @var array<int, string>
	 */
	private array $refuse = [];

	/**
	 * The firing count the fake reports before any commit.
	 *
	 * @var int
	 */
	private int $firings = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger());
		$this->run = new FlowRun();
		$this->run->setUuid('run-1');
		$this->run->setFirings(0);
	}//end setUp()

	/**
	 * A split into two branches, one of which waits.
	 *
	 * @return array The flow.
	 */
	private function splitFlow(): array {
		return [
			'id' => 'split',
			'nodes' => [
				['id' => 'start', 'type' => 'passthrough'],
				['id' => 'advice', 'type' => 'wait-for-person'],
				['id' => 'hearing', 'type' => 'passthrough'],
				['id' => 'hearing-done', 'type' => 'passthrough'],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'advice'],
				['id' => 'e2', 'from' => 'start', 'to' => 'hearing'],
				['id' => 'e3', 'from' => 'hearing', 'to' => 'hearing-done'],
			],
		];
	}//end splitFlow()

	/**
	 * A fake stream collaborator that tracks streams in memory and records
	 * every protocol call.
	 *
	 * @return FlowStreamWalk&MockObject The fake.
	 */
	private function fakeWalk(): FlowStreamWalk&MockObject {
		$walk = $this->createMock(FlowStreamWalk::class);
		$streams = [];
		$parked = [];
		$exhausted = [];
		$refused = [];
		$cursor = 0;

		$walk->method('run')->willReturnCallback(fn (): FlowRun => $this->run);
		$walk->method('begin')->willReturnCallback(function (array $marking) use (&$streams): void {
			$this->seen['begun'] = $marking;
			$i = 1;
			foreach (array_keys($marking) as $place) {
				$id = 's' . $i;
				$streams[$id] = ['place' => (string)$place, 'path' => ($i === 1) ? '0001' : FlowStream::childPath('0001', $i)];
				$i++;
			}
		});
		$walk->method('nextStream')->willReturnCallback(function () use (&$streams, &$parked, &$exhausted, &$refused, &$cursor): ?string {
			$ids = array_keys($streams);
			$n = count($ids);
			for ($k = 0; $k < $n; $k++) {
				$id = $ids[(($cursor + $k) % $n)];
				if (isset($parked[$id]) === false && isset($exhausted[$id]) === false && isset($refused[$id]) === false) {
					$cursor = ((($cursor + $k) + 1) % max(1, $n));
					$this->seen['visits'][] = $id;
					return $id;
				}
			}

			return null;
		});
		// A closure, not an arrow function: arrow functions capture by VALUE at
		// creation, which would freeze the empty stream list forever.
		$walk->method('placeOf')->willReturnCallback(function (string $id) use (&$streams): ?string {
			return ($streams[$id]['place'] ?? null);
		});
		$walk->method('exhaust')->willReturnCallback(function (string $id) use (&$exhausted): void {
			$this->seen['exhausted'][] = $id;
			$exhausted[$id] = true;
		});
		$walk->method('claim')->willReturnCallback(function (string $id, string $transition, array $places) use (&$refused): ?array {
			$this->seen['claims'][] = $transition;
			if (in_array($transition, $this->refuse, true) === true) {
				$refused[$id] = true;
				return null;
			}

			sort($places, SORT_STRING);
			return $places;
		});
		$walk->method('release')->willReturnCallback(function (array $places): void {
			$this->seen['released'][] = $places;
		});
		$walk->method('firings')->willReturnCallback(fn (): int => $this->firings);
		$walk->method('workRemains')->willReturnCallback(function (array $transitions) use (&$streams, &$parked): bool {
			$unparked = [];
			foreach ($streams as $id => $stream) {
				if (isset($parked[$id]) === false && $stream['place'] !== null) {
					$unparked[$stream['place']] = true;
				}
			}

			foreach ($transitions as $transition) {
				foreach ($transition->getFroms() as $from) {
					if (isset($unparked[(string)$from]) === true) {
						return true;
					}
				}
			}

			return false;
		});
		$walk->method('budgetSpent')->willReturn(false);
		$walk->method('commitFiring')->willReturnCallback(
			function (string $id, string $transition, array $froms, array $taken, array $placeItems, array $claimed, array $logEntry, bool $enabledAfter, string $streamStatus = 'running', ?string $streamError = null) use (&$streams, &$exhausted): FlowFiringResult {
				$this->seen['commits'][] = ['stream' => $id, 'transition' => $transition, 'froms' => $froms, 'taken' => $taken, 'status' => $logEntry['status']];
				$this->firings++;
				$this->run->setFirings($this->firings);
				// Continue or split, as the real commit would.
				$marking = FlowRunMarkingStoreTestAccess::marking($this->run);
				if (count($taken) <= 1) {
					$streams[$id]['place'] = ($taken[0] ?? null);
					if ($taken === []) {
						unset($streams[$id]);
					}
				} else {
					$parentPath = $streams[$id]['path'];
					unset($streams[$id]);
					$k = 1;
					foreach ($taken as $to) {
						$streams[$id . '.' . $k] = ['place' => $to, 'path' => FlowStream::childPath($parentPath, $k)];
						$k++;
					}
				}

				$exhausted = [];
				return new FlowFiringResult(marking: $marking, placeItems: $placeItems, streams: [], firings: $this->firings);
			}
		);
		$walk->method('park')->willReturnCallback(function (string $id, ?DateTime $resumeAt, string $reason, array $claimed, bool $enabled) use (&$parked): void {
			$this->seen['parked'][] = $id;
			$parked[$id] = true;
		});
		$walk->method('endStream')->willReturnCallback(function (string $id, string $status, ?string $error) use (&$streams): void {
			$this->seen['ended'][] = [$id, $status];
			unset($streams[$id]);
		});
		$walk->method('finalize')->willReturnCallback(function (bool $enabled, ?string $forcedTerminal = null) use (&$parked, &$refused): string {
			$this->seen['finalized'] = ['enabled' => $enabled, 'forced' => $forcedTerminal];
			if ($forcedTerminal !== null) {
				return $forcedTerminal;
			}

			if ($enabled === true) {
				return FlowRun::STATUS_QUEUED;
			}

			if ($parked !== []) {
				return FlowRun::STATUS_SUSPENDED;
			}

			return FlowRun::STATUS_COMPLETED;
		});

		return $walk;
	}//end fakeWalk()

	/**
	 * Run a flow through the stream walk.
	 *
	 * @param array $flow The flow.
	 * @param FlowStepDispatcher $dispatcher The dispatcher.
	 * @param FlowStreamWalk $walk The collaborator.
	 * @param array $context The context.
	 *
	 * @return array The result.
	 */
	private function walk(array $flow, FlowStepDispatcher $dispatcher, FlowStreamWalk $walk, array $context = []): array {
		return $this->engine->run(
			flow: $flow,
			store: new FlowRunMarkingStore(run: $this->run),
			subject: new stdClass(),
			dispatcher: $dispatcher,
			context: $context,
			items: [FlowItems::item(json: ['n' => 1])],
			startAt: null,
			streams: $walk
		);
	}//end walk()

	public function testAHumanTaskOnOneBranchDoesNotStopItsSibling(): void {
		$dispatcher = new StreamWalkDispatcher(suspendOn: 'advice');
		$result = $this->walk($this->splitFlow(), $dispatcher, $this->fakeWalk());

		// The hearing branch advanced to its end while the advice branch parked.
		$this->assertContains('hearing', $dispatcher->dispatched);
		$this->assertContains('hearing-done', $dispatcher->dispatched);
		$this->assertCount(1, $this->seen['parked']);
		// The run is reported parked only once nothing else can fire, and the
		// suspension entry names its stream.
		$this->assertSame(FlowEngine::STATUS_SUSPENDED, $result['status']);
		$suspended = array_values(array_filter($result['log'], static fn (array $e): bool => ($e['status'] ?? '') === 'suspended'));
		$this->assertCount(1, $suspended);
		$this->assertArrayHasKey('streamId', $suspended[0]);
		// The firings the walk committed carry their stream and are marked as
		// recorded, so the step history does not write them twice.
		$committed = array_values(array_filter($result['log'], static fn (array $e): bool => ($e['recorded'] ?? false) === true));
		$this->assertCount(3, $committed);
	}//end testAHumanTaskOnOneBranchDoesNotStopItsSibling()

	public function testARefusedClaimSkipsTheCandidateWithoutDispatchingAndLeavesTheRunQueued(): void {
		$this->refuse = ['hearing'];
		$dispatcher = new StreamWalkDispatcher();
		$result = $this->walk($this->splitFlow(), $dispatcher, $this->fakeWalk());

		$this->assertNotContains('hearing', $dispatcher->dispatched);
		$this->assertNotContains('hearing-done', $dispatcher->dispatched);
		$this->assertContains('advice', $dispatcher->dispatched);
		// The skipped firing stays enabled, so finalize sees enabled work and
		// the run ends the pass queued — never completed with a stranded token.
		$this->assertTrue($this->seen['finalized']['enabled']);
		$this->assertSame(FlowEngine::STATUS_QUEUED, $result['status']);
	}//end testARefusedClaimSkipsTheCandidateWithoutDispatchingAndLeavesTheRunQueued()

	public function testEveryFiringIsClaimedBeforeItIsDispatched(): void {
		$dispatcher = new StreamWalkDispatcher();
		$this->walk($this->splitFlow(), $dispatcher, $this->fakeWalk());

		// Same set: nothing was dispatched without a claim, and nothing was
		// claimed and then not accounted for by a commit or a release.
		sort($dispatcher->dispatched);
		$claimed = $this->seen['claims'];
		sort($claimed);
		$this->assertSame($dispatcher->dispatched, $claimed);
		$this->assertCount(count($dispatcher->dispatched), $this->seen['commits']);
	}//end testEveryFiringIsClaimedBeforeItIsDispatched()

	public function testTheCeilingCountsTheRunAcrossPasses(): void {
		// The persisted count already sits at the ceiling from earlier passes
		// — a cycle that parked once per lap — so this pass fires nothing and
		// fails the run with the existing message.
		$this->firings = 1000;
		$this->run->setFirings(1000);
		$dispatcher = new StreamWalkDispatcher();
		$result = $this->walk($this->splitFlow(), $dispatcher, $this->fakeWalk());

		$this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
		$this->assertStringContainsString('1000 transitions', (string)$result['error']);
		$this->assertSame([], $dispatcher->dispatched);
		$this->assertSame(FlowRun::STATUS_FAILED, $this->seen['finalized']['forced']);
	}//end testTheCeilingCountsTheRunAcrossPasses()

	public function testAnOversightRefusalEndsTheRunForEveryStream(): void {
		$check = new class implements IFlowOversightCheck {
			public int $asked = 0;

			public function getId(): string {
				return 'test.refuse-second';
			}

			public function veto(array $context): ?string {
				$this->asked++;
				// Consent to the first hop (the split), refuse everything after.
				return ($this->asked > 1) ? 'kill switch thrown' : null;
			}
		};
		$registry = new FlowOversightRegistry(logger: new NullLogger());
		$registry->register(check: $check);
		$this->engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger(), $registry);

		$dispatcher = new StreamWalkDispatcher();
		$result = $this->walk($this->splitFlow(), $dispatcher, $this->fakeWalk());

		$this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
		// Only the split ran; neither branch began a firing after the refusal.
		$this->assertSame(['start'], $dispatcher->dispatched);
		$this->assertSame(FlowRun::STATUS_STOPPED, $this->seen['finalized']['forced']);
		// The refusing check is recorded, and the refused hop's claim released.
		$stopped = array_values(array_filter($result['log'], static fn (array $e): bool => ($e['status'] ?? '') === 'stopped'));
		$this->assertSame('test.refuse-second', $stopped[0]['checkId']);
		$this->assertCount(1, $this->seen['released']);
	}//end testAnOversightRefusalEndsTheRunForEveryStream()

	public function testATerminalStepFailureEndsTheStreamAndTheRun(): void {
		$dispatcher = new StreamWalkDispatcher(failOn: 'hearing');
		$result = $this->walk($this->splitFlow(), $dispatcher, $this->fakeWalk());

		// `failed`, not `stopped`: a broken step is not a deliberate end, and
		// this walk must agree with the single-stream one about that or which
		// walk ran would decide whether the wreck is queryable.
		$this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
		$this->assertSame('boom', $result['error']);
		$this->assertCount(1, $this->seen['ended']);
		$this->assertSame(FlowRun::STATUS_FAILED, $this->seen['ended'][0][1]);
	}//end testATerminalStepFailureEndsTheStreamAndTheRun()

	public function testASingleStreamFlowWalksExactlyAsTheLegacyWalk(): void {
		$flow = [
			'id' => 'linear',
			'nodes' => [
				['id' => 'one', 'type' => 'passthrough'],
				['id' => 'two', 'type' => 'passthrough'],
				['id' => 'three', 'type' => 'passthrough'],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'one', 'to' => 'two'],
				['id' => 'e2', 'from' => 'two', 'to' => 'three'],
			],
		];

		$legacyRun = new FlowRun();
		$legacyDispatcher = new StreamWalkDispatcher();
		$legacy = $this->engine->run(
			flow: $flow,
			store: new FlowRunMarkingStore(run: $legacyRun),
			subject: new stdClass(),
			dispatcher: $legacyDispatcher,
			items: [FlowItems::item(json: ['n' => 1])]
		);

		$streamDispatcher = new StreamWalkDispatcher();
		$streamed = $this->walk($flow, $streamDispatcher, $this->fakeWalk());

		$this->assertSame($legacyDispatcher->dispatched, $streamDispatcher->dispatched);
		$this->assertSame($legacy['status'], $streamed['status']);
		$this->assertSame($legacyRun->getMarking(), $this->run->getMarking());
		$this->assertSame(
			array_column($legacy['log'], 'transition'),
			array_column($streamed['log'], 'transition')
		);
		$this->assertSame(array_column($legacy['log'], 'status'), array_column($streamed['log'], 'status'));
		$this->assertArrayNotHasKey('resumeAt', $streamed);
	}//end testASingleStreamFlowWalksExactlyAsTheLegacyWalk()

	public function testPerPlaceItemsSeedFromTheStoredColumnWhenPresent(): void {
		// A resumed run whose branches produced different items: each branch
		// resumes with ITS items, not the flat list.
		$flow = [
			'id' => 'resume',
			'nodes' => [
				['id' => 'a', 'type' => 'passthrough'],
				['id' => 'b', 'type' => 'passthrough'],
			],
			'edges' => [],
		];
		$this->run->setMarking(['a' => 1, 'b' => 1]);
		$this->run->setPlaceItems(['a' => [FlowItems::item(json: ['branch' => 'A'])], 'b' => [FlowItems::item(json: ['branch' => 'B'])]]);

		$seen = [];
		$dispatcher = new class($seen) implements FlowStepDispatcher {
			public array $seen = [];

			public function __construct(array $seen) {
				$this->seen = $seen;
			}

			public function dispatch(array $step, array $items, array $context): array {
				$this->seen[(string)$step['id']] = array_column(array_column($items, 'json'), 'branch');
				return $items;
			}
		};

		$this->walk($flow, $dispatcher, $this->fakeWalk());

		$this->assertSame(['A'], $dispatcher->seen['a']);
		$this->assertSame(['B'], $dispatcher->seen['b']);
	}//end testPerPlaceItemsSeedFromTheStoredColumnWhenPresent()
}//end class

/**
 * Reads the run's marking the way the store normalises it.
 */
class FlowRunMarkingStoreTestAccess {
	public static function marking(FlowRun $run): array {
		$out = [];
		foreach ((array)($run->getMarking() ?? []) as $k => $v) {
			if (is_int($k) === true) {
				$out[(string)$v] = 1;
				continue;
			}

			$out[(string)$k] = max(1, (int)$v);
		}

		return $out;
	}
}//end class
