<?php

/**
 * PortalTaskNode: who the task is created as, the run it must belong to, the
 * suspension it parks under, the answer bag it places, and the re-ask after
 * a re-entry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskServices;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskAuditEntry;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskRound
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskServices
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\TaskConfigCommon
 * @uses \OCA\OpenRegister\Service\Flow\FlowAdvanceBudget
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowSuspension
 * @uses \OCA\OpenRegister\Service\Flow\FlowTaskBridge
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueRenderer
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Db\Task
 */
class PortalTaskNodeBehaviourTest extends TestCase {

	private FlowTaskBridge&MockObject $bridge;

	private PortalPartyResolver&MockObject $parties;

	private PortalTaskDeliveryService&MockObject $delivery;

	private PortalTaskNode $node;

	protected function setUp(): void {
		parent::setUp();
		$this->bridge = $this->createMock(FlowTaskBridge::class);
		$this->parties = $this->createMock(PortalPartyResolver::class);
		$this->delivery = $this->createMock(PortalTaskDeliveryService::class);
		$this->delivery->method('messageFor')->willReturn(['title' => 'x']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->node = new PortalTaskNode(
			services: new PortalTaskServices(bridge: $this->bridge, parties: $this->parties, delivery: $this->delivery),
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $this->urls())
		);
	}//end setUp()

	/**
	 * @param array<string, mixed> $extra Extra json fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items(array $extra = []): array {
		return [
			FlowItems::item(json: array_merge(['document' => 'payslip', '@self' => ['uuid' => 'case-7', 'register' => 3, 'schema' => 9]], $extra)),
		];
	}//end items()

	/**
	 * @param array<string, mixed> $extra Context overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function context(FlowResumeState $state, array $extra = []): array {
		return array_merge(
			[
				FlowResumeState::CONTEXT_KEY => $state,
				FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
			],
			$extra
		);
	}//end context()

	private function task(string $state, string $uuid = 't-1', array $metadata = []): Task {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee('party:bsn-1');
		$task->setMetadata($metadata);

		return $task;
	}//end task()

	/**
	 * A state whose node slot already points at a task.
	 *
	 * @param array<string, mixed> $slot Extra slot values.
	 */
	private function waitingOn(string $taskUuid, array $slot = []): FlowResumeState {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->merge(values: array_merge([FlowTaskBridge::SLOT_TASK_UUID => $taskUuid], $slot));

		return $state;
	}//end waitingOn()

	public function testAnAskOutsideAPersistedRunIsRefusedBeforeAnythingIsCreated(): void {
		$this->parties->expects(self::never())->method('resolveFromObject');
		$this->bridge->expects(self::never())->method('createTask');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cannot create a task outside a persisted run');

		$this->node->execute($this->items(), ['title' => 'Send it'], $this->context(new FlowResumeState(), ['runUuid' => '  ']));
	}//end testAnAskOutsideAPersistedRunIsRefusedBeforeAnythingIsCreated()

	public function testTheLegacyRunKeyAndTheTriggeringUserCreateTheTask(): void {
		$state = new FlowResumeState();
		$created = $this->task(state: Task::STATE_ACTIVE);
		$this->parties->method('resolveFromObject')->willReturn('party:bsn-1');
		$this->bridge->expects(self::once())->method('createTask')
			->with(
				self::callback(static function (array $data): bool {
					return $data['description'] === 'About payslip'
						&& $data['dueAt'] === null
						&& $data['onTimeout'] === 'error'
						&& $data['onReject'] === null;
				}),
				'run-legacy',
				'ask',
				'clerk'
			)
			->willReturn($created);
		$this->bridge->expects(self::once())->method('record')->with(self::callback(static fn (TaskAuditEntry $entry): bool => [$entry->uuid, $entry->action, $entry->actor] === ['t-1', 'match', 'clerk'] && str_contains($entry->reason, '(cycle 1)')));
		$this->delivery->expects(self::once())->method('request')->with($created, PortalTaskDelivery::KIND_ASK, ['title' => 'x']);

		try {
			$this->node->execute(
				$this->items(),
				['title' => 'Send the {{ document }}', 'description' => 'About {{ document }}', 'dueAt' => '{{ missing }}', 'onTimeout' => ' error ', 'onReject' => ''],
				$this->context($state, ['runUuid' => 'run-legacy', 'runAs' => '  ', 'triggeredBy' => 'clerk'])
			);
			self::fail('The ask must suspend the run.');
		} catch (FlowSuspension $suspension) {
			self::assertSame('waiting for a party outside the organisation: Send the payslip', $suspension->getMessage());
		}

		self::assertSame('t-1', $state->read(nodeId: 'ask')[FlowTaskBridge::SLOT_TASK_UUID]);
	}//end testTheLegacyRunKeyAndTheTriggeringUserCreateTheTask()

	public function testWithoutAnActingIdentityTheTaskIsCreatedWithoutAnActor(): void {
		$this->parties->method('resolveFromObject')->willReturn('party:bsn-1');
		$this->bridge->expects(self::once())->method('createTask')
			->with(self::anything(), 'run-1', 'ask', null)
			->willReturn($this->task(state: Task::STATE_ACTIVE));

		$this->expectException(FlowSuspension::class);

		$this->node->execute($this->items(), ['title' => 'Send it'], $this->context(new FlowResumeState(), [FlowRunContext::CONTEXT_RUN => 'run-1']));
	}//end testWithoutAnActingIdentityTheTaskIsCreatedWithoutAnActor()

	public function testAWakeOnAnOpenTaskParksAgainUnderAGenericTitleAndKeepsTheSlot(): void {
		$state = $this->waitingOn(taskUuid: 't-1', slot: [FlowTaskBridge::SLOT_ASKED_AT => '2026-01-01T00:00:00+00:00']);
		$this->bridge->method('taskOrNull')->with('t-1')->willReturn($this->task(state: Task::STATE_ACTIVE));
		$this->bridge->expects(self::never())->method('createTask');

		try {
			$this->node->execute($this->items(), ['title' => '{{ nothing }}'], $this->context($state));
			self::fail('An open task must suspend again.');
		} catch (FlowSuspension $suspension) {
			self::assertSame('waiting for a party outside the organisation: a portal task', $suspension->getMessage());
		}

		self::assertSame('2026-01-01T00:00:00+00:00', $state->read(nodeId: 'ask')[FlowTaskBridge::SLOT_ASKED_AT]);
	}//end testAWakeOnAnOpenTaskParksAgainUnderAGenericTitleAndKeepsTheSlot()

	public function testAnExpiredTaskTravelsOnUnderTheConfiguredKeyAndSkipsNonArrayItems(): void {
		$state = $this->waitingOn(taskUuid: 't-1');
		$task = $this->task(state: Task::STATE_TERMINATED, metadata: ['cycle' => 2, 'reaskReason' => 'blurry']);
		$task->setOutcome('expired');
		$task->setEvidence([['name' => 'scan.pdf']]);
		$this->bridge->method('taskOrNull')->willReturn($task);
		$this->bridge->expects(self::once())->method('recordHeartbeatRecovery')->with($task);

		$items = $this->items();
		$items[] = 'not-an-item';
		$out = $this->node->execute($items, ['title' => 'Send it', 'outcomeKey' => 'answer'], $this->context($state));

		$bag = $out[0][FlowItems::JSON]['answer'];
		self::assertArrayNotHasKey('portalTask', $out[0][FlowItems::JSON]);
		self::assertFalse($bag['decided']);
		self::assertSame('expired', $bag['outcome']);
		self::assertTrue($bag['expired']);
		self::assertSame([['name' => 'scan.pdf']], $bag['files']);
		self::assertSame('party:bsn-1', $bag['party']);
		self::assertSame(2, $bag['cycle']);
		self::assertSame('blurry', $bag['reason']);
		self::assertSame('not-an-item', $out[1]);
		self::assertNotNull($state->read(nodeId: 'ask')[PortalTaskConfig::SLOT_PASSED_AT]);
	}//end testAnExpiredTaskTravelsOnUnderTheConfiguredKeyAndSkipsNonArrayItems()

	public function testACompletedTaskWithoutMetadataIsADecidedFirstCycleAnswer(): void {
		$state = $this->waitingOn(taskUuid: 't-1');
		$task = $this->task(state: Task::STATE_COMPLETED);
		$task->setMetadata(null);
		$task->setOutcome('expired-but-completed');
		$this->bridge->method('taskOrNull')->willReturn($task);
		$this->bridge->expects(self::never())->method('recordHeartbeatRecovery');

		$out = $this->node->execute(
			$this->items(),
			['title' => 'Send it'],
			$this->context($state, [FlowRunServiceInterface::SIGNAL_CONTEXT_KEY => ['taskUuid' => 't-1']])
		);

		$bag = $out[0][FlowItems::JSON]['portalTask'];
		self::assertTrue($bag['decided']);
		self::assertFalse($bag['expired'], 'a decided answer is never expired, whatever its outcome says');
		self::assertSame(1, $bag['cycle']);
		self::assertNull($bag['reason']);
		self::assertSame([], $bag['files']);
	}//end testACompletedTaskWithoutMetadataIsADecidedFirstCycleAnswer()

	public function testAReEntryReAsksTheSamePartyInTheNextCycleWithAReAskDelivery(): void {
		$state = $this->waitingOn(
			taskUuid: 't-1',
			slot: [PortalTaskConfig::SLOT_PASSED_AT => '2026-01-01T00:00:00+00:00', PortalTaskConfig::SLOT_CYCLE => 2]
		);
		$this->bridge->method('taskOrNull')->willReturn($this->task(state: Task::STATE_COMPLETED));
		$this->parties->expects(self::once())->method('resolveFromObject')->with('case-7', 'initiator')->willReturn('party:bsn-1');
		$created = $this->task(state: Task::STATE_ACTIVE, uuid: 't-3');
		$this->bridge->expects(self::once())->method('createTask')
			->with(
				self::callback(static function (array $data): bool {
					return $data['metadata']['cycle'] === 3
						&& $data['metadata']['previousTaskUuid'] === 't-1'
						&& $data['metadata']['reaskReason'] === 'The scan is unreadable';
				}),
				'run-1',
				'ask',
				'caseworker'
			)
			->willReturn($created);
		$this->delivery->expects(self::once())->method('request')->with($created, PortalTaskDelivery::KIND_RE_ASK, self::anything());

		try {
			$this->node->execute(
				$this->items(['why' => ['text' => 'The scan is unreadable']]),
				['title' => 'Send it', 'reasonField' => 'why.text'],
				$this->context($state, [FlowRunContext::CONTEXT_RUN => 'run-1', 'runAs' => 'caseworker'])
			);
			self::fail('A re-ask must suspend the run.');
		} catch (FlowSuspension) {
			$slot = $state->read(nodeId: 'ask');
			self::assertSame('t-3', $slot[FlowTaskBridge::SLOT_TASK_UUID]);
			self::assertSame(3, $slot[PortalTaskConfig::SLOT_CYCLE]);
			self::assertSame('t-1', $slot[PortalTaskConfig::SLOT_PREVIOUS_TASK_UUID]);
			self::assertNull($slot[PortalTaskConfig::SLOT_PASSED_AT]);
		}
	}//end testAReEntryReAsksTheSamePartyInTheNextCycleWithAReAskDelivery()

	public function testAReEntryWhoseReasonIsNotAScalarNamesTheConfiguredField(): void {
		$state = $this->waitingOn(taskUuid: 't-1', slot: [PortalTaskConfig::SLOT_PASSED_AT => '2026-01-01T00:00:00+00:00']);
		$this->bridge->method('taskOrNull')->willReturn($this->task(state: Task::STATE_COMPLETED));
		$this->bridge->expects(self::never())->method('createTask');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('re-entered after task t-1 ended, but the items carry no reason under "why"');

		$this->node->execute(
			$this->items(['why' => ['nested' => 'object']]),
			['title' => 'Send it', 'reasonField' => 'why'],
			$this->context($state, [FlowRunContext::CONTEXT_RUN => 'run-1'])
		);
	}//end testAReEntryWhoseReasonIsNotAScalarNamesTheConfiguredField()

	public function testAVanishedTaskNamesTheUuidItWaitedOn(): void {
		$this->bridge->method('taskOrNull')->with('t-9')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Task t-9, which this step was waiting on, no longer exists.');

		$this->node->execute($this->items(), ['title' => 'Send it'], $this->context($this->waitingOn(taskUuid: ' t-9 ')));
	}//end testAVanishedTaskNamesTheUuidItWaitedOn()
	private function urls(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/' . $app . '/img/' . $image);

		return $urls;
	}//end urls()

	public function testTheFormOffersEveryConfigKeyAndOnlyTheTitleIsRequired(): void {
		$form = $this->node->configForm();

		$keys = $this->node->configKeys();
		$offered = array_column($form, 'key');
		sort($keys);
		sort($offered);
		self::assertSame($keys, $offered);
		$required = array_values(array_filter($form, static fn (array $field): bool => ($field['required'] ?? false) === true));
		self::assertSame(['title'], array_column($required, 'key'));
		$types = array_column($form, 'type', 'key');
		self::assertSame('textarea', $types['description']);
		self::assertSame('boolean', $types['uploadRequired']);
		self::assertSame('number', $types['uploadMaxFiles']);
		self::assertSame('number', $types['uploadMaxSizeMb']);
		self::assertSame('number', $types['heartbeatMinutes']);
		self::assertSame('text', $types['advance']);
		foreach ($form as $field) {
			self::assertNotSame('', $field['label'], $field['key'] . ' has a label');
			self::assertNotSame('', $field['help'], $field['key'] . ' has help');
		}
	}//end testTheFormOffersEveryConfigKeyAndOnlyTheTitleIsRequired()

	public function testThePaletteIdentity(): void {
		self::assertSame('openregister.portal-task', $this->node->getId());
		self::assertSame('Ask a party outside the organisation', $this->node->getDisplayName());
		self::assertStringContainsString('"Ask a person"', $this->node->getDescription());
		self::assertSame('/core/img/actions/share.svg', $this->node->getIcon());
		self::assertSame('userTask', $this->node->getKind());
		self::assertSame('human', $this->node->getCategory());
		self::assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		self::assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		self::assertFalse($this->node->isAvailableForScope(99));
	}//end testThePaletteIdentity()

	public function testValidationDelegatesToTheConfigRules(): void {
		$this->node->validateConfig(['title' => 'Send it', 'uploadMaxFiles' => 2]);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('uploadMaxFiles must be at least 1');
		$this->node->validateConfig(['title' => 'Send it', 'uploadMaxFiles' => 0]);
	}//end testValidationDelegatesToTheConfigRules()

	public function testAnEmptyBranchPassesThroughWithoutASlot(): void {
		$this->bridge->expects(self::never())->method('createTask');

		self::assertSame([], $this->node->execute([], ['title' => 'Send it'], []));
	}//end testAnEmptyBranchPassesThroughWithoutASlot()

	public function testItemsWithoutAResumeSlotAreRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs a node resume slot');

		$this->node->execute($this->items(), ['title' => 'Send it'], [FlowRunContext::CONTEXT_RUN => 'run-1']);
	}//end testItemsWithoutAResumeSlotAreRefused()

	public function testAnItemWithoutACaseIsRefusedBeforeAPartyIsMatched(): void {
		$this->parties->expects(self::never())->method('resolveFromObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs the item to be about a case object');

		$this->node->execute(
			[FlowItems::item(json: ['document' => 'payslip'])],
			['title' => 'Send it'],
			$this->context(new FlowResumeState(), [FlowRunContext::CONTEXT_RUN => 'run-1'])
		);
	}//end testAnItemWithoutACaseIsRefusedBeforeAPartyIsMatched()
}//end class
