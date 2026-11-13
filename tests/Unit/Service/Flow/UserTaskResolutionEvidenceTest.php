<?php

/**
 * The resolution at task creation is kept as evidence, and the answer guard never reads it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-typed-principals/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskServices;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCP\IURLGenerator;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskHooks;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers;
use OCA\OpenRegister\Service\Flow\Principal\IPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCA\OpenRegister\Service\Flow\RunSignal;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Task\TaskFormSources;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A membership roster whose holders the test changes between creation and answer.
 */
class EvidenceRoster implements IPrincipalResolver {

	/**
	 * Holders per id.
	 *
	 * @var array<string, list<string>>
	 */
	public array $holders = [];

	/**
	 * Constructor.
	 *
	 * @param string $type The principal type.
	 */
	public function __construct(private readonly string $type) {
	}//end __construct()

	/**
	 * The principal type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}//end type()

	/**
	 * The current holders.
	 *
	 * @param string $id The principal id.
	 *
	 * @return list<string>
	 */
	public function resolve(string $id): array {
		return ($this->holders[$id] ?? []);
	}//end resolve()
}//end class

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunAssignee
 */
final class UserTaskResolutionEvidenceTest extends TestCase {

	/**
	 * The group `vergunningen`, held at creation by anna and bram.
	 *
	 * @var EvidenceRoster
	 */
	private EvidenceRoster $groups;

	/**
	 * The registry over the roster.
	 *
	 * @var PrincipalResolverRegistry
	 */
	private PrincipalResolverRegistry $registry;

	/**
	 * Set up the roster and its registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->groups = new EvidenceRoster('group');
		$this->groups->holders['vergunningen'] = ['anna', 'bram'];

		$roster = $this->groups;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($roster): void {
				if ($event instanceof RegisterPrincipalResolversEvent) {
					$event->registerResolver($roster);
				}
			}
		);
		$this->registry = new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * The performer questions over the registry, or over none.
	 *
	 * @param bool $withRegistry Whether a registry is wired.
	 *
	 * @return UserTaskPerformers
	 */
	private function performers(bool $withRegistry = true): UserTaskPerformers {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$config = new UserTaskConfig(
			l10n: $l10n,
			forms: new TaskFormReader(
				new TaskFormSources($this->createMock(SchemaMapper::class), $this->createMock(TransitionEngine::class)),
				$this->createMock(IAppManager::class),
				$l10n
			)
		);

		$hooks = new UserTaskHooks();
		if ($withRegistry === true) {
			$hooks = new UserTaskHooks(principals: $this->registry);
		}

		return new UserTaskPerformers(config: $config, hooks: $hooks);
	}//end performers()

	/**
	 * The evidence names the references and who held them, with the moment.
	 *
	 * @return void
	 */
	public function testTheResolutionIsRecordedWithItsMoment(): void {
		$evidence = $this->performers()->resolutionEvidence(
			config: ['assignee' => ['type' => 'group', 'id' => 'vergunningen'], 'candidateGroups' => ['vergunningen']]
		);

		$this->assertSame(['group:vergunningen', 'group:vergunningen'], $evidence['principals']);
		$this->assertSame(['anna', 'bram'], $evidence['users']);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $evidence['resolvedAt']));
	}//end testTheResolutionIsRecordedWithItsMoment()

	/**
	 * No performer, or nothing to resolve with, records nothing.
	 *
	 * @return void
	 */
	public function testNothingIsRecordedWithoutPerformersOrResolvers(): void {
		$this->assertNull($this->performers()->resolutionEvidence(config: []));
		$this->assertNull($this->performers(withRegistry: false)->resolutionEvidence(config: ['assignee' => ['type' => 'group', 'id' => 'vergunningen']]));
	}//end testNothingIsRecordedWithoutPerformersOrResolvers()

	/**
	 * A former member stays in the record and is refused: the guard resolves again.
	 *
	 * @return void
	 */
	public function testAFormerMemberIsRefusedWhileStillInTheRecord(): void {
		$reference = ['type' => 'group', 'id' => 'vergunningen'];
		$evidence = $this->performers()->resolutionEvidence(config: ['assignee' => $reference]);

		// bram leaves the group after the task was raised.
		$this->groups->holders['vergunningen'] = ['anna'];

		$run = new FlowRun();
		$run->setContext(
			[
				FlowResumeState::CONTEXT_KEY => [
					'ask' => ['askedAt' => $evidence['resolvedAt'], 'assignee' => $reference, 'performersAtCreation' => $evidence],
				],
			]
		);
		$guard = new FlowRunAssignee(null, $this->registry);

		$this->assertContains('bram', $evidence['users'], 'the record still shows bram among those asked');
		$this->assertFalse($guard->mayAnswer(run: $run, signal: new RunSignal(payload: [], actorUid: 'bram')), 'a former member must be refused');
		$this->assertTrue($guard->mayAnswer(run: $run, signal: new RunSignal(payload: [], actorUid: 'anna')));
	}//end testAFormerMemberIsRefusedWhileStillInTheRecord()

	/**
	 * The task a user-task step raises carries the evidence in its metadata, beside what was there.
	 *
	 * @return void
	 */
	public function testTheRaisedTaskCarriesTheEvidenceInItsMetadata(): void {
		$created = [];
		$bridge = $this->createMock(FlowTaskBridge::class);
		$bridge->method('createTask')->willReturnCallback(
			static function (array $data) use (&$created): Task {
				$created = $data;
				$task = new Task();
				$task->setUuid('task-1');
				$task->setState(Task::STATE_ACTIVE);

				return $task;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));

		$node = new UserTaskNode(
			new UserTaskServices($bridge, $forms, $this->createMock(FlowTimerService::class)),
			new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class)),
			new UserTaskHooks(principals: $this->registry)
		);
		$state = new FlowResumeState();
		try {
			$node->execute(
				new FlowNodeCall(
					items: [['json' => []]],
					config: ['title' => 'Check the permit', 'assignee' => ['type' => 'group', 'id' => 'vergunningen']],
					context: [
						FlowResumeState::CONTEXT_KEY => $state,
						FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
						FlowRunContext::CONTEXT_RUN => 'run-1',
						'runUuid' => 'run-1',
					]
				)
			);
		} catch (FlowSuspension) {
			// Expected: the step waits for its answer.
		}

		$this->assertSame('ask', $created['metadata']['flowNode']);
		$this->assertSame(['group:vergunningen'], $created['metadata']['performersAtCreation']['principals']);
		$this->assertSame(['anna', 'bram'], $created['metadata']['performersAtCreation']['users']);
	}//end testTheRaisedTaskCarriesTheEvidenceInItsMetadata()
}//end class
