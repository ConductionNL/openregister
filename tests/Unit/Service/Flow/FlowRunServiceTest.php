<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunMarkingStore;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\Oversight\KillSwitchCheck;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Marking;

/** Subject carrying nothing — the marking lives on the run, not here. */
class RunSubject {
	public array $bag = [];
}

/** A node that suspends the first time and proceeds once resuming. */
class WaitingNode implements IFlowNode {
	public int $calls = 0;

	public function __construct(
		private readonly string $id = 'test.wait',
	) {
	}

	public function getId(): string {
		return $this->id;
	}

	public function getDisplayName(): string {
		return 'Wait';
	}

	public function getDescription(): string {
		return 'Waits.';
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
		$this->calls++;
		if (($context['resuming'] ?? false) !== true) {
			throw new FlowSuspension(new DateTime('@1900000000'), 'waiting for the clock');
		}

		return $items;
	}
}

/** A subject whose serialisation carries its identity, like ObjectEntity does. */
class IdentifiedSubject {
	public function __construct(
		private readonly array $fields,
	) {
	}

	public function jsonSerialize(): array {
		return $this->fields;
	}
}

/** Passes items through and counts how often it ran, so a branch can be asserted. */
class BranchRecordingNode implements IFlowNode {
	public int $calls = 0;

	public function __construct(
		private readonly string $id,
	) {
	}

	public function getId(): string {
		return $this->id;
	}

	public function getDisplayName(): string {
		return 'Records';
	}

	public function getDescription(): string {
		return 'Counts its runs.';
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
		$this->calls++;

		return $items;
	}
}

/**
 * An await-signal-shaped node: consumes a visible signal as its answer,
 * otherwise records the ask in its OWN resume slot and suspends — the shape
 * AwaitSignalNode and dossiq's askPerson/requestDecision nodes share.
 */
class AskingNode implements IFlowNode {
	/**
	 * The signal each node id saw on each entry, in order.
	 */
	public array $sawSignal = [];

	public function getId(): string {
		return 'test.ask';
	}

	public function getDisplayName(): string {
		return 'Ask';
	}

	public function getDescription(): string {
		return 'Waits for an answer.';
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
		$slot = $context[FlowNodeResumeState::CONTEXT_KEY];
		$signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
		$this->sawSignal[$slot->nodeId()][] = $signal;

		if (is_array($signal) === true && trim((string)($signal['decision'] ?? '')) !== '') {
			foreach ($items as $index => $item) {
				$item['json'][$slot->nodeId()] = $signal;
				$items[$index] = $item;
			}

			return $items;
		}

		if ($slot->has(key: 'askedAt') === false) {
			$slot->set(key: 'askedAt', value: '2026-09-01T00:00:00+00:00');
		}

		throw new FlowSuspension(resumeAt: null, reason: 'waiting for an answer');
	}
}

/** Records the context it was handed, so attribution can be asserted. */
class ContextCapturingNode implements IFlowNode {
	public array $seenContext = [];

	public function getId(): string {
		return 'test.capture';
	}

	public function getDisplayName(): string {
		return 'Capture';
	}

	public function getDescription(): string {
		return 'Captures its context.';
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
		$this->seenContext = $context;

		return $items;
	}
}

class FlowRunServiceTest extends TestCase {
	use \OCA\OpenRegister\Tests\Unit\Service\Flow\PublishedVersionDouble;

	private FlowRunMapper $mapper;
	private FlowRunService $service;
	private WaitingNode $waiter;

	private ContextCapturingNode $capturer;

	private BranchRecordingNode $doneBranch;

	private BranchRecordingNode $retryBranch;

	private AskingNode $asker;

	protected function setUp(): void {
		$this->mapper = $this->createMock(FlowRunMapper::class);
		// insert/update echo the entity back, so assertions read the real state.
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->waiter = new WaitingNode();
		$this->capturer = new ContextCapturingNode();
		$this->doneBranch = new BranchRecordingNode(id: 'test.done');
		$this->retryBranch = new BranchRecordingNode(id: 'test.retry');
		$this->asker = new AskingNode();

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				if ($event instanceof RegisterFlowNodesEvent) {
					$event->registerNode($this->waiter);
					$event->registerNode($this->capturer);
					$event->registerNode($this->doneBranch);
					$event->registerNode($this->retryBranch);
					$event->registerNode($this->asker);
				}
			}
		);

		$registry = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));

		// No OrganisationService in the container — the cron/unit case, where a
		// queued run is recorded with no organisation rather than a guessed one.
		$container = $this->createMock(ContainerInterface::class);
		// Since versioning, queue() refuses a flow with no published version.
		// These fixtures are about what a run DOES, so they say version 1 is
		// live; the refusal itself has its own tests.
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

		$this->service = new FlowRunService(
			$this->mapper,
			$this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
			$engine,
			$registry,
			$this->createMock(LoggerInterface::class),
			$container
		);
	}

	private function waitFlow(): array {
		return [
			'id' => 'f1',
			// The step is the NODE (or-flow-action-nodes).
			'nodes' => [['id' => 'hop', 'type' => 'test.wait']],
			'edges' => [],
		];
	}

	public function testAQueuedRunIsNotExecuted(): void {
		// An object event carries the user whose action raised it — the real
		// listener reads it off the session and passes it here — so the fixture
		// names one rather than dispatching anonymously.
		$run = $this->service->queue('f1', ['uuid' => 'u1'], 'object.created', user: 'alice');

		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
		$this->assertSame(0, $this->waiter->calls);
		$this->assertNotEmpty($run->getUuid());
	}

	/**
	 * The property the whole issue exists for: a step can pause the run.
	 */
	public function testAStepThatSuspendsLeavesTheRunResumable(): void {
		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		$this->assertInstanceOf(DateTime::class, $run->getResumeAt());
		$this->assertFalse($run->isTerminal());
	}

	/**
	 * The marking must NOT have advanced past the suspended step, or resuming
	 * would skip the very step that asked to wait.
	 */
	public function testASuspendedRunKeepsItsPlaceInTheGraph(): void {
		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

		// The marking names the NODE the run is paused on. A suspending step
		// does not advance the token, so it waits on its own place — which is
		// the node's id, since a place is named after its node.
		$this->assertSame(['hop' => 1], $run->getMarking());
	}

	public function testResumingCarriesTheStoredItemsRatherThanReseeding(): void {
		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

		// Something the subject could never produce — proves the items came
		// from storage and not from re-seeding.
		$run->setItems([FlowItems::item(json: ['carried' => 'through-the-pause'])]);

		$run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

		$this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());
		$this->assertSame('through-the-pause', $run->getItems()[0]['json']['carried']);
		$this->assertSame(2, $this->waiter->calls);
	}

	/**
	 * 🔴 D2 REGRESSION: a suspended run whose SUBJECT changed while it waited
	 * resumes and branches on the NEW value, not the trigger-time snapshot.
	 *
	 * The dossiq stranding: the applicant supplied the missing description and
	 * completed the task, and the re-check still read `description: null` off
	 * the frozen item — so the re-ask loop could never succeed. Items still
	 * survive the pause (REQ-FR-003): only the subject's own fields on the
	 * item that IS the subject are refreshed; a key the earlier steps produced
	 * is asserted intact below.
	 *
	 * @return void
	 */
	public function testAResumedRunBranchesOnTheLiveSubjectNotTheTriggerTimeSnapshot(): void {
		$flow = [
			'id' => 'f1',
			'nodes' => [
				[
					'id' => 'hop',
					'type' => 'test.wait',
					'exits' => [
						['id' => 'filled', 'condition' => ['!=' => [['var' => 'json.description'], null]]],
						['id' => 'missing'],
					],
				],
				['id' => 'done', 'type' => 'test.done'],
				['id' => 'again', 'type' => 'test.retry'],
			],
			'edges' => [
				['id' => 'toDone', 'from' => 'hop', 'fromExit' => 'filled', 'to' => 'done'],
				['id' => 'toAgain', 'from' => 'hop', 'fromExit' => 'missing', 'to' => 'again'],
			],
		];

		$run = $this->service->queue('f1', ['uuid' => 'case-1'], 'object.created', user: 'alice');
		$run = $this->service->execute(
			$run,
			$flow,
			new IdentifiedSubject(fields: ['id' => 'case-1', 'description' => null])
		);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());

		// What the earlier steps produced must survive the refresh.
		$items = $run->getItems();
		$items[0]['json']['stepProduced'] = 'kept';
		$run->setItems($items);

		// The human answers: the SUBJECT changes while the run sits suspended.
		$run = $this->service->execute(
			$run,
			$flow,
			new IdentifiedSubject(fields: ['id' => 'case-1', 'description' => 'now supplied'])
		);

		$this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());
		$this->assertSame(1, $this->doneBranch->calls, 'the branch decision must read the LIVE subject');
		$this->assertSame(0, $this->retryBranch->calls, 'the stale snapshot must not re-take the re-ask branch');
		$this->assertSame('now supplied', $run->getItems()[0]['json']['description']);
		$this->assertSame('kept', $run->getItems()[0]['json']['stepProduced'], 'step output still survives the pause');
	}//end testAResumedRunBranchesOnTheLiveSubjectNotTheTriggerTimeSnapshot()

	/**
	 * 🔴 D2 REGRESSION, the loop shape: a re-ask loop that RECEIVES the answer
	 * terminates instead of stranding.
	 *
	 * check -> (missing) -> ask -> check again. On the stale snapshot the
	 * resumed walk takes `missing` forever — each re-entry of the ask node
	 * completes instantly against the already-consumed answer, so the loop
	 * spins to the ceiling in one pass and the run strands. With the subject
	 * refreshed at resume, the first re-check reads the supplied value and the
	 * run completes.
	 *
	 * @return void
	 */
	public function testAReAskLoopThatReceivesTheAnswerTerminates(): void {
		$flow = [
			'id' => 'f1',
			'nodes' => [
				[
					'id' => 'check',
					'type' => 'test.retry',
					'exits' => [
						['id' => 'filled', 'condition' => ['!=' => [['var' => 'json.description'], null]]],
						['id' => 'missing'],
					],
				],
				['id' => 'ask', 'type' => 'test.wait'],
				['id' => 'done', 'type' => 'test.done'],
			],
			'edges' => [
				['id' => 'toDone', 'from' => 'check', 'fromExit' => 'filled', 'to' => 'done'],
				['id' => 'toAsk', 'from' => 'check', 'fromExit' => 'missing', 'to' => 'ask'],
				['id' => 'back', 'from' => 'ask', 'to' => 'check'],
			],
		];

		$run = $this->service->queue('f1', ['uuid' => 'case-1'], 'object.created', user: 'alice');
		$run = $this->service->execute(
			$run,
			$flow,
			new IdentifiedSubject(fields: ['id' => 'case-1', 'description' => null])
		);

		// The first pass checked, found nothing, asked, and parked.
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		$this->assertSame(1, $this->retryBranch->calls);

		$run = $this->service->execute(
			$run,
			$flow,
			new IdentifiedSubject(fields: ['id' => 'case-1', 'description' => 'now supplied'])
		);

		$this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus(), 'the loop must terminate, not strand at the ceiling');
		$this->assertSame(2, $this->retryBranch->calls, 'exactly one re-check, which sees the answer');
		$this->assertSame(1, $this->doneBranch->calls);
	}//end testAReAskLoopThatReceivesTheAnswerTerminates()

	/**
	 * THE RESUME ANSWER IS SCOPED TO THE NODE THAT SUSPENDED — through the
	 * worker path. `signal()` then `execute()` is exactly what the resume
	 * endpoint plus the next FlowRunWorker pass do, so this is the defect the
	 * 2026-09-01 acceptance run caught (runs f8996ccc / ca50c56c) driven
	 * through the same seam: answering the FIRST wait must leave the SECOND
	 * suspended on a fresh ask of its own, not complete it in zero
	 * milliseconds on somebody else's answer.
	 *
	 * @return void
	 */
	public function testAnsweringOneWaitDoesNotAnswerTheNext(): void {
		$flow = [
			'id' => 'f1',
			'nodes' => [
				['id' => 'first', 'type' => 'test.ask'],
				['id' => 'second', 'type' => 'test.ask'],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'first', 'to' => 'second'],
			],
		];

		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $flow, new RunSubject());
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		$this->assertArrayHasKey(
			'first',
			($run->getContext()[FlowResumeState::CONTEXT_KEY] ?? []),
			'the first ask holds the run'
		);

		// The answer to the FIRST question arrives; the worker picks the run up.
		$answer = ['decision' => 'approved', 'node' => 'first', 'taskId' => 'task-1'];
		$run = $this->service->signal($run, $answer);
		$this->assertNotNull($run, 'a suspended run accepts its signal');
		$run = $this->service->execute($run, $flow, new RunSubject());

		$this->assertSame(
			FlowRun::STATUS_SUSPENDED,
			$run->getStatus(),
			'one answer advances the run to the NEXT question, never to the end'
		);

		$slots = ($run->getContext()[FlowResumeState::CONTEXT_KEY] ?? []);
		$this->assertArrayHasKey('second', $slots, 'the second ask recorded a question of its own');
		$this->assertArrayNotHasKey('first', $slots, 'the answered ask keeps no slot');
		$this->assertArrayNotHasKey(
			FlowRunService::SIGNAL_CONTEXT_KEY,
			($run->getContext() ?? []),
			'the consumed signal does not linger in the stored context'
		);

		$this->assertSame(
			[null, $answer],
			$this->asker->sawSignal['first'] ?? [],
			'the answered node read the payload on its resume, and only then'
		);
		$this->assertSame(
			[null],
			$this->asker->sawSignal['second'] ?? [],
			'the second node never saw the first answer'
		);

		// The second answer is the one that finishes the run, and each item
		// carries each answer under its OWN node.
		$secondAnswer = ['decision' => 'approved', 'node' => 'second'];
		$run = $this->service->signal($run, $secondAnswer);
		$run = $this->service->execute($run, $flow, new RunSubject());

		$this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());
		$item = ($run->getItems()[0]['json'] ?? []);
		$this->assertSame($answer, ($item['first'] ?? null));
		$this->assertSame($secondAnswer, ($item['second'] ?? null));
	}//end testAnsweringOneWaitDoesNotAnswerTheNext()

	/**
	 * On the first walk — no suspension yet, nothing resuming — an ask node
	 * asks rather than answering itself: the negative control for the
	 * scoping, proving the signal gate does not leak on fresh runs either.
	 *
	 * @return void
	 */
	public function testAFreshRunsFirstAskSeesNoSignal(): void {
		$flow = [
			'id' => 'f1',
			'nodes' => [['id' => 'only', 'type' => 'test.ask']],
			'edges' => [],
		];

		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $flow, new RunSubject());

		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		$this->assertSame([null], $this->asker->sawSignal['only'] ?? []);
	}//end testAFreshRunsFirstAskSeesNoSignal()

	/**
	 * THE OPERATOR'S STOP LANDS ON THE NEXT OBSERVATION. The kill switch is
	 * thrown while a run is suspended; the operator nudges it (`resume` with
	 * an empty body is `signal([])`) and the next worker pass must end the run
	 * `stopped` — the oversight veto travels an author's Stop-step path — not
	 * leave it suspended forever. The run's terminal write is what triggers
	 * task termination (FlowRunTerminalEvent → terminateForRun), so a stop
	 * that never lands is also an inbox that never empties.
	 *
	 * @return void
	 */
	public function testAKillSwitchVetoLandsTheStopOnTheNextObservation(): void {
		$thrown = false;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static function (string $app, string $key, bool $default = false) use (&$thrown): bool {
				return $thrown;
			}
		);

		$oversight = new FlowOversightRegistry(logger: $this->createMock(LoggerInterface::class));
		$oversight->register(check: new KillSwitchCheck(appConfig: $appConfig));

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				if ($event instanceof RegisterFlowNodesEvent) {
					$event->registerNode($this->waiter);
				}
			}
		);
		$registry = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$engine = new FlowEngine(
			new FlowDefinitionBuilder(),
			$this->createMock(LoggerInterface::class),
			$oversight
		);

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

		$service = new FlowRunService(
			$this->mapper,
			$this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
			$engine,
			$registry,
			$this->createMock(LoggerInterface::class),
			$container
		);

		$run = $service->queue('f1', user: 'alice');
		$run = $service->execute($run, $this->waitFlow(), new RunSubject());
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());

		// The operator throws the switch and nudges the parked run.
		$thrown = true;
		$run = $service->signal($run, []);
		$this->assertNotNull($run);
		$run = $service->execute($run, $this->waitFlow(), new RunSubject());

		$this->assertSame(
			FlowRun::STATUS_STOPPED,
			$run->getStatus(),
			'the veto must END the run; a stop that leaves it suspended never lands'
		);

		$last = $run->getLog()[count($run->getLog()) - 1];
		$this->assertSame('stopped', $last['status']);
		$this->assertSame(
			'openregister.kill-switch',
			($last['checkId'] ?? null),
			'the history records WHICH gate closed'
		);
		$this->assertNull($run->getResumeAt(), 'a stopped run is never due again');
	}//end testAKillSwitchVetoLandsTheStopOnTheNextObservation()

	/**
	 * The refresh reaches the PER-PLACE buffers too: with a stream layer the
	 * resumed walk reads its input place's buffer, not the flat list, so a
	 * refresh that missed placeItems would leave the stale snapshot exactly
	 * where the branch decision is made.
	 *
	 * @return void
	 */
	public function testTheResumeRefreshAlsoRewritesThePerPlaceBuffers(): void {
		$run = $this->service->queue('f1', ['uuid' => 'case-1'], 'object.created', user: 'alice');
		$run = $this->service->execute(
			$run,
			$this->waitFlow(),
			new IdentifiedSubject(fields: ['id' => 'case-1', 'description' => null])
		);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());

		$run->setPlaceItems(['hop' => [FlowItems::item(json: ['id' => 'case-1', 'description' => null, 'stepProduced' => 'kept'])]]);

		$run = $this->service->execute(
			$run,
			$this->waitFlow(),
			new IdentifiedSubject(fields: ['id' => 'case-1', 'description' => 'now supplied'])
		);

		$buffer = $run->getPlaceItems()['hop'];
		$this->assertSame('now supplied', $buffer[0]['json']['description'], 'the per-place buffer reads the live subject');
		$this->assertSame('kept', $buffer[0]['json']['stepProduced'], 'step output survives in the buffer too');
	}//end testTheResumeRefreshAlsoRewritesThePerPlaceBuffers()

	public function testResumeAtIsClearedOnceTheRunIsNoLongerSuspended(): void {
		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $this->waitFlow(), new RunSubject());
		$this->assertNotNull($run->getResumeAt());

		$run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

		// Otherwise the due-runs query keeps picking up a finished run.
		$this->assertNull($run->getResumeAt());
	}

	public function testTheLogAccumulatesAcrossASuspension(): void {
		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $this->waitFlow(), new RunSubject());
		$afterSuspend = count($run->getLog());

		$run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

		$this->assertGreaterThan($afterSuspend, count($run->getLog()));
		$this->assertSame('suspended', $run->getLog()[0]['status']);
	}

	/**
	 * Re-executing a finished run would repeat every side effect it performed.
	 */
	public function testATerminalRunIsNeverReExecuted(): void {
		$run = $this->service->queue('f1', user: 'alice');
		$run->setStatus(FlowRun::STATUS_COMPLETED);

		$this->service->execute($run, $this->waitFlow(), new RunSubject());

		$this->assertSame(0, $this->waiter->calls);
	}

	public function testAMalformedFlowFailsTheRunRatherThanLeavingItRunning(): void {
		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, ['nodes' => []], new RunSubject());

		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertNotNull($run->getError());
	}

	/**
	 * A flow definition whose single hop runs the context-capturing node.
	 *
	 * @return array<string,mixed>
	 */
	private function captureFlow(): array {
		return [
			'id' => 'f1',
			'nodes' => [['id' => 'hop', 'type' => 'test.capture']],
			'edges' => [],
		];
	}

	/**
	 * FAILING PATH (or#2158): nodes read `context['triggeredBy']` to attribute
	 * what they do — ObjectWriteNode REFUSES to write without it, SubFlowNode
	 * propagates it to child runs, and Hermiq's agent node runs the turn as
	 * that user. Before this fix `execute()` set only `runUuid` and `resuming`,
	 * so the key was never populated from the run and EVERY trigger reached its
	 * nodes ownerless; only hand-injected contexts (tests, harnesses) worked.
	 *
	 * @return void
	 */
	public function testTheRunsOwnerReachesTheNodeContext(): void {
		$run = $this->service->queue('f1', ['uuid' => 'u1'], 'object.created', [], 'alice');

		$this->service->execute($run, $this->captureFlow(), new RunSubject());

		$this->assertSame('alice', ($this->capturer->seenContext['triggeredBy'] ?? null));
	}

	/**
	 * An explicit context value wins, so a caller can attribute a run to
	 * somebody other than whoever queued it.
	 *
	 * @return void
	 */
	public function testAnExplicitContextOwnerIsNotOverwrittenByTheRunsOwner(): void {
		$run = $this->service->queue('f1', ['uuid' => 'u1'], 'object.created', ['triggeredBy' => 'bob'], 'alice');

		$this->service->execute($run, $this->captureFlow(), new RunSubject());

		$this->assertSame('bob', ($this->capturer->seenContext['triggeredBy'] ?? null));
	}

	/**
	 * A context-supplied `runAs` is IGNORED — the run's own value wins.
	 *
	 * The asymmetry with `triggeredBy` above is deliberate and is the security
	 * property. Provenance is a claim about the past and a caller may legitimately
	 * record "I did this on Bob's behalf". Authorization is a claim about what may
	 * happen next, and context is caller-supplied at queue time — so honouring it
	 * would let anyone who can start a flow choose the identity its steps execute
	 * as. That is the widening ADR-099 forbids: identity narrows along an
	 * invocation chain, and widening needs a grant checked against the caller,
	 * never a key in a payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function testAContextSuppliedActingIdentityIsIgnored(): void {
		$run = $this->service->queue(
			'f1',
			['uuid' => 'u1'],
			'object.created',
			['runAs' => 'mallory'],
			'alice'
		);

		$this->service->execute($run, $this->captureFlow(), new RunSubject());

		$this->assertSame(
			'alice',
			($this->capturer->seenContext['runAs'] ?? null),
			'the run decides who it acts as; a caller-supplied context must not'
		);
		$this->assertNotSame('mallory', ($this->capturer->seenContext['runAs'] ?? null));
	}
}

class FlowRunMarkingStoreTest extends TestCase {
	public function testTheMarkingRoundTripsThroughTheRun(): void {
		$run = new FlowRun();
		$store = new FlowRunMarkingStore($run);

		$store->setMarking(new RunSubject(), new Marking(['a' => 1, 'b' => 1]));

		$this->assertSame(['a' => 1, 'b' => 1], $run->getMarking());
		$this->assertSame(['a' => 1, 'b' => 1], $store->getMarking(new RunSubject())->getPlaces());
	}

	public function testAnEmptyRunStartsWithAnEmptyMarking(): void {
		$store = new FlowRunMarkingStore(new FlowRun());

		$this->assertSame([], $store->getMarking(new RunSubject())->getPlaces());
	}

	/**
	 * A hand-authored fixture tends to be a list of place names rather than a
	 * place => tokens map; accept it rather than silently marking nothing.
	 */
	public function testAListOfPlaceNamesIsAccepted(): void {
		$run = new FlowRun();
		$run->setMarking(['a', 'b']);

		$this->assertSame(['a' => 1, 'b' => 1], (new FlowRunMarkingStore($run))->getMarking(new RunSubject())->getPlaces());
	}
}
