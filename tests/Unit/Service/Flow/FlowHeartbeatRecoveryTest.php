<?php

/**
 * The heartbeat recovers a missed completion signal instead of rolling forever.
 *
 * The wedge this file pins down, observed live: a user task's completion
 * signal was REFUSED (the assignee group did not exist at signal time), and
 * the suspended run's heartbeat then re-suspended every thirty minutes without
 * ever advancing — `resume_at` rolled 08:07 → 08:37 → … while the task sat
 * `completed`. The root cause was not in the node, which has always re-read
 * its task on re-entry: it was `persistResult()` dropping EVERY parked node's
 * resume slot whenever a pass ended `queued` — which the in-request advance of
 * a sibling branch does whenever other work remains enabled. The parked node
 * lost the uuid of the task it was waiting on, asked again on the next wake,
 * and from then on no completion of the ORIGINAL task could ever address the
 * node's slot.
 *
 * Driven through the REAL engine, dispatcher, node, stream walk, claims and
 * commit path over in-memory mappers, so the pass-to-pass persistence that
 * loses the slot is exercised exactly as the worker exercises it. Only the
 * task bridge is mocked: tasks are rows in another service's table.
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
 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md
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
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowPlaceClaims;
use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** A subject carrying nothing — the marking lives on the run. */
class HeartbeatSubject {
}

/** A step that passes items through, to split the graph. */
class HeartbeatPassNode implements IFlowNode {

	public function getId(): string {
		return 'test.pass';
	}

	public function getDisplayName(): string {
		return 'Pass';
	}

	public function getDescription(): string {
		return 'Passes items through.';
	}

	public function getIcon(): string {
		return 'i.svg';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(array $items, array $config, array $context): array {
		return $items;
	}
}//end class

/**
 * The wedge, reproduced and recovered.
 */
class FlowHeartbeatRecoveryTest extends TestCase {
	use PublishedVersionDouble;

	private FlowRunService $service;

	/**
	 * The tasks the bridge "persisted", by uuid.
	 *
	 * @var array<string, Task>
	 */
	private array $tasks = [];

	/**
	 * Node ids handed to createTask, in call order. Growing past one entry
	 * per user-task node is the duplicate-task defect.
	 *
	 * @var array<int, string>
	 */
	private array $created = [];

	/**
	 * Task uuids handed to recordHeartbeatRecovery, in call order.
	 *
	 * @var array<int, string>
	 */
	private array $recovered = [];

	/**
	 * The "database": one run row, its streams, its claims.
	 *
	 * @var FlowRun|null
	 */
	private ?FlowRun $row = null;

	/** @var array<string, FlowStream> */
	private array $streams = [];

	/** @var array<int, FlowClaim> */
	private array $claims = [];

	protected function setUp(): void {
		parent::setUp();
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('insert')->willReturnCallback(function (FlowRun $run): FlowRun {
			$this->row = $run;
			return $run;
		});
		$mapper->method('update')->willReturnCallback(function (FlowRun $run): FlowRun {
			$this->row = $run;
			return $run;
		});
		$mapper->method('lockByUuid')->willReturnCallback(fn (): FlowRun => $this->row);

		$bridge = $this->createMock(FlowTaskBridge::class);
		$bridge->method('createTask')->willReturnCallback(function (array $data, string $runUuid, string $nodeId, ?string $actor): Task {
			$this->created[] = $nodeId;
			$uuid = sprintf('t-%s-%d', $nodeId, count($this->created));
			$task = new Task();
			$task->setUuid($uuid);
			$task->setState(Task::STATE_ACTIVE);
			$task->setAssignee('alice');
			$task->setRunUuid($runUuid);
			$task->setNodeId($nodeId);
			$this->tasks[$uuid] = $task;

			return $task;
		});
		$bridge->method('taskOrNull')->willReturnCallback(fn (string $uuid): ?Task => ($this->tasks[$uuid] ?? null));
		$bridge->method('recordHeartbeatRecovery')->willReturnCallback(function (Task $task): void {
			$this->recovered[] = (string)$task->getUuid();
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));

		$node = new UserTaskNode(
			$bridge,
			$l10n,
			$this->createMock(IURLGenerator::class),
			$forms,
			$this->createMock(FlowTimerService::class)
		);

		$pass = new HeartbeatPassNode();
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($node, $pass): void {
				if ($event instanceof RegisterFlowNodesEvent) {
					$event->registerNode($node);
					$event->registerNode($pass);
				}
			}
		);

		$registry = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));

		$container = $this->createMock(ContainerInterface::class);
		$versions = $this->publishedVersionMapper();
		$pin = $this->pinReturning();
		$container->method('get')->willReturnCallback(
			function (string $id) use ($versions, $pin): object {
				if ($id === \OCA\OpenRegister\Db\FlowVersionMapper::class) {
					return $versions;
				}

				if ($id === \OCA\OpenRegister\Service\Flow\FlowDefinitionPin::class) {
					return $pin;
				}

				throw new \RuntimeException('not available');
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);

		$streamMapper = $this->createMock(FlowStreamMapper::class);
		$streamMapper->method('findByRun')->willReturnCallback(function (): array {
			$list = array_values($this->streams);
			usort($list, static fn (FlowStream $a, FlowStream $b): int => strcmp((string)$a->getOrdinalPath(), (string)$b->getOrdinalPath()));
			return $list;
		});
		$streamMapper->method('findByRunAndStream')->willReturnCallback(fn (string $runUuid, string $streamId): ?FlowStream => ($this->streams[$streamId] ?? null));
		$streamMapper->method('insert')->willReturnCallback(function (FlowStream $stream): FlowStream {
			$this->streams[(string)$stream->getStreamId()] = $stream;
			return $stream;
		});
		$streamMapper->method('update')->willReturnCallback(function (FlowStream $stream): FlowStream {
			$this->streams[(string)$stream->getStreamId()] = $stream;
			return $stream;
		});
		$streamMapper->method('allocateNextSequence')->willReturnCallback(function (string $runUuid, string $streamId): int {
			$stream = ($this->streams[$streamId] ?? null);
			if ($stream === null) {
				return 0;
			}

			$next = (int)$stream->getNextSequence();
			$stream->setNextSequence($next + 1);
			return $next;
		});

		$claimMapper = $this->createMock(FlowClaimMapper::class);
		$claimMapper->method('countHeldForRun')->willReturn(0);
		$claimMapper->method('countHeldByOwner')->willReturn(0);
		$claimMapper->method('insertOrRefuse')->willReturnCallback(function (FlowClaim $claim): bool {
			$this->claims[] = $claim;
			return true;
		});
		$claimMapper->method('findByRun')->willReturnCallback(fn (): array => array_values($this->claims));
		$claimMapper->method('release')->willReturnCallback(function (string $runUuid, array $places): int {
			$before = count($this->claims);
			$this->claims = array_values(array_filter($this->claims, static fn (FlowClaim $c): bool => in_array($c->getPlace(), $places, true) === false));
			return ($before - count($this->claims));
		});
		$claimMapper->method('releaseByOwner')->willReturnCallback(function (string $runUuid, string $owner): int {
			$before = count($this->claims);
			$this->claims = array_values(array_filter($this->claims, static fn (FlowClaim $c): bool => $c->getOwner() !== $owner));
			return ($before - count($this->claims));
		});

		$stepMapper = $this->createMock(FlowRunStepMapper::class);
		$stepMapper->method('highestSequence')->willReturn(0);
		$stepMapper->method('insert')->willReturnCallback(static fn (FlowRunStep $step): FlowRunStep => $step);

		$commit = new FlowRunCommit(
			db: $db,
			runs: $mapper,
			streams: $streamMapper,
			claims: $claimMapper,
			steps: $stepMapper,
			logger: new NullLogger()
		);

		$this->service = new FlowRunService(
			$mapper,
			$this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
			$engine,
			$registry,
			$this->createMock(LoggerInterface::class),
			$container,
			null,
			null,
			$streamMapper,
			new FlowPlaceClaims(claims: $claimMapper, db: $db, logger: new NullLogger()),
			$commit
		);
	}//end setUp()

	/**
	 * A split into two parallel user-task branches — the shape whose sibling
	 * completion ends a pass `queued` and used to drop the other slot.
	 *
	 * @return array The flow document.
	 */
	private function flow(): array {
		return [
			'id' => 'f1',
			'nodes' => [
				['id' => 'start', 'type' => 'test.pass'],
				[
					'id' => 'askA',
					'type' => 'openregister.user-task',
					'config' => ['title' => 'Approve A', 'assignee' => 'alice', 'heartbeatMinutes' => 30],
				],
				[
					'id' => 'askB',
					'type' => 'openregister.user-task',
					'config' => ['title' => 'Approve B', 'assignee' => 'alice', 'heartbeatMinutes' => 30, 'outcomeKey' => 'taskB'],
				],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'askA'],
				['id' => 'e2', 'from' => 'start', 'to' => 'askB'],
			],
		];
	}//end flow()

	/**
	 * Park both branches on their freshly created tasks.
	 *
	 * @return FlowRun The suspended run.
	 */
	private function suspendedOnBothTasks(): FlowRun {
		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute(
			$run,
			$this->flow(),
			new HeartbeatSubject(),
			seedItems: [FlowItems::item(json: ['name' => 'Case 7'])]
		);

		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		$this->assertSame(['askA', 'askB'], $this->created);

		return $run;
	}//end suspendedOnBothTasks()

	/**
	 * Mark a task terminal, as its completion verb would have left it.
	 *
	 * @param string $uuid The task.
	 * @param string $completedBy Who answered.
	 *
	 * @return void
	 */
	private function complete(string $uuid, string $completedBy): void {
		$this->tasks[$uuid]->setState(Task::STATE_COMPLETED);
		$this->tasks[$uuid]->setIsTerminal(true);
		$this->tasks[$uuid]->setOutcome('approved');
		$this->tasks[$uuid]->setCompletedBy($completedBy);
	}//end complete()

	/**
	 * The live stream whose token stands on a place.
	 *
	 * @param string $place The place.
	 *
	 * @return string The stream id.
	 */
	private function streamOn(string $place): string {
		foreach ($this->streams as $stream) {
			if ((string)$stream->getPlace() === $place && $stream->isTerminal() === false) {
				return (string)$stream->getStreamId();
			}
		}

		$this->fail(sprintf('No live stream stands on place "%s".', $place));
	}//end streamOn()

	/**
	 * 🔴 THE WEDGE'S ROOT CAUSE, proven red before the fix: an in-request
	 * advance of one branch ends the pass `queued` while the sibling branch
	 * still has enabled work, and that pass end used to DROP the sibling's
	 * resume slot — the uuid of the task it was waiting on. From there the
	 * sibling asked again on its next wake, the original task's completion
	 * signal was refused against the new slot's assignee, and the run rolled
	 * its heartbeat forever.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-live-run-keeps-every-parked-nodes-resume-slot
	 */
	public function testAnInRequestAdvanceKeepsTheSiblingNodesParkedSlot(): void {
		$run = $this->suspendedOnBothTasks();
		$slots = ($run->getContext()['resumeState'] ?? []);
		$taskB = (string)($slots['askB']['taskUuid'] ?? '');
		$this->assertNotSame('', $taskB);

		// Task A completes; its completion signals the run and spends the
		// node's advance budget in-request, as FlowTaskBridge::continueRun()
		// does. Branch B still has enabled work, so this pass ends `queued`.
		$this->complete(uuid: (string)$slots['askA']['taskUuid'], completedBy: 'bob');
		$woken = $this->service->signal($run, []);
		$this->assertNotNull($woken);
		$run = $this->service->advanceStream($woken, $this->flow(), new HeartbeatSubject(), $this->streamOn('askA'), 'all');

		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
		$kept = ($run->getContext()['resumeState'] ?? []);
		$this->assertSame(
			$taskB,
			(string)($kept['askB']['taskUuid'] ?? ''),
			'a queued pass end must keep the sibling\'s parked slot, or the heartbeat loses the task it is waiting on'
		);

		// A signal-delivered completion is not a heartbeat recovery.
		$this->assertSame([], $this->recovered);

		// The worker's next pass re-parks branch B on the SAME task — never a
		// duplicate in somebody's inbox.
		$run = $this->service->execute($run, $this->flow(), new HeartbeatSubject());
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		$this->assertSame(['askA', 'askB'], $this->created, 'a wake must never create a second task for a parked node');
		$this->assertSame($taskB, (string)($run->getContext()['resumeState']['askB']['taskUuid'] ?? ''));
	}//end testAnInRequestAdvanceKeepsTheSiblingNodesParkedSlot()

	/**
	 * The heartbeat's whole reason to exist: a completion whose signal was
	 * refused or lost is recovered on the next wake — the node re-reads its
	 * task, applies the outcome exactly as the signal path would have (same
	 * bag under `json.<outcomeKey>`, same advance), and the recovery is
	 * recorded on the task's audit attributed to its completer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-wake-re-reads-the-awaited-task-and-applies-a-terminal-outcome
	 */
	public function testTheHeartbeatRecoversACompletionWhoseSignalWasRefused(): void {
		$run = $this->suspendedOnBothTasks();
		$slots = ($run->getContext()['resumeState'] ?? []);
		$taskA = (string)$slots['askA']['taskUuid'];
		$taskB = (string)$slots['askB']['taskUuid'];

		// Both tasks complete, but NO signal ever reaches the run — the
		// observed case: the assignee guard refused the delivery.
		$this->complete(uuid: $taskA, completedBy: 'bob');
		$this->complete(uuid: $taskB, completedBy: 'carol');

		// The heartbeat fires: findDue() → advance() → execute().
		$run = $this->service->execute($run, $this->flow(), new HeartbeatSubject());

		$this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());
		$bagA = ($run->getItems()[0]['json']['task'] ?? null);
		$bagB = ($run->getItems()[0]['json']['taskB'] ?? null);
		$this->assertSame('approved', ($bagA['outcome'] ?? ($bagB['outcome'] ?? null)));
		$this->assertSame(['askA', 'askB'], $this->created, 'recovery must never create a task');
		$this->assertEqualsCanonicalizing([$taskA, $taskB], $this->recovered, 'each recovered delivery is audited, attributed to its completer');
	}//end testTheHeartbeatRecoversACompletionWhoseSignalWasRefused()

	/**
	 * A heartbeat that finds the task still open is a re-suspend, not an
	 * answer: same task, same slot, no audit entry, still suspended.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-wake-re-reads-the-awaited-task-and-applies-a-terminal-outcome
	 */
	public function testAHeartbeatWakeWithTheTasksStillOpenParksAgainOnTheSameTasks(): void {
		$run = $this->suspendedOnBothTasks();
		$before = ($run->getContext()['resumeState'] ?? []);

		$run = $this->service->execute($run, $this->flow(), new HeartbeatSubject());

		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		$this->assertSame(['askA', 'askB'], $this->created);
		$this->assertSame([], $this->recovered);
		$after = ($run->getContext()['resumeState'] ?? []);
		$this->assertSame($before['askA']['taskUuid'], $after['askA']['taskUuid']);
		$this->assertSame($before['askB']['taskUuid'], $after['askB']['taskUuid']);
		$this->assertSame($before['askA']['askedAt'], $after['askA']['askedAt'], 'a heartbeat must not restamp askedAt');
	}//end testAHeartbeatWakeWithTheTasksStillOpenParksAgainOnTheSameTasks()

	/**
	 * Per-node slot addressing holds through a recovery: only the node whose
	 * task ended advances; its sibling re-parks on its own task, slot intact.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-wake-re-reads-the-awaited-task-and-applies-a-terminal-outcome
	 */
	public function testOnlyTheNodeWhoseTaskEndedRecovers(): void {
		$run = $this->suspendedOnBothTasks();
		$slots = ($run->getContext()['resumeState'] ?? []);
		$taskA = (string)$slots['askA']['taskUuid'];
		$taskB = (string)$slots['askB']['taskUuid'];

		// Only task A completed, and its signal never arrived.
		$this->complete(uuid: $taskA, completedBy: 'bob');

		$run = $this->service->execute($run, $this->flow(), new HeartbeatSubject());

		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), 'branch B still waits');
		$this->assertSame([$taskA], $this->recovered, 'only the addressed node\'s slot recovers');
		$this->assertSame(['askA', 'askB'], $this->created);
		$kept = ($run->getContext()['resumeState'] ?? []);
		$this->assertArrayNotHasKey('askA', $kept, 'a node that answered has nothing left to remember');
		$this->assertSame($taskB, (string)($kept['askB']['taskUuid'] ?? ''), 'the waiting sibling keeps its own task');
	}//end testOnlyTheNodeWhoseTaskEndedRecovers()
}//end class
