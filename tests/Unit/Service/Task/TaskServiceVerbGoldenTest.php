<?php

/**
 * Golden outcomes of TaskService's lifecycle verbs: what each verb allows,
 * what it refuses with which message, and the audit entry it writes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskAct;
use OCA\OpenRegister\Service\Task\TaskAnswer;
use OCA\OpenRegister\Service\Task\TaskAnswerPayload;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskCommit;
use OCA\OpenRegister\Service\Task\TaskIntake;
use OCA\OpenRegister\Service\Task\TaskLifecycleServices;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTables;
use OCA\OpenRegister\Service\Task\TaskTrigger;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskDelegation;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskResolution;
use OCA\OpenRegister\Kernel\Contracts\Value\ChecklistTick;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskAuditEntry;

/**
 * Pins allowed and refused outcomes of every verb, with the audit row.
 */
class TaskServiceVerbGoldenTest extends TestCase {

	private TaskMapper&MockObject $tasks;

	private TaskCandidateMapper&MockObject $candidates;

	private TaskAuditMapper&MockObject $audits;

	private TaskAuthorizationService&MockObject $authorization;

	private TaskPerformerResolver&MockObject $resolver;

	private IDBConnection&MockObject $db;

	/**
	 * Audit rows in insertion order.
	 *
	 * @var list<TaskAudit>
	 */
	private array $written = [];

	/**
	 * Whether updateIfOpen admits the write.
	 *
	 * @var list<bool|RuntimeException>
	 */
	private array $openWrites = [];

	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->candidates = $this->createMock(TaskCandidateMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->authorization = $this->createMock(TaskAuthorizationService::class);
		$this->resolver = $this->createMock(TaskPerformerResolver::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->written = [];
		$this->openWrites = [];

		$this->tasks->method('update')->willReturnArgument(0);
		$this->tasks->method('updateIfOpen')->willReturnCallback(
			function (): bool {
				if ($this->openWrites === []) {
					return true;
				}

				$next = array_shift($this->openWrites);
				if ($next instanceof RuntimeException) {
					throw $next;
				}

				return $next;
			}
		);
		$this->audits->method('insert')->willReturnCallback(
			function (TaskAudit $entry): TaskAudit {
				$this->written[] = $entry;

				return $entry;
			}
		);
	}//end setUp()

	private function service(): TaskService {
		return new TaskService(
			authorization: $this->authorization,
			services: new TaskLifecycleServices(
				tables: new TaskTables(
					tasks: $this->tasks,
					candidates: $this->candidates,
					relations: $this->createMock(TaskRelationMapper::class),
					audits: $this->audits
				),
				commit: new TaskCommit(
					db: $this->db,
					logger: new NullLogger()
				),
				intake: new TaskIntake(
					builder: new TaskBuilder()
				),
				resolver: $this->resolver
			)
		);
	}//end service()

	private function task(string $state = Task::STATE_ACTIVE, ?string $assignee = 'alice'): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->setState($state);
		$task->setIsTerminal(in_array($state, [Task::STATE_COMPLETED, Task::STATE_TERMINATED], true));
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee($assignee);
		$task->setRequester('rita');

		return $task;
	}//end task()

	private function stored(Task $task): void {
		$this->tasks->method('findByUuid')->willReturn($task);
	}//end stored()

	/**
	 * The one audit row, as the fields a reader of the trail sees.
	 *
	 * @return array<string, mixed>
	 */
	private function onlyAudit(): array {
		$this->assertCount(1, $this->written);
		$entry = $this->written[0];

		return [
			'taskId' => $entry->getTaskId(),
			'action' => $entry->getAction(),
			'stateAfter' => $entry->getStateAfter(),
			'actor' => $entry->getActor(),
			'performerType' => $entry->getPerformerType(),
			'onBehalfOf' => $entry->getOnBehalfOf(),
			'mandate' => $entry->getMandate(),
			'reason' => $entry->getReason(),
			'authorized' => $entry->getAuthorized(),
		];
	}//end onlyAudit()

	private function expectRefusal(callable $call, string $class, string $message): void {
		try {
			$call();
			$this->fail('Expected ' . $class);
		} catch (TaskValidationException | TaskConflictException | TaskAccessDeniedException $refused) {
			$this->assertSame($class, $refused::class);
			$this->assertSame($message, $refused->getMessage());
		}
	}//end expectRefusal()

	public function testCompleteWritesOutcomeFieldsStateAndAudit(): void {
		$task = $this->task();
		$this->stored($task);

		$done = $this->service()->complete(
			uuid: 't-7',
			outcome: 'approved',
			resultText: 'ok',
			comment: 'fine',
			actor: 'alice',
			responses: ['a' => 1],
			evidence: ['e' => 2]
		);

		$this->assertSame('approved', $done->getOutcome());
		$this->assertSame('ok', $done->getResultText());
		$this->assertSame('fine', $done->getComment());
		$this->assertSame(['a' => 1], $done->getResponses());
		$this->assertSame(['e' => 2], $done->getEvidence());
		$this->assertSame('alice', $done->getCompletedBy());
		$this->assertNotNull($done->getCompletedAt());
		$this->assertSame(Task::STATE_COMPLETED, $done->getState());
		$this->assertTrue($done->getIsTerminal());
		$this->assertSame('complete', $done->getLastAction());
		$this->assertSame(
			[
				'taskId' => 7,
				'action' => 'complete',
				'stateAfter' => Task::STATE_COMPLETED,
				'actor' => 'alice',
				'performerType' => Task::PERFORMER_USER,
				'onBehalfOf' => null,
				'mandate' => null,
				'reason' => 'fine',
				'authorized' => true,
			],
			$this->onlyAudit()
		);
	}//end testCompleteWritesOutcomeFieldsStateAndAudit()

	public function testCompleteWithoutResponsesLeavesThemUnset(): void {
		$task = $this->task();
		$task->setResponses(['kept' => true]);
		$task->setEvidence(['kept' => true]);
		$this->stored($task);

		$done = $this->service()->completeWithAnswer(uuid: 't-7', answer: new TaskAnswer(outcome: 'approved'), actor: 'alice');

		$this->assertSame(['kept' => true], $done->getResponses());
		$this->assertSame(['kept' => true], $done->getEvidence());
	}//end testCompleteWithoutResponsesLeavesThemUnset()

	public function testResolveCompletesUnderItsOwnVerb(): void {
		$this->stored($this->task());

		$done = $this->service()->resolve(
			ref: new TaskActorRef(uuid: 't-7', actor: 'alice'),
			resolution: new TaskResolution(resultText: 'r', comment: 'c'),
		);

		$this->assertSame('resolved', $done->getOutcome());
		$this->assertSame('resolve', $done->getLastAction());
		$this->assertSame('resolve', $this->onlyAudit()['action']);
	}//end testResolveCompletesUnderItsOwnVerb()

	public function testADenialIsAuditedAsUnauthorizedAndRethrownUnchanged(): void {
		$task = $this->task();
		$task->setOnBehalfOf('olga');
		$task->setMandate('m-1');
		$this->stored($task);
		$denial = new TaskAccessDeniedException(message: 'not yours');
		$this->authorization->method('assertMay')->willThrowException($denial);
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->service()->completeWithAnswer(uuid: 't-7', answer: new TaskAnswer(outcome: 'approved'), actor: 'mallory');
			$this->fail('Expected a denial');
		} catch (TaskAccessDeniedException $thrown) {
			$this->assertSame($denial, $thrown);
		}

		$this->assertSame(
			[
				'taskId' => 7,
				'action' => 'complete',
				'stateAfter' => Task::STATE_ACTIVE,
				'actor' => 'mallory',
				'performerType' => Task::PERFORMER_USER,
				'onBehalfOf' => 'olga',
				'mandate' => 'm-1',
				'reason' => 'not yours',
				'authorized' => false,
			],
			$this->onlyAudit()
		);
		$this->assertNull($task->getOutcome());
	}//end testADenialIsAuditedAsUnauthorizedAndRethrownUnchanged()

	public function testADenialOnAnUnsavedTaskIsNotAuditedButStillDenies(): void {
		$task = $this->task();
		$task->setId(null);
		$this->stored($task);
		$this->authorization->method('assertMay')->willThrowException(new TaskAccessDeniedException(message: 'no'));

		$this->expectRefusal(fn () => $this->service()->cancel(ref: new TaskActorRef(uuid: 't-7', actor: 'x'), reason: null), TaskAccessDeniedException::class, 'no');
		$this->assertSame([], $this->written);
	}//end testADenialOnAnUnsavedTaskIsNotAuditedButStillDenies()

	public function testADenialWhoseAuditFailsStillDenies(): void {
		$this->stored($this->task());
		$this->authorization->method('assertMay')->willThrowException(new TaskAccessDeniedException(message: 'no'));
		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willThrowException(new RuntimeException('db down'));
		$this->audits = $audits;

		$this->expectRefusal(fn () => $this->service()->claim(uuid: 't-7', actor: 'x'), TaskAccessDeniedException::class, 'no');
	}//end testADenialWhoseAuditFailsStillDenies()

	public function testAVerbOnATerminalTaskIsRefusedNamingVerbAndState(): void {
		$this->stored($this->task(state: Task::STATE_COMPLETED));
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectRefusal(
			fn () => $this->service()->cancel(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), reason: 'r'),
			TaskConflictException::class,
			"Verb 'cancel' refused: task 't-7' is already in terminal state 'completed'."
		);
	}//end testAVerbOnATerminalTaskIsRefusedNamingVerbAndState()

	public function testARejectionWithoutCommentIsRefusedBeforeAnyWrite(): void {
		$this->stored($this->task());
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectRefusal(
			fn () => $this->service()->completeWithAnswer(uuid: 't-7', answer: new TaskAnswer(outcome: 'Rejected', comment: '  '), actor: 'alice'),
			TaskValidationException::class,
			"Outcome 'Rejected' rejects or returns the work, so a non-empty comment is mandatory."
		);
	}//end testARejectionWithoutCommentIsRefusedBeforeAnyWrite()

	public function testADeadLetterRejectionReroutesStateOutcomeAndReason(): void {
		$task = $this->task();
		$task->setOnReject('dead_letter');
		$this->stored($task);

		$done = $this->service()->completeWithAnswer(uuid: 't-7', answer: new TaskAnswer(outcome: 'denied', comment: 'bad'), actor: 'alice');

		$this->assertSame(Task::STATE_DISABLED, $done->getState());
		$this->assertSame('dead_letter', $done->getOutcome());
		$this->assertSame('bad', $done->getComment());
		$this->assertSame(
			"Rejected as 'denied'; declared onReject 'dead_letter' routed it to the dead letter state. bad",
			$this->onlyAudit()['reason']
		);
	}//end testADeadLetterRejectionReroutesStateOutcomeAndReason()

	public function testARejectionWithoutDeadLetterCompletes(): void {
		$task = $this->task();
		$task->setOnReject('skip');
		$this->stored($task);

		$done = $this->service()->completeWithAnswer(uuid: 't-7', answer: new TaskAnswer(outcome: 'rejected', comment: 'bad'), actor: 'alice');

		$this->assertSame(Task::STATE_COMPLETED, $done->getState());
		$this->assertSame('rejected', $done->getOutcome());
		$this->assertSame('bad', $this->onlyAudit()['reason']);
	}//end testARejectionWithoutDeadLetterCompletes()

	public function testACompletionThatLosesTheOpenWriteIsAConflict(): void {
		$this->stored($this->task());
		$this->openWrites = [false];

		$this->expectRefusal(
			fn () => $this->service()->completeWithAnswer(uuid: 't-7', answer: new TaskAnswer(outcome: 'approved'), actor: 'alice'),
			TaskConflictException::class,
			"Task 't-7' was closed concurrently; this change was not applied."
		);
		$this->assertSame([], $this->written);
	}//end testACompletionThatLosesTheOpenWriteIsAConflict()

	public function testOfferOnAnAssignedTaskIsRefused(): void {
		$this->stored($this->task());

		$this->expectRefusal(
			fn () => $this->service()->offer(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), pool: []),
			TaskConflictException::class,
			"Verb 'offer' refused: task 't-7' is already assigned; unclaim or reassign it instead."
		);
	}//end testOfferOnAnAssignedTaskIsRefused()

	public function testOfferSetsOnlyTheSentKeysAndRoutes(): void {
		$task = $this->task(state: Task::STATE_ENABLED, assignee: null);
		$task->setCandidateRole('clerk');
		$this->stored($task);
		$this->resolver->method('resolveAssignee')->willReturnOnConsecutiveCalls('bob', null);
		$this->candidates->expects($this->exactly(2))->method('replaceForTask')->with(
			7,
			[
				['kind' => 'user', 'ref' => 'u1'],
				['kind' => 'role', 'ref' => 'clerk'],
			]
		);

		$routed = $this->service()->offer(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), pool: ['candidateUsers' => ['u1'], 'other' => 'x']);
		$this->assertSame('bob', $routed->getAssignee());
		$this->assertSame(Task::STATE_ACTIVE, $routed->getState());
		$this->assertSame('offer', $routed->getLastAction());
		$this->assertSame('clerk', $routed->getCandidateRole());
		$this->assertSame(['offer', null, 'rita'], [$this->written[0]->getAction(), $this->written[0]->getReason(), $this->written[0]->getActor()]);

		$routed->setAssignee(null);
		$pooled = $this->service()->offer(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), pool: []);
		$this->assertNull($pooled->getAssignee());
		$this->assertSame(Task::STATE_ENABLED, $pooled->getState());
	}//end testOfferSetsOnlyTheSentKeysAndRoutes()

	public function testEditWithoutChangeWritesNothing(): void {
		$task = $this->task();
		$task->setTitle('same');
		$this->stored($task);
		$this->db->expects($this->never())->method('beginTransaction');

		$this->assertSame($task, $this->service()->edit(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), changes: ['title' => 'same']));
		$this->assertSame([], $this->written);
	}//end testEditWithoutChangeWritesNothing()

	public function testEditAuditsTheChangedFields(): void {
		$task = $this->task();
		$task->setTitle('old');
		$this->stored($task);

		$edited = $this->service()->edit(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), changes: ['title' => 'new']);

		$this->assertSame('new', $edited->getTitle());
		$this->assertSame(['edit', 'Changed: title.'], [$this->onlyAudit()['action'], $this->onlyAudit()['reason']]);
	}//end testEditAuditsTheChangedFields()

	public function testUnclaimPoolsTheTask(): void {
		$task = $this->task();
		$task->setOnBehalfOf('olga');
		$task->setMandate('m');
		$this->stored($task);

		$pooled = $this->service()->unclaim(uuid: 't-7', actor: 'alice');

		$this->assertSame([null, null, null, Task::STATE_ENABLED], [$pooled->getAssignee(), $pooled->getOnBehalfOf(), $pooled->getMandate(), $pooled->getState()]);
		$this->assertSame(['unclaim', null, null], [$this->onlyAudit()['action'], $this->onlyAudit()['reason'], $this->onlyAudit()['onBehalfOf']]);
	}//end testUnclaimPoolsTheTask()

	public function testAssignAndReassignRefuseAnEmptyAssignee(): void {
		$this->stored($this->task());

		$this->expectRefusal(
			fn () => $this->service()->assign(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), assignee: ' '),
			TaskValidationException::class,
			'An assignment requires a non-empty assignee.'
		);
		$this->expectRefusal(
			fn () => $this->service()->reassign(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), assignee: ''),
			TaskValidationException::class,
			'An assignment requires a non-empty assignee.'
		);
		$this->assertSame([], $this->written);
	}//end testAssignAndReassignRefuseAnEmptyAssignee()

	public function testReassignActivatesAndClearsTheDelegation(): void {
		$task = $this->task(state: Task::STATE_ENABLED, assignee: null);
		$task->setOnBehalfOf('olga');
		$task->setMandate('m');
		$this->stored($task);

		$done = $this->service()->reassign(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), assignee: 'bob');

		$this->assertSame(['bob', null, null, Task::STATE_ACTIVE, 'reassign'], [$done->getAssignee(), $done->getOnBehalfOf(), $done->getMandate(), $done->getState(), $done->getLastAction()]);
		$this->assertSame(['reassign', null, 'rita'], [$this->onlyAudit()['action'], $this->onlyAudit()['reason'], $this->onlyAudit()['actor']]);
	}//end testReassignActivatesAndClearsTheDelegation()

	public function testDelegateRefusalsAndTheDelegation(): void {
		$this->stored($this->task());

		$this->expectRefusal(
			fn () => $this->service()->delegate(
				ref: new TaskActorRef(uuid: 't-7', actor: 'alice'),
				delegation: new TaskDelegation(delegate: '', mandate: 'm'),
			),
			TaskValidationException::class,
			'A delegation requires a non-empty delegate.'
		);
		$this->expectRefusal(
			fn () => $this->service()->delegate(
				ref: new TaskActorRef(uuid: 't-7', actor: 'alice'),
				delegation: new TaskDelegation(delegate: 'bob', mandate: ' '),
			),
			TaskValidationException::class,
			'A delegation requires a mandate naming the authority relied on.'
		);

		$done = $this->service()->delegate(
			ref: new TaskActorRef(uuid: 't-7', actor: 'alice'),
			delegation: new TaskDelegation(delegate: 'bob', mandate: 'art. 5'),
		);

		$this->assertSame(['bob', 'alice', 'art. 5', Task::STATE_ACTIVE], [$done->getAssignee(), $done->getOnBehalfOf(), $done->getMandate(), $done->getState()]);
		$this->assertSame(['delegate', 'art. 5', 'alice'], [$this->onlyAudit()['action'], $this->onlyAudit()['reason'], $this->onlyAudit()['onBehalfOf']]);
	}//end testDelegateRefusalsAndTheDelegation()

	public function testCancelTerminatesWithTheReason(): void {
		$this->stored($this->task());

		$done = $this->service()->cancel(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), reason: 'moot');

		$this->assertSame(['cancelled', Task::STATE_TERMINATED, true], [$done->getOutcome(), $done->getState(), $done->getIsTerminal()]);
		$this->assertSame(['cancel', 'moot', Task::STATE_TERMINATED], [$this->onlyAudit()['action'], $this->onlyAudit()['reason'], $this->onlyAudit()['stateAfter']]);
	}//end testCancelTerminatesWithTheReason()

	public function testChecklistChecksTheFirstMatchingItemOnly(): void {
		$task = $this->task();
		$task->setChecklist([['id' => 'b'], 'loose', ['id' => 'a', 'checked' => false], ['id' => 'a', 'checked' => false]]);
		$this->stored($task);

		$done = $this->service()->checkChecklistItem(
			ref: new TaskActorRef(uuid: 't-7', actor: 'alice'),
			tick: new ChecklistTick(itemId: 'a', checked: true),
		);

		$this->assertSame([['id' => 'b'], 'loose', ['id' => 'a', 'checked' => true], ['id' => 'a', 'checked' => false]], $done->getChecklist());
		$this->assertSame(['checklist', "Item 'a' set to 'true'."], [$this->onlyAudit()['action'], $this->onlyAudit()['reason']]);
	}//end testChecklistChecksTheFirstMatchingItemOnly()

	public function testAMissingChecklistItemIsRefusedInsideTheTransaction(): void {
		$this->stored($this->task());
		$this->db->expects($this->once())->method('rollBack');

		$this->expectRefusal(
			fn () => $this->service()->checkChecklistItem(
				ref: new TaskActorRef(uuid: 't-7', actor: 'alice'),
				tick: new ChecklistTick(itemId: 'zz', checked: false),
			),
			TaskValidationException::class,
			"Checklist item 'zz' does not exist on task 't-7'."
		);
	}//end testAMissingChecklistItemIsRefusedInsideTheTransaction()

	public function testTerminateForRunCountsOnlyWhatItTerminated(): void {
		$this->assertSame(0, $this->service()->terminateForRun(runUuid: ' ', runStatus: 'done'));

		$first = $this->task();
		$first->setBlockedReason('waiting');
		$second = $this->task();
		$second->setUuid('t-8');
		$third = $this->task();
		$third->setUuid('t-9');
		$this->tasks->method('findOpenByRunUuid')->willReturn([$first, $second, $third]);
		$this->openWrites = [true, false, true];
		$this->candidates->method('replaceForTask');

		$this->assertSame(2, $this->service()->terminateForRun(runUuid: 'r1', runStatus: 'failed'));
		$this->assertSame(['terminated', null, Task::STATE_TERMINATED], [$first->getOutcome(), $first->getBlockedReason(), $first->getState()]);
		$this->assertCount(2, $this->written);
		$this->assertSame(
			['terminate', 'flow-run:r1', "Run 'r1' reached terminal status 'failed'."],
			[$this->written[1]->getAction(), $this->written[1]->getActor(), $this->written[1]->getReason()]
		);
	}//end testTerminateForRunCountsOnlyWhatItTerminated()

	public function testTerminateForRunSurvivesAnUnexpectedFailure(): void {
		$first = $this->task();
		$second = $this->task();
		$second->setUuid('t-8');
		$this->tasks->method('findOpenByRunUuid')->willReturn([$first, $second]);
		$this->openWrites = [new RuntimeException('boom'), true];
		$this->db->expects($this->once())->method('rollBack');

		$this->assertSame(1, $this->service()->terminateForRun(runUuid: 'r1', runStatus: 'failed'));
	}//end testTerminateForRunSurvivesAnUnexpectedFailure()

	public function testTerminateAsMootSkipsATerminalTask(): void {
		$closed = $this->task(state: Task::STATE_TERMINATED);
		$this->stored($closed);
		$this->db->expects($this->never())->method('beginTransaction');

		$this->assertSame($closed, $this->service()->terminateAsMoot(uuid: 't-7', reason: 'r', source: 'flow'));
	}//end testTerminateAsMootSkipsATerminalTask()

	public function testTerminateAsMootTerminates(): void {
		$this->stored($this->task());

		$done = $this->service()->terminateAsMoot(uuid: 't-7', reason: 'moot', source: 'flow:x');

		$this->assertSame(['terminated', Task::STATE_TERMINATED, 'terminate'], [$done->getOutcome(), $done->getState(), $done->getLastAction()]);
		$this->assertSame(['terminate', 'flow:x', 'moot'], [$this->onlyAudit()['action'], $this->onlyAudit()['actor'], $this->onlyAudit()['reason']]);
	}//end testTerminateAsMootTerminates()

	public function testConsumeOnceThenRefuseTheSecondTime(): void {
		$task = $this->task(state: Task::STATE_COMPLETED);
		$task->setOutcome('approved');
		$task->setMetadata(['keep' => 1]);
		$this->stored($task);
		$this->db->expects($this->once())->method('commit');

		$done = $this->service()->consume(uuid: 't-7', source: 'flow:a', reason: 'used');

		$metadata = $done->getMetadata();
		$this->assertSame(1, $metadata['keep']);
		$this->assertSame('flow:a', $metadata['consumedBy']);
		$this->assertNotSame('', $metadata['consumedAt']);
		$this->assertSame(['consume', 'flow:a', 'used'], [$this->onlyAudit()['action'], $this->onlyAudit()['actor'], $this->onlyAudit()['reason']]);

		$metadata['consumedAt'] = '2020-01-01T00:00:00+00:00';
		$task->setMetadata($metadata);
		$this->expectRefusal(
			fn () => $this->service()->consume(uuid: 'T-7', source: 'flow:b', reason: 'again'),
			TaskConflictException::class,
			"Verb 'consume' refused: the approval on task 'T-7' was already consumed at 2020-01-01T00:00:00+00:00 by flow:a, and an approval authorizes exactly once."
		);
	}//end testConsumeOnceThenRefuseTheSecondTime()

	public function testConsumeNamesAnUnrecordedConsumer(): void {
		$task = $this->task(state: Task::STATE_COMPLETED);
		$task->setOutcome('approved');
		$task->setMetadata(['consumedAt' => 'yesterday']);
		$this->stored($task);

		$this->expectRefusal(
			fn () => $this->service()->consume(uuid: 't-7', source: 'flow:b', reason: 'again'),
			TaskConflictException::class,
			"Verb 'consume' refused: the approval on task 't-7' was already consumed at yesterday by an unrecorded consumer, and an approval authorizes exactly once."
		);
	}//end testConsumeNamesAnUnrecordedConsumer()

	public function testConsumeRollsBackWhenTheAuditFails(): void {
		$task = $this->task(state: Task::STATE_COMPLETED);
		$task->setOutcome('approved');
		$this->stored($task);
		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willThrowException(new RuntimeException('db down'));
		$this->audits = $audits;
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('db down');
		$this->service()->consume(uuid: 't-7', source: 'flow:a', reason: 'used');
	}//end testConsumeRollsBackWhenTheAuditFails()

	public function testConsumeRefusesARejectionAndAnUndecidedOutcome(): void {
		$task = $this->task(state: Task::STATE_COMPLETED);
		$task->setOutcome('rejected');
		$this->stored($task);

		$this->expectRefusal(
			fn () => $this->service()->consume(uuid: 't-7', source: 's', reason: 'r'),
			TaskConflictException::class,
			"Verb 'consume' refused: task 't-7' is not an approving decision (state 'completed', outcome 'rejected')."
		);

		$task->setOutcome('noted');
		$this->expectRefusal(
			fn () => $this->service()->consume(uuid: 't-7', source: 's', reason: 'r'),
			TaskConflictException::class,
			"Verb 'consume' refused: task 't-7' is undecided: outcome 'noted' neither approves nor rejects."
		);
	}//end testConsumeRefusesARejectionAndAnUndecidedOutcome()

	public function testEnableRefusesTerminalSkipsOpenAndEnablesAvailable(): void {
		$task = $this->task(state: Task::STATE_TERMINATED);
		$this->stored($task);
		$this->expectRefusal(
			fn () => $this->service()->enable(uuid: 'T-7', trigger: new TaskTrigger(source: 's', reason: 'r')),
			TaskConflictException::class,
			"Verb 'enable' refused: task 'T-7' is already in terminal state 'terminated'."
		);

		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$this->assertSame($task, $this->service()->enable(uuid: 't-7', trigger: new TaskTrigger(source: 's', reason: 'r')));
		$this->assertSame([], $this->written);

		$task->setState(Task::STATE_AVAILABLE);
		$done = $this->service()->enable(uuid: 't-7', trigger: new TaskTrigger(source: 'timer', reason: 'due'));
		$this->assertSame([Task::STATE_ENABLED, 'enable'], [$done->getState(), $done->getLastAction()]);
		$this->assertSame(['enable', 'timer', 'due'], [$this->onlyAudit()['action'], $this->onlyAudit()['actor'], $this->onlyAudit()['reason']]);
	}//end testEnableRefusesTerminalSkipsOpenAndEnablesAvailable()

	/**
	 * Timer outcomes and what each one leaves.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool}>
	 */
	public static function timerOutcomes(): array {
		return [
			'skip' => ['skip', Task::STATE_COMPLETED, 'skipped', 'skip', true],
			'error' => ['error', Task::STATE_TERMINATED, 'failed', 'error', false],
			'dead letter' => ['dead_letter', Task::STATE_DISABLED, 'dead_letter', 'dead_letter', false],
			'transition' => ['transition: escalate ', Task::STATE_COMPLETED, 'escalate', 'escalate', true],
		];
	}//end timerOutcomes()

	/**
	 * @dataProvider timerOutcomes
	 */
	public function testATimerOutcomeLandsWhereItIsMapped(string $outcome, string $state, string $recorded, string $action, bool $completed): void {
		$task = $this->task();
		$task->setBlockedReason('b');
		$this->stored($task);

		$done = $this->service()->applyTimerOutcome(uuid: 't-7', outcome: $outcome, source: 'timer', reason: 'expired');

		$this->assertSame([$state, $recorded, $action, null], [$done->getState(), $done->getOutcome(), $done->getLastAction(), $done->getBlockedReason()]);
		$this->assertSame($completed, $done->getCompletedBy() === 'timer');
		$this->assertSame($completed, $done->getCompletedAt() !== null);
		$this->assertSame([$action, 'timer', 'expired'], [$this->onlyAudit()['action'], $this->onlyAudit()['actor'], $this->onlyAudit()['reason']]);
	}//end testATimerOutcomeLandsWhereItIsMapped()

	/**
	 * Refused timer outcomes and their message.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedTimerOutcomes(): array {
		$unusable = "Timer outcome '%s' names no usable action: it must be 1..32 characters and not a reserved outcome.";

		return [
			'empty action' => ['transition: ', sprintf($unusable, 'transition: ')],
			'reserved action' => ['transition:skip', sprintf($unusable, 'transition:skip')],
			'long action' => ['transition:' . str_repeat('a', 33), sprintf($unusable, 'transition:' . str_repeat('a', 33))],
			'unknown' => ['later', "Timer outcome 'later' is refused: use skip, error, dead_letter or transition:<action>."],
		];
	}//end refusedTimerOutcomes()

	/**
	 * @dataProvider refusedTimerOutcomes
	 */
	public function testAnUnmappedTimerOutcomeIsRefusedBeforeTheTaskIsRead(string $outcome, string $message): void {
		$this->tasks->expects($this->never())->method('findByUuid');

		$this->expectRefusal(
			fn () => $this->service()->applyTimerOutcome(uuid: 't-7', outcome: $outcome, source: 's', reason: 'r'),
			TaskValidationException::class,
			$message
		);
	}//end testAnUnmappedTimerOutcomeIsRefusedBeforeTheTaskIsRead()

	public function testATimerOutcomeOnATerminalTaskChangesNothing(): void {
		$closed = $this->task(state: Task::STATE_COMPLETED);
		$this->stored($closed);
		$this->db->expects($this->never())->method('beginTransaction');

		$this->assertSame($closed, $this->service()->applyTimerOutcome(uuid: 't-7', outcome: 'skip', source: 's', reason: 'r'));
	}//end testATimerOutcomeOnATerminalTaskChangesNothing()

	public function testRecordAppendsAnAuthorizedEntryOnAnyTask(): void {
		$closed = $this->task(state: Task::STATE_COMPLETED);
		$this->stored($closed);

		$this->assertSame($closed, $this->service()->record(entry: new TaskAuditEntry(uuid: 't-7', action: 'note', actor: 'x', reason: 'why')));
		$this->assertSame(['note', 'x', 'why', true], [$this->onlyAudit()['action'], $this->onlyAudit()['actor'], $this->onlyAudit()['reason'], $this->onlyAudit()['authorized']]);
	}//end testRecordAppendsAnAuthorizedEntryOnAnyTask()

	public function testARefusedCompletionIsRecordedAuthorizedAndNeverThrows(): void {
		$task = $this->task();
		$task->setOnBehalfOf('olga');
		$this->service()->recordRefusedCompletion(task: $task, attempt: new TaskAct(verb: 'complete', actor: 'alice', reason: 'field x'));

		$this->assertSame(
			[
				'taskId' => 7,
				'action' => 'complete-refused',
				'stateAfter' => Task::STATE_ACTIVE,
				'actor' => 'alice',
				'performerType' => Task::PERFORMER_USER,
				'onBehalfOf' => 'olga',
				'mandate' => null,
				'reason' => 'field x',
				'authorized' => true,
			],
			$this->onlyAudit()
		);

		$unsaved = $this->task();
		$unsaved->setId(null);
		$this->service()->recordRefusedCompletion(task: $unsaved, attempt: new TaskAct(verb: 'complete', actor: 'a', reason: 'r'));
		$this->assertCount(1, $this->written);

		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willThrowException(new RuntimeException('db down'));
		$this->audits = $audits;
		$this->service()->recordRefusedCompletion(task: $task, attempt: new TaskAct(verb: 'complete', actor: 'a', reason: 'r'));
		$this->assertTrue(true);
	}//end testARefusedCompletionIsRecordedAuthorizedAndNeverThrows()

	public function testImportAuditsCreationWithTheTaskCreationDate(): void {
		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task): Task {
				$task->setId(41);

				return $task;
			}
		);
		$created = new \DateTime('2024-02-03T04:05:06+00:00');

		$task = $this->service()->import(
			data: ['title' => 'T', 'candidateUsers' => ['u1']],
			actor: 'sys',
			createdAt: $created
		);

		$this->assertSame(41, $task->getId());
		$entry = $this->written[0];
		$this->assertSame(['create', 'sys', null, true], [$entry->getAction(), $entry->getActor(), $entry->getReason(), $entry->getAuthorized()]);
		$this->assertSame($created, $entry->getCreated());
	}//end testImportAuditsCreationWithTheTaskCreationDate()

	public function testAnImportDenialIsNotAuditedAndWritesNothing(): void {
		$this->authorization->method('assertMay')->willThrowException(new TaskAccessDeniedException(message: 'no create'));
		$this->tasks->expects($this->never())->method('insert');

		$this->expectRefusal(
			fn () => $this->service()->import(data: ['title' => 'T'], actor: 'x'),
			TaskAccessDeniedException::class,
			'no create'
		);
		$this->assertSame([], $this->written);
	}//end testAnImportDenialIsNotAuditedAndWritesNothing()
}//end class
