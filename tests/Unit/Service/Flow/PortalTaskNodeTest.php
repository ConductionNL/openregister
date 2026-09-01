<?php

/**
 * The portal-task node's lifecycle, walked without a database.
 *
 * What is pinned here is the set of rules an author cannot see from the
 * palette: one ask per node per run across a heartbeat wake; nothing on an
 * empty branch; the match frozen at creation and recorded; a non-null
 * heartbeat; the answer placed once with files, party and cycle; an expiry
 * distinguishable from an answer; a re-ask only with a reason, as a NEW task
 * carrying cycle two and the previous uuid; and a delivery failure that
 * leaves the ask standing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\PortalPartyNotFoundException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * Behavioural tests for {@see PortalTaskNode} and {@see PortalTaskConfig}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @covers \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @covers \OCA\OpenRegister\Service\Flow\FlowSuspension
 */
class PortalTaskNodeTest extends TestCase {

	/**
	 * The bridge, mocked.
	 *
	 * @var FlowTaskBridge&MockObject
	 */
	private FlowTaskBridge&MockObject $bridge;

	/**
	 * The party resolver, mocked.
	 *
	 * @var PortalPartyResolver&MockObject
	 */
	private PortalPartyResolver&MockObject $parties;

	/**
	 * The delivery seam, mocked.
	 *
	 * @var PortalTaskDeliveryService&MockObject
	 */
	private PortalTaskDeliveryService&MockObject $delivery;

	/**
	 * The node under test.
	 *
	 * @var PortalTaskNode
	 */
	private PortalTaskNode $node;

	/**
	 * Build the node over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->bridge = $this->createMock(FlowTaskBridge::class);
		$this->parties = $this->createMock(PortalPartyResolver::class);
		$this->delivery = $this->createMock(PortalTaskDeliveryService::class);
		$this->delivery->method('messageFor')->willReturn(['title' => 'x']);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$this->node = new PortalTaskNode($this->bridge, $this->parties, $this->delivery, $l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	/**
	 * A step configuration with overrides.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(array $overrides = []): array {
		return array_merge(['title' => 'Send the missing {{ document }}', 'uploadRequired' => true], $overrides);
	}//end config()

	/**
	 * A run context carrying a resume state for one node.
	 *
	 * @param FlowResumeState $state The run's slots.
	 * @param string $nodeId The node.
	 * @param array<string, mixed> $extra Extra context.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(FlowResumeState $state, string $nodeId = 'ask', array $extra = []): array {
		return array_merge(
			[
				FlowResumeState::CONTEXT_KEY => $state,
				FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: $nodeId),
				FlowRunContext::CONTEXT_RUN => 'run-1',
				'runUuid' => 'run-1',
				'runAs' => 'caseworker',
			],
			$extra
		);
	}//end context()

	/**
	 * One item about a case object.
	 *
	 * @param array<string, mixed> $extra Extra json fields.
	 *
	 * @return array The items.
	 */
	private function items(array $extra = []): array {
		return [
			FlowItems::item(
				json: array_merge(['document' => 'payslip', '@self' => ['uuid' => 'case-7', 'register' => 3, 'schema' => 9]], $extra)
			),
		];
	}//end items()

	/**
	 * An external task in a state.
	 *
	 * @param string $state The state.
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $metadata Metadata overrides.
	 *
	 * @return Task The task.
	 */
	private function task(string $state, string $uuid = 't-1', array $metadata = []): Task {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee('party:bsn-1');
		$task->setRunUuid('run-1');
		$task->setNodeId('ask');
		$task->setMetadata(array_merge(['cycle' => 1], $metadata));

		return $task;
	}//end task()

	// ---- Catalogue ---------------------------------------------------------

	/**
	 * The palette entry: id, the three-waiter description, keys and form agree.
	 *
	 * @return void
	 */
	public function testThePaletteEntryStatesTheThreeWaiterDivision(): void {
		$this->assertSame('openregister.portal-task', $this->node->getId());
		$this->assertStringContainsString('Ask a person', $this->node->getDescription());
		$this->assertStringContainsString('Wait for an answer', $this->node->getDescription());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));

		$formKeys = array_map(static fn (array $field): string => $field['key'], $this->node->configForm());
		foreach ($this->node->configKeys() as $key) {
			$this->assertContains($key, $formKeys, "config key $key has a form field");
		}

		foreach (['partyRole', 'uploadRequired', 'uploadMaxFiles', 'uploadAcceptedTypes', 'uploadMaxSizeMb', 'reasonField', 'advance'] as $key) {
			$this->assertContains($key, $this->node->configKeys());
		}
	}//end testThePaletteEntryStatesTheThreeWaiterDivision()

	/**
	 * Validation refuses what would bury a mistake in a suspended run.
	 *
	 * @return void
	 */
	public function testValidationRefusesNoTitleABlankRoleAndANullBudget(): void {
		$this->node->validateConfig($this->config());
		$this->node->validateConfig($this->config(['partyRole' => 'applicant', 'uploadMaxFiles' => 3, 'advance' => 'all']));

		foreach ([['title' => ''], ['partyRole' => ' '], ['uploadMaxFiles' => 'many'], ['uploadMaxFiles' => 0], ['advance' => null]] as $bad) {
			try {
				$this->node->validateConfig($this->config($bad));
				$this->fail('Expected refusal for ' . json_encode($bad));
			} catch (UnexpectedValueException) {
				$this->addToAssertionCount(1);
			}
		}
	}//end testValidationRefusesNoTitleABlankRoleAndANullBudget()

	// ---- First ask ---------------------------------------------------------

	/**
	 * The first firing: match the initiator, create ONE external task
	 * assigned to the frozen party, record the match, request delivery, and
	 * suspend with a non-null heartbeat.
	 *
	 * @return void
	 */
	public function testTheFirstFiringMatchesCreatesDeliversAndSuspends(): void {
		$state = new FlowResumeState();
		$this->parties->expects($this->once())
			->method('resolveFromObject')
			->with('case-7', 'initiator')
			->willReturn('party:bsn-1');

		$created = $this->task(state: Task::STATE_ACTIVE);
		$this->bridge->expects($this->once())
			->method('createTask')
			->with(
				$this->callback(function (array $data): bool {
					$this->assertSame('Send the missing payslip', $data['title']);
					$this->assertSame(Task::PERFORMER_EXTERNAL, $data['performerType']);
					$this->assertSame('party:bsn-1', $data['assignee'], 'the frozen match is the assignee');
					$this->assertSame(Task::STATE_ACTIVE, $data['state'], 'an external task is always assigned at creation');
					$this->assertSame('case-7', $data['objectUuid']);
					$this->assertSame(3, $data['registerId']);
					$this->assertSame('initiator', $data['metadata']['partyRole']);
					$this->assertSame('party:bsn-1', $data['metadata']['partyReference']);
					$this->assertSame(1, $data['metadata']['cycle']);
					$this->assertNull($data['metadata']['previousTaskUuid']);
					$this->assertTrue($data['metadata']['upload']['required']);
					$this->assertSame('portalTask', $data['metadata']['outcomeKey']);
					$this->assertArrayNotHasKey('candidateUsers', $data, 'no pool for an external task');

					return true;
				}),
				'run-1',
				'ask',
				'caseworker'
			)
			->willReturn($created);

		$this->bridge->expects($this->once())
			->method('record')
			->with('t-1', 'match', 'caseworker', $this->stringContains("role 'initiator' on case 'case-7' to 'party:bsn-1'"));

		$this->delivery->expects($this->once())
			->method('request')
			->with($created, PortalTaskDelivery::KIND_ASK, $this->isType('array'));

		try {
			$this->node->execute($this->items(), $this->config(), $this->context($state));
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt(), 'a null resumeAt is the one shape the 14-day reaper fails');
			$this->assertGreaterThan(new DateTime(), $suspension->getResumeAt());
			$this->assertStringContainsString('outside the organisation', $suspension->getMessage());
		}

		$slot = $state->read(nodeId: 'ask');
		$this->assertSame('t-1', $slot[FlowTaskBridge::SLOT_TASK_UUID]);
		$this->assertSame('party:bsn-1', $slot['assignee'], 'the resume door compares against the party reference, which no uid can equal');
		$this->assertSame(1, $slot[PortalTaskConfig::SLOT_CYCLE]);
		$this->assertNull($slot[PortalTaskConfig::SLOT_PASSED_AT]);
	}//end testTheFirstFiringMatchesCreatesDeliversAndSuspends()

	/**
	 * A configured role other than the default is what is matched.
	 *
	 * @return void
	 */
	public function testTheConfiguredPartyRoleIsMatched(): void {
		$this->parties->expects($this->once())->method('resolveFromObject')->with('case-7', 'applicant')->willReturn('party:kvk-9');
		$this->bridge->method('createTask')->willReturn($this->task(state: Task::STATE_ACTIVE));

		$this->expectException(FlowSuspension::class);
		$this->node->execute($this->items(), $this->config(['partyRole' => 'applicant']), $this->context(new FlowResumeState()));
	}//end testTheConfiguredPartyRoleIsMatched()

	/**
	 * A case naming nobody fails the firing loudly and creates no task.
	 *
	 * @return void
	 */
	public function testACaseWithNoPartyForTheRoleFailsTheFiringAndCreatesNothing(): void {
		$this->parties->method('resolveFromObject')
			->willThrowException(new PortalPartyNotFoundException("Case 'case-7' names no party for role 'initiator'; the portal task cannot be addressed."));
		$this->bridge->expects($this->never())->method('createTask');
		$this->delivery->expects($this->never())->method('request');

		$this->expectException(PortalPartyNotFoundException::class);
		$this->expectExceptionMessageMatches("/role 'initiator'/");
		$this->node->execute($this->items(), $this->config(), $this->context(new FlowResumeState()));
	}//end testACaseWithNoPartyForTheRoleFailsTheFiringAndCreatesNothing()

	/**
	 * An item that is about no case object cannot be matched: loud failure.
	 *
	 * @return void
	 */
	public function testAnItemWithoutACaseObjectFailsTheFiring(): void {
		$this->bridge->expects($this->never())->method('createTask');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/no case to match/');
		$this->node->execute([FlowItems::item(json: ['document' => 'x'])], $this->config(), $this->context(new FlowResumeState()));
	}//end testAnItemWithoutACaseObjectFailsTheFiring()

	/**
	 * An empty branch creates nothing and does not suspend.
	 *
	 * @return void
	 */
	public function testAnEmptyBranchCreatesNothingAndDoesNotSuspend(): void {
		$this->bridge->expects($this->never())->method('createTask');
		$this->parties->expects($this->never())->method('resolveFromObject');

		$this->assertSame([], $this->node->execute([], $this->config(), $this->context(new FlowResumeState())));
	}//end testAnEmptyBranchCreatesNothingAndDoesNotSuspend()

	/**
	 * Without a resume slot the node refuses to run at all.
	 *
	 * @return void
	 */
	public function testANodeWithoutAResumeSlotRefusesToRun(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/resume slot/');
		$this->node->execute($this->items(), $this->config(), [FlowRunContext::CONTEXT_RUN => 'run-1']);
	}//end testANodeWithoutAResumeSlotRefusesToRun()

	/**
	 * A delivery seam that records nothing still leaves the task and the
	 * suspension standing: the ask outlives a delivery outage.
	 *
	 * @return void
	 */
	public function testAFailedDeliveryRequestLeavesTheAskStanding(): void {
		$state = new FlowResumeState();
		$this->parties->method('resolveFromObject')->willReturn('party:bsn-1');
		$this->bridge->method('createTask')->willReturn($this->task(state: Task::STATE_ACTIVE));
		// The seam's contract: never throws, returns what it could write.
		$this->delivery->expects($this->once())->method('request')->willReturn([]);

		try {
			$this->node->execute($this->items(), $this->config(), $this->context($state));
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt());
		}

		$this->assertSame('t-1', $state->read(nodeId: 'ask')[FlowTaskBridge::SLOT_TASK_UUID], 'the slot holds the task despite the delivery failure');
	}//end testAFailedDeliveryRequestLeavesTheAskStanding()

	// ---- Heartbeat and continuation ---------------------------------------

	/**
	 * A heartbeat wake over an open task asks nothing new and suspends again
	 * without restamping askedAt.
	 *
	 * @return void
	 */
	public function testAHeartbeatWakeDoesNotAskTwice(): void {
		$state = new FlowResumeState();
		$state->write(nodeId: 'ask', values: [FlowTaskBridge::SLOT_TASK_UUID => 't-1', FlowTaskBridge::SLOT_ASKED_AT => '2026-01-01T00:00:00+00:00', PortalTaskConfig::SLOT_CYCLE => 1]);
		$this->bridge->method('taskOrNull')->with('t-1')->willReturn($this->task(state: Task::STATE_ACTIVE));
		$this->bridge->expects($this->never())->method('createTask');
		$this->parties->expects($this->never())->method('resolveFromObject');
		$this->delivery->expects($this->never())->method('request');

		try {
			$this->node->execute($this->items(), $this->config(), $this->context($state));
			$this->fail('Expected the node to suspend again.');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt());
		}

		$this->assertSame('2026-01-01T00:00:00+00:00', $state->read(nodeId: 'ask')[FlowTaskBridge::SLOT_ASKED_AT], 'askedAt is written once');
	}//end testAHeartbeatWakeDoesNotAskTwice()

	/**
	 * A task that vanished fails the step rather than waiting forever.
	 *
	 * @return void
	 */
	public function testAVanishedTaskFailsTheStep(): void {
		$state = new FlowResumeState();
		$state->write(nodeId: 'ask', values: [FlowTaskBridge::SLOT_TASK_UUID => 'gone']);
		$this->bridge->method('taskOrNull')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/no longer exists/');
		$this->node->execute($this->items(), $this->config(), $this->context($state));
	}//end testAVanishedTaskFailsTheStep()

	/**
	 * Completion places the answer bag on EVERY item under the configured key,
	 * with answers, files, party and cycle, and marks the slot passed ONCE.
	 *
	 * @return void
	 */
	public function testACompletedTaskPlacesTheAnswerOnEveryItemAndMarksThePass(): void {
		$state = new FlowResumeState();
		$state->write(nodeId: 'ask', values: [FlowTaskBridge::SLOT_TASK_UUID => 't-1', PortalTaskConfig::SLOT_CYCLE => 1]);
		$task = $this->task(state: Task::STATE_COMPLETED);
		$task->setOutcome('submitted');
		$task->setCompletedBy('party:bsn-1');
		$task->setResponses(['remarks' => 'here you go']);
		$task->setEvidence([['fileId' => 42, 'name' => 'payslip.pdf']]);
		$this->bridge->method('taskOrNull')->willReturn($task);

		$items = [
			FlowItems::item(json: ['n' => 1, '@self' => ['uuid' => 'case-7']]),
			FlowItems::item(json: ['n' => 2, '@self' => ['uuid' => 'case-7']]),
			'not-an-item',
		];
		$out = $this->node->execute($items, $this->config(['outcomeKey' => 'answer']), $this->context($state));

		$this->assertCount(3, $out);
		foreach ([0, 1] as $index) {
			$bag = $out[$index][FlowItems::JSON]['answer'];
			$this->assertTrue($bag['decided']);
			$this->assertFalse($bag['expired']);
			$this->assertSame('submitted', $bag['outcome']);
			$this->assertSame(['remarks' => 'here you go'], $bag['answers']);
			$this->assertSame(42, $bag['files'][0]['fileId']);
			$this->assertSame('party:bsn-1', $bag['party']);
			$this->assertSame(1, $bag['cycle']);
		}

		$this->assertSame('not-an-item', $out[2], 'a non-array item is left alone');
		$this->assertNotNull($state->read(nodeId: 'ask')[PortalTaskConfig::SLOT_PASSED_AT], 'the pass is marked so the next firing is a re-entry');
	}//end testACompletedTaskPlacesTheAnswerOnEveryItemAndMarksThePass()

	/**
	 * An expiry-terminated task continues the run distinguishably from an answer.
	 *
	 * @return void
	 */
	public function testAnExpiredAskIsNotAnAnswer(): void {
		$state = new FlowResumeState();
		$state->write(nodeId: 'ask', values: [FlowTaskBridge::SLOT_TASK_UUID => 't-1']);
		$task = $this->task(state: Task::STATE_TERMINATED);
		$task->setOutcome('expired');
		$this->bridge->method('taskOrNull')->willReturn($task);

		$out = $this->node->execute($this->items(), $this->config(), $this->context($state));
		$bag = $out[0][FlowItems::JSON]['portalTask'];
		$this->assertFalse($bag['decided']);
		$this->assertTrue($bag['expired']);
		$this->assertSame('expired', $bag['outcome']);
		$this->assertSame([], $bag['files']);
	}//end testAnExpiredAskIsNotAnAnswer()

	// ---- Re-ask ------------------------------------------------------------

	/**
	 * Re-entry with a reason creates a NEW task: fresh match, reason, cycle 2,
	 * the previous uuid, delivered as a re-ask; the slot moves to the new task.
	 *
	 * @return void
	 */
	public function testReEntryWithAReasonCreatesANewTaskCarryingCycleTwoAndThePreviousUuid(): void {
		$state = new FlowResumeState();
		$state->write(
			nodeId: 'ask',
			values: [FlowTaskBridge::SLOT_TASK_UUID => 't-1', PortalTaskConfig::SLOT_CYCLE => 1, PortalTaskConfig::SLOT_PASSED_AT => '2026-01-02T00:00:00+00:00']
		);
		$first = $this->task(state: Task::STATE_COMPLETED);
		$this->bridge->method('taskOrNull')->with('t-1')->willReturn($first);
		$this->parties->expects($this->once())->method('resolveFromObject')->with('case-7', 'initiator')->willReturn('party:bsn-1');

		$second = $this->task(state: Task::STATE_ACTIVE, uuid: 't-2');
		$this->bridge->expects($this->once())
			->method('createTask')
			->with(
				$this->callback(function (array $data): bool {
					$this->assertSame(2, $data['metadata']['cycle']);
					$this->assertSame('t-1', $data['metadata']['previousTaskUuid']);
					$this->assertSame('The scan is unreadable', $data['metadata']['reaskReason']);

					return true;
				}),
				'run-1',
				'ask',
				'caseworker'
			)
			->willReturn($second);
		$this->delivery->expects($this->once())->method('request')->with($second, PortalTaskDelivery::KIND_RE_ASK, $this->isType('array'));

		$items = $this->items(['review' => ['outcome' => 'rejected', 'comment' => 'The scan is unreadable']]);
		try {
			$this->node->execute($items, $this->config(['reasonField' => 'review.comment']), $this->context($state));
			$this->fail('Expected the re-ask to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt());
		}

		$slot = $state->read(nodeId: 'ask');
		$this->assertSame('t-2', $slot[FlowTaskBridge::SLOT_TASK_UUID]);
		$this->assertSame(2, $slot[PortalTaskConfig::SLOT_CYCLE]);
		$this->assertSame('t-1', $slot[PortalTaskConfig::SLOT_PREVIOUS_TASK_UUID]);
		$this->assertNull($slot[PortalTaskConfig::SLOT_PASSED_AT], 'the new cycle has not passed yet');
		$this->assertSame(Task::STATE_COMPLETED, $first->getState(), 'the first task is untouched');
	}//end testReEntryWithAReasonCreatesANewTaskCarryingCycleTwoAndThePreviousUuid()

	/**
	 * Re-entry WITHOUT a reason fails the firing naming the field, and asks nobody.
	 *
	 * @return void
	 */
	public function testReEntryWithoutAReasonIsRefusedAndCreatesNothing(): void {
		$state = new FlowResumeState();
		$state->write(nodeId: 'ask', values: [FlowTaskBridge::SLOT_TASK_UUID => 't-1', PortalTaskConfig::SLOT_PASSED_AT => '2026-01-02T00:00:00+00:00']);
		$this->bridge->method('taskOrNull')->willReturn($this->task(state: Task::STATE_COMPLETED));
		$this->bridge->expects($this->never())->method('createTask');
		$this->parties->expects($this->never())->method('resolveFromObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/no reason under "review\.comment"/');
		$this->node->execute($this->items(), $this->config(['reasonField' => 'review.comment']), $this->context($state));
	}//end testReEntryWithoutAReasonIsRefusedAndCreatesNothing()

	// ---- Config boundary ---------------------------------------------------

	/**
	 * The upload constraints normalise to the shape the completion validates.
	 *
	 * @return void
	 */
	public function testUploadConstraintsNormalise(): void {
		$config = new PortalTaskConfig(l10n: $this->createMock(IL10N::class));
		$this->assertSame(
			['required' => false, 'maxFiles' => 1, 'acceptedTypes' => [], 'maxSizeBytes' => null],
			$config->uploadConstraints(config: [])
		);
		$this->assertSame(
			['required' => true, 'maxFiles' => 3, 'acceptedTypes' => ['application/pdf', 'image/*'], 'maxSizeBytes' => 2621440],
			$config->uploadConstraints(config: ['uploadRequired' => 'true', 'uploadMaxFiles' => '3', 'uploadAcceptedTypes' => 'application/pdf, image/*', 'uploadMaxSizeMb' => 2.5])
		);
		$this->assertSame('initiator', $config->partyRole(config: []));
		$this->assertSame('reason', $config->reasonField(config: []));
		$this->assertSame('portalTask', $config->outcomeKey(config: []));
		$this->assertGreaterThanOrEqual(
			(new DateTime())->modify('+4 minutes'),
			$config->heartbeatAt(config: ['heartbeatMinutes' => 1]),
			'the heartbeat is clamped to the five-minute floor'
		);
	}//end testUploadConstraintsNormalise()
}//end class
