<?php

/**
 * Who a user task is raised for: the refusal when nobody holds any named
 * performer or a performer type is unknown, the agent run asked of the first
 * agent assignee, and the performer kind a task records.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskStep;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * UserTaskPerformers.
 */
class UserTaskPerformersBehaviourTest extends TestCase {

	private PrincipalResolverRegistry&MockObject $principals;

	private IEventDispatcher&MockObject $events;

	protected function setUp(): void {
		$this->principals = $this->createMock(PrincipalResolverRegistry::class);
		$this->events = $this->createMock(IEventDispatcher::class);
	}//end setUp()

	private function config(): UserTaskConfig {
		return new UserTaskConfig($this->createMock(IL10N::class), $this->createMock(TaskFormReader::class));
	}//end config()

	private function performers(bool $withPrincipals = true, bool $withEvents = true): UserTaskPerformers {
		return new UserTaskPerformers(config: $this->config(), hooks: new \OCA\OpenRegister\Service\Flow\Nodes\UserTaskHooks(principals: $withPrincipals ? $this->principals : null, events: $withEvents ? $this->events : null));
	}//end performers()

	private function task(): Task {
		$task = new Task();
		$task->setUuid('task-1');
		return $task;
	}//end task()

	public function testATaskForNamedPerformersNobodyHoldsIsRefused(): void {
		$this->principals->expects($this->once())->method('resolveAll')
			->with($this->callback(static fn (array $refs): bool => array_map('strval', $refs) === ['user:alice', 'group:clerks']))
			->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('openregister.user-task cannot raise a task: it asks user:alice, group:clerks, and nobody currently holds any of them.');

		$this->performers()->refuseIfNobodyHoldsThem(['assignee' => 'alice', 'candidateGroups' => ['clerks']]);
	}//end testATaskForNamedPerformersNobodyHoldsIsRefused()

	public function testATaskForPerformersSomeoneHoldsIsAllowed(): void {
		$this->principals->expects($this->once())->method('resolveAll')->willReturn(['alice']);

		$this->performers()->refuseIfNobodyHoldsThem(['assignee' => 'alice']);
	}//end testATaskForPerformersSomeoneHoldsIsAllowed()

	public function testNoNamedPerformersOrNoRegistryChecksNothing(): void {
		$this->principals->expects($this->never())->method('resolveAll');

		$this->performers()->refuseIfNobodyHoldsThem([]);
		$this->performers(withPrincipals: false)->refuseIfNobodyHoldsThem(['assignee' => 'alice']);
	}//end testNoNamedPerformersOrNoRegistryChecksNothing()

	public function testAnUnknownPerformerTypeIsRefusedNamingIt(): void {
		$this->principals->method('has')->willReturnCallback(static fn (string $type): bool => $type === 'user');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		try {
			$this->performers()->refuseUnknownTypes(['assignee' => ['alice', ['type' => 'robot', 'id' => 'r2']]], $l10n);
			$this->fail('An unknown performer type was accepted.');
		} catch (UnexpectedValueException $e) {
			$this->assertSame(
				'This step asks a "robot" called "r2", and nothing on this server knows what a "robot" is. Is the app that provides it installed?',
				$e->getMessage()
			);
		}
	}//end testAnUnknownPerformerTypeIsRefusedNamingIt()

	public function testKnownPerformerTypesOrNoRegistryPassTheTypeCheck(): void {
		$this->principals->expects($this->exactly(2))->method('has')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->never())->method('t');

		$this->performers()->refuseUnknownTypes(['assignee' => ['alice', 'bob']], $l10n);
		$this->performers(withPrincipals: false)->refuseUnknownTypes(['assignee' => [['type' => 'robot', 'id' => 'r2']]], $l10n);
	}//end testKnownPerformerTypesOrNoRegistryPassTheTypeCheck()

	public function testTheFirstAgentAssigneeIsAskedToRunWithTheStepsPrompt(): void {
		$dispatched = [];
		$this->events->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (AgentRunRequestedEvent $event) use (&$dispatched): void {
				$dispatched[] = $event;
			}
		);
		$step = new UserTaskStep(
			[],
			[
				'assignee' => ['alice', ['type' => 'agent', 'id' => 'triage-bot'], ['type' => 'agent', 'id' => 'second-bot']],
				'subjectRegister' => ' zaken ',
				'subjectSchema' => ' zaak ',
				'skill' => 'classify',
				'prompt' => '  Classify this  ',
				'title' => 'Ignored title',
				'outcomeKey' => ' category ',
				'requiresApproval' => 1,
				'mode' => ' draft ',
			],
			['flowName' => ' Intake ']
		);

		$this->performers()->askAnyAgent($step, $this->task());

		$event = $dispatched[0];
		$this->assertSame(
			['task-1', 'zaken', 'zaak', 'triage-bot', 'classify', 'Classify this', 'category', 'draft', 'Intake'],
			[
				$event->getSubjectUuid(),
				$event->getSubjectRegister(),
				$event->getSubjectSchema(),
				$event->getAgent(),
				$event->getSkill(),
				$event->getPrompt(),
				$event->getResultField(),
				$event->getMode(),
				$event->getFlowName(),
			]
		);
		$this->assertTrue($event->getPayload()['requiresApproval']);
	}//end testTheFirstAgentAssigneeIsAskedToRunWithTheStepsPrompt()

	public function testAnAgentAskedNothingGetsTheTitleAndTheDefaults(): void {
		$dispatched = [];
		$this->events->method('dispatchTyped')->willReturnCallback(
			static function (AgentRunRequestedEvent $event) use (&$dispatched): void {
				$dispatched[] = $event;
			}
		);

		$this->performers()->askAnyAgent(new UserTaskStep([], ['assignee' => ['type' => 'agent', 'id' => 'bot'], 'title' => ' Review ']), $this->task());

		$event = $dispatched[0];
		$this->assertSame(['Review', '', 'task', '', null, '', ''], [
			$event->getPrompt(),
			$event->getResultField(),
			$event->getMode(),
			$event->getFlowName(),
			$event->getSkill(),
			$event->getSubjectRegister(),
			$event->getSubjectSchema(),
		]);
		$this->assertFalse($event->getPayload()['requiresApproval']);
	}//end testAnAgentAskedNothingGetsTheTitleAndTheDefaults()

	public function testNoAgentAssigneeOrNoDispatcherAsksNobody(): void {
		$this->events->expects($this->never())->method('dispatchTyped');

		$this->performers()->askAnyAgent(new UserTaskStep([], ['assignee' => ['alice', ['type' => 'group', 'id' => 'g']]]), $this->task());
		$this->performers(withEvents: false)->askAnyAgent(new UserTaskStep([], ['assignee' => ['type' => 'agent', 'id' => 'bot']]), $this->task());
	}//end testNoAgentAssigneeOrNoDispatcherAsksNobody()

	public function testThePerformerKindIsAuthoredOrTheFirstKnownTypeOrUser(): void {
		$group = PrincipalReference::from(['type' => 'group', 'id' => 'g']);
		$robot = PrincipalReference::from(['type' => 'robot', 'id' => 'r']);

		$this->assertSame('worker', UserTaskPerformers::kindFor(['performerType' => ' worker '], [$group]));
		$this->assertSame('group', UserTaskPerformers::kindFor(['performerType' => '  '], [$robot, $group]));
		$this->assertSame('user', UserTaskPerformers::kindFor([], [$robot]));
		$this->assertSame('user', UserTaskPerformers::kindFor([], []));
	}//end testThePerformerKindIsAuthoredOrTheFirstKnownTypeOrUser()
}//end class
