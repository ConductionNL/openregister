<?php

/**
 * A run's locks are released when the run ENDS, and at no other moment.
 *
 * WHY THIS DRIVES THE REAL ENGINE
 * -------------------------------
 * Release layer 1 is `FlowRunMapper::update()` dispatching
 * `FlowRunTerminalEvent` whenever the persisted row `isTerminal()`. Every
 * collaborator between the walk and that predicate is production code here —
 * the engine, `FlowStreamWalk`, `FlowRunCommit`, the mapper itself and the
 * listener — because the defect this test exists for lives BETWEEN them: the
 * commit path derived a terminal status for a run that was still working, and
 * every test that mocked `FlowRunMapper::update()` (FlowRunCommitTest) or
 * restated the walk's `workRemains()` in a fake (FlowEngineStreamWalkTest)
 * agreed with the bug. Only the database is replaced.
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
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Listener\FlowRunLockReleaseListener;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowPlaceClaims;
use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\Flow\FlowRunMarkingStore;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowStreamWalk;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCA\OpenRegister\Tests\Unit\Db\FluentQueryBuilderTrait;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

/**
 * A mapper that reads its locked row from memory and writes through the real
 * `update()` — the dispatch predicate under test.
 */
class InMemoryFlowRunMapper extends FlowRunMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The (mocked) connection.
	 * @param IEventDispatcher $dispatcher The spy dispatcher.
	 * @param FlowRun $row The one run row.
	 */
	public function __construct(
		IDBConnection $db,
		IEventDispatcher $dispatcher,
		private readonly FlowRun $row,
	) {
		parent::__construct(db: $db, dispatcher: $dispatcher);
	}

	/**
	 * The locked read, from memory.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return FlowRun The row.
	 */
	public function lockByUuid(string $uuid): FlowRun {
		return $this->row;
	}
}//end class

/**
 * A dispatcher that suspends, stops or fails on a named step.
 */
class LockLifecycleDispatcher implements FlowStepDispatcher {

	/**
	 * Constructor.
	 *
	 * @param string|null $suspendOn Node id that parks the run.
	 * @param string|null $stopOn Node id that raises a FlowStop.
	 * @param string|null $failOn Node id that throws.
	 */
	public function __construct(
		private readonly ?string $suspendOn = null,
		private readonly ?string $stopOn = null,
		private readonly ?string $failOn = null,
	) {
	}

	/**
	 * Dispatch one step.
	 *
	 * @param array $step The step.
	 * @param array $items The items.
	 * @param array $context The context.
	 *
	 * @return array The items.
	 */
	public function dispatch(array $step, array $items, array $context): array {
		$id = (string)($step['id'] ?? '');
		if ($id === $this->suspendOn) {
			throw new FlowSuspension(resumeAt: new \DateTime('+5 minutes'), reason: 'waiting on a person');
		}

		if ($id === $this->stopOn) {
			throw new FlowStop(reason: 'the author asked it to stop');
		}

		if ($id === $this->failOn) {
			throw new RuntimeException('boom');
		}

		return $items;
	}
}//end class

/**
 * Release layer 1, end to end.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunCommit
 * @covers \OCA\OpenRegister\Service\Flow\FlowStreamWalk
 * @covers \OCA\OpenRegister\Listener\FlowRunLockReleaseListener
 */
class RunLockReleaseTerminalityTest extends TestCase {
	use FluentQueryBuilderTrait;

	private const RUN = 'run-lock-1';

	/**
	 * The run row.
	 */
	private FlowRun $row;

	/**
	 * The stream rows by id.
	 *
	 * @var array<string, FlowStream>
	 */
	private array $streams = [];

	/**
	 * Every run uuid whose locks were released, in order.
	 *
	 * @var array<int, string>
	 */
	private array $released = [];

	/**
	 * Every terminal status announced, in order.
	 *
	 * @var array<int, string>
	 */
	private array $announced = [];

	/**
	 * The walk over the real commit path.
	 */
	private FlowStreamWalk $walk;

	/**
	 * The engine.
	 */
	private FlowEngine $engine;

	protected function setUp(): void {
		parent::setUp();

		$this->row = new FlowRun();
		$this->row->setId(1);
		$this->row->setUuid(self::RUN);
		$this->row->setFlowId('flow-1');
		$this->row->setStatus(FlowRun::STATUS_RUNNING);
		$this->row->setFirings(0);

		$db = $this->connectionWith();

		// The listener, wired to the dispatcher exactly as Application.php
		// wires it, over a registry that records what it was asked to release.
		$registry = $this->createMock(RunLockRegistry::class);
		$registry->method('releaseRunLocks')->willReturnCallback(function (string $runUuid): int {
			$this->released[] = $runUuid;
			return 1;
		});
		$listener = new FlowRunLockReleaseListener(locks: $registry, logger: new NullLogger());

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event) use ($listener): void {
			if ($event instanceof FlowRunTerminalEvent === true) {
				$this->announced[] = $event->getStatus();
			}

			$listener->handle($event);
		});

		$runs = new InMemoryFlowRunMapper(db: $db, dispatcher: $dispatcher, row: $this->row);

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
			$stream->setNextSequence(($next + 1));
			return $next;
		});

		$claimRows = [];
		$claims = $this->createMock(FlowClaimMapper::class);
		$claims->method('findByRun')->willReturnCallback(static fn (): array => array_values($claimRows));
		$claims->method('release')->willReturn(0);
		$claims->method('releaseByOwner')->willReturn(0);

		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(0);
		$steps->method('insert')->willReturnCallback(static fn (FlowRunStep $step): FlowRunStep => $step);

		$commit = new FlowRunCommit(
			db: $db,
			runs: $runs,
			streams: $streams,
			claims: $claims,
			steps: $steps,
			logger: new NullLogger()
		);

		// Claims are not the property under test: every acquire succeeds.
		$places = $this->createMock(FlowPlaceClaims::class);
		$places->method('acquire')->willReturnCallback(static function (string $runUuid, string $streamId, string $transition, array $places): ?array {
			sort($places, SORT_STRING);
			return $places;
		});

		$this->walk = new FlowStreamWalk(
			run: $this->row,
			claims: $places,
			commit: $commit,
			streamMapper: $streams,
			owner: 'pass-1'
		);

		$this->engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger());
	}//end setUp()

	/**
	 * A three-node line: two ordinary steps and a third that the dispatcher
	 * decides the fate of.
	 *
	 * @return array The flow.
	 */
	private function flow(): array {
		return [
			'id' => 'lock-then-wait',
			'nodes' => [
				['id' => 'start', 'type' => 'passthrough'],
				['id' => 'lock', 'type' => 'openregister.lock-object'],
				['id' => 'decide', 'type' => 'passthrough'],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'lock'],
				['id' => 'e2', 'from' => 'lock', 'to' => 'decide'],
			],
		];
	}//end flow()

	/**
	 * Walk the flow.
	 *
	 * @param FlowStepDispatcher $dispatcher The dispatcher.
	 *
	 * @return array The result envelope.
	 */
	private function walkFlow(FlowStepDispatcher $dispatcher): array {
		return $this->engine->run(
			flow: $this->flow(),
			store: new FlowRunMarkingStore(run: $this->row),
			subject: new stdClass(),
			dispatcher: $dispatcher,
			context: [],
			items: [FlowItems::item(json: ['n' => 1])],
			startAt: null,
			streams: $this->walk
		);
	}//end walkFlow()

	/**
	 * A run that parks keeps every lock it holds.
	 *
	 * The run reaches `decide`, which waits for a person. Nothing about that
	 * is terminal, so no terminal event may be announced and the registry
	 * must not be asked to release anything — the case stays locked while its
	 * flow works, which is the entire point of a run-scoped lock.
	 *
	 * @return void
	 */
	public function testAParkedRunKeepsItsLocks(): void {
		$result = $this->walkFlow(new LockLifecycleDispatcher(suspendOn: 'decide'));

		$this->assertSame(FlowRun::STATUS_SUSPENDED, $result['status']);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, (string)$this->row->getStatus());
		$this->assertFalse($this->row->isTerminal(), 'the run is not terminal');
		$this->assertSame([], $this->announced, 'a working run announced terminality: ' . implode(',', $this->announced));
		$this->assertSame([], $this->released, 'a parked run lost its locks');
	}//end testAParkedRunKeepsItsLocks()

	/**
	 * Every terminal outcome releases the run's locks — the four the
	 * production constant names, not a list restated here.
	 *
	 * @return void
	 */
	public function testEveryTerminalOutcomeReleasesTheLocks(): void {
		$drivers = [
			FlowRun::STATUS_COMPLETED => static fn (): FlowStepDispatcher => new LockLifecycleDispatcher(),
			FlowRun::STATUS_STOPPED => static fn (): FlowStepDispatcher => new LockLifecycleDispatcher(stopOn: 'decide'),
			FlowRun::STATUS_FAILED => static fn (): FlowStepDispatcher => new LockLifecycleDispatcher(failOn: 'decide'),
			FlowRun::STATUS_DEAD_LETTER => static fn (): FlowStepDispatcher => new LockLifecycleDispatcher(failOn: 'decide'),
		];

		$this->assertSame(
			[],
			array_values(array_diff(FlowRun::TERMINAL, array_keys($drivers))),
			'a terminal status shipped with no driver here, so its release goes untested'
		);
		$this->assertCount(count(FlowRun::TERMINAL), $drivers, 'a driver names a status that is not terminal');

		foreach (FlowRun::TERMINAL as $status) {
			$this->setUp();
			$flow = $this->flow();
			if ($status === FlowRun::STATUS_DEAD_LETTER) {
				$flow['nodes'][2]['onError'] = FlowEngine::ON_ERROR_DEAD_LETTER;
			}

			$result = $this->engine->run(
				flow: $flow,
				store: new FlowRunMarkingStore(run: $this->row),
				subject: new stdClass(),
				dispatcher: $drivers[$status](),
				context: [],
				items: [FlowItems::item(json: ['n' => 1])],
				startAt: null,
				streams: $this->walk
			);

			$this->assertSame($status, $result['status'], 'the walk did not reach ' . $status);
			$this->assertTrue($this->row->isTerminal(), $status . ' left a non-terminal row');
			$this->assertContains($status, $this->announced, $status . ' was never announced');
			$this->assertContains(self::RUN, $this->released, $status . ' did not release the run\'s locks');
		}
	}//end testEveryTerminalOutcomeReleasesTheLocks()
}//end class
