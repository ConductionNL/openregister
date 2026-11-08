<?php

/**
 * Pins the paths of the four save-path lifecycle listeners that their own tests leave open.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleRunSubject;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardContext;
use OCA\OpenRegister\Lifecycle\LifecycleTransitionGuardInterface;
use OCA\OpenRegister\Listener\ApprovalChainGateListener;
use OCA\OpenRegister\Listener\LifecycleActionListener;
use OCA\OpenRegister\Listener\LifecycleInitialStateListener;
use OCA\OpenRegister\Listener\LifecycleValidationListener;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionReporting;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCP\EventDispatcher\Event;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Listener\Support\LifecycleTransitionAccess;
use OCA\OpenRegister\Listener\Support\LifecycleTransitionChecks;
use OCA\OpenRegister\Listener\Support\ApprovalChainSequences;

/**
 * @covers \OCA\OpenRegister\Listener\ApprovalChainGateListener
 * @covers \OCA\OpenRegister\Listener\ChainGateRun
 * @covers \OCA\OpenRegister\Listener\LifecycleAttempt
 * @covers \OCA\OpenRegister\Listener\LifecycleCreate
 * @covers \OCA\OpenRegister\Listener\LifecycleDeclaration
 * @covers \OCA\OpenRegister\Listener\LifecycleEdit
 * @covers \OCA\OpenRegister\Listener\LifecycleEditReader
 * @covers \OCA\OpenRegister\Listener\LifecycleInitialReference
 * @covers \OCA\OpenRegister\Listener\LifecycleActionListener
 * @covers \OCA\OpenRegister\Listener\LifecycleInitialStateListener
 * @covers \OCA\OpenRegister\Listener\LifecycleValidationListener
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\TaskSequence
 * @uses   \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses   \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses   \OCA\OpenRegister\Lifecycle\GuardResult
 * @uses   \OCA\OpenRegister\Lifecycle\LifecycleGuardContext
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller
 * @uses   \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @uses   \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 * @uses   \OCA\OpenRegister\Service\Flow\FlowExpression
 * @uses   \OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope
 * @uses   \OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext
 * @uses   \OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator
 * @uses   \OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry
 * @uses   \OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver
 * @uses   \OCA\OpenRegister\Service\Rules\ConditionDialect
 * @uses   \OCA\OpenRegister\Service\Rules\ConditionTracer
 * @uses   \OCA\OpenRegister\Service\Rules\RuleDescriptor
 * @uses   \OCA\OpenRegister\Service\Rules\RuleTrace
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\Task\ApprovingOutcomeSet
 */
class LifecycleListenersGoldenTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private LoggerInterface&MockObject $logger;
	private IUserSession&MockObject $userSession;
	private LifecycleActionExecutor&MockObject $executor;
	private TaskSequenceMapper&MockObject $sequenceMapper;
	private TaskSequenceService&MockObject $sequenceService;
	private ReferenceResolver&MockObject $references;
	private PermissionHandler&MockObject $permission;
	private RuleRunRecorder&MockObject $ruleRuns;
	private ContainerInterface&MockObject $guards;
	private LifecycleActionContext $actions;

	protected function setUp(): void {
		$this->schemaMapper    = $this->createMock(SchemaMapper::class);
		$this->logger          = $this->createMock(LoggerInterface::class);
		$this->userSession     = $this->createMock(IUserSession::class);
		$this->executor        = $this->createMock(LifecycleActionExecutor::class);
		$this->sequenceMapper  = $this->createMock(TaskSequenceMapper::class);
		$this->sequenceService = $this->createMock(TaskSequenceService::class);
		$this->references      = $this->createMock(ReferenceResolver::class);
		$this->permission      = $this->createMock(PermissionHandler::class);
		$this->ruleRuns        = $this->createMock(RuleRunRecorder::class);
		$this->guards          = $this->createMock(ContainerInterface::class);
		$this->actions         = new LifecycleActionContext();
	}//end setUp()

	private function resolver(): LifecycleTransitionResolver {
		return new LifecycleTransitionResolver($this->actions);
	}//end resolver()

	private function actionListener(): LifecycleActionListener {
		return new LifecycleActionListener(
			new \OCA\OpenRegister\Listener\LifecycleEditReader($this->schemaMapper, $this->resolver(), $this->logger),
			$this->executor,
			$this->logger
		);
	}//end actionListener()

	private function gateListener(): ApprovalChainGateListener {
		return new ApprovalChainGateListener(
			new \OCA\OpenRegister\Listener\LifecycleEditReader($this->schemaMapper, $this->resolver(), $this->logger),
			new ApprovalChainSequences($this->sequenceMapper, $this->sequenceService, new ApprovalChainAnnotationInstaller(logger: $this->logger)),
			$this->userSession,
			$this->logger
		);
	}//end gateListener()

	private function initialListener(): LifecycleInitialStateListener {
		return new LifecycleInitialStateListener(
			new \OCA\OpenRegister\Listener\LifecycleEditReader($this->schemaMapper, new \OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver(new \OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext()), $this->logger),
			$this->references,
			$this->logger
		);
	}//end initialListener()

	private function validationListener(): LifecycleValidationListener {
		$server = $this->createMock(IServerContainer::class);
		$server->method('get')->willThrowException(new RuntimeException('not found'));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		$dialect = new ConditionDialect(
			ast: new CalculationEvaluator(placeholders: new PlaceholderResolver(userSession: $this->createMock(IUserSession::class)))
		);

		return new LifecycleValidationListener(
			new LifecycleTransitionAccess(new \OCA\OpenRegister\Listener\LifecycleEditReader($this->schemaMapper, $this->resolver(), $this->logger), $this->userSession, $this->permission),
			new LifecycleTransitionChecks(new LifecycleGuardRegistry($this->guards, $server, $this->logger), new LifecycleConditionEvaluator($this->userSession, $groups, new LifecycleConditionReporting($l10n, $this->logger), $dialect), new ConditionTracer(dialect: $dialect)),
			$this->ruleRuns
		);
	}//end validationListener()

	private function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('golden');
		$schema->setConfiguration($configuration);
		$this->schemaMapper->method('find')->willReturn($schema);
		return $schema;
	}//end schema()

	private function object(array $data, ?string $schema = 'golden'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setUuid('obj-1');
		$object->setRegister('12');
		$object->setOrganisation('org-1');
		$object->setObject($data);
		return $object;
	}//end object()

	private function data(ObjectEntity $object): array {
		$data = $object->getObject();
		unset($data['id']);
		return $data;
	}//end data()

	private function update(array $old, array $new): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(newObject: $this->object($new), oldObject: $this->object($old));
	}//end update()

	private function lifecycle(array $extra = []): array {
		return [
			'x-openregister-lifecycle' => array_merge(
				['field' => 'status', 'transitions' => ['submit' => ['from' => 'draft', 'to' => 'sent']]],
				$extra
			),
		];
	}//end lifecycle()

	private function chain(array $spec): array {
		return $this->lifecycle() + ['x-openregister-approval-chains' => ['pay' => $spec]];
	}//end chain()

	private function approvers(): array {
		return [
			['role' => 'clerks', 'min' => 1, 'minAmount' => 100],
			['role' => 'heads', 'min' => 1, 'minAmount' => 500],
			['role' => 'heads-too', 'min' => 1, 'minAmount' => 500],
		];
	}//end approvers()

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end loginAs()

	private function sequence(string $status): TaskSequence {
		$sequence = new TaskSequence();
		$sequence->setUuid('seq-1');
		$sequence->setStatus($status);
		return $sequence;
	}//end sequence()

	/**
	 * Every listener ignores an event that is not its own, before reading anything.
	 */
	public function testAForeignEventIsIgnored(): void {
		$this->schemaMapper->expects($this->never())->method('find');
		$event = new Event();

		$this->actionListener()->handle($event);
		$this->gateListener()->handle($event);
		$this->initialListener()->handle($event);
		$this->validationListener()->handle($event);
	}//end testAForeignEventIsIgnored()

	/**
	 * An update without a stored object is a create: nothing to judge.
	 */
	public function testAnUpdateWithoutAnOldObjectIsIgnored(): void {
		$this->schemaMapper->expects($this->never())->method('find');
		$event = new ObjectUpdatingEvent(newObject: $this->object(['status' => 'sent']));

		$this->actionListener()->handle($event);
		$this->gateListener()->handle($event);
		$this->validationListener()->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUpdateWithoutAnOldObjectIsIgnored()

	/**
	 * An object without a schema reference, null or empty, is never looked up.
	 */
	public function testAnObjectWithoutASchemaReferenceIsNotLookedUp(): void {
		$this->schemaMapper->expects($this->never())->method('find');
		foreach ([null, ''] as $reference) {
			$event = new ObjectUpdatingEvent(
				newObject: $this->object(['status' => 'sent'], $reference),
				oldObject: $this->object(['status' => 'draft'], $reference)
			);
			$this->actionListener()->handle($event);
			$this->gateListener()->handle($event);
			$this->validationListener()->handle($event);
			$this->initialListener()->handle(new ObjectCreatingEvent(object: $this->object([], $reference)));
			$this->assertFalse($event->isPropagationStopped());
		}
	}//end testAnObjectWithoutASchemaReferenceIsNotLookedUp()

	/**
	 * The action and gate listeners stay silent on a failed schema lookup and carry on without a schema.
	 */
	public function testAFailedLookupIsSilentForActionsAndTheGate(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->never())->method('warning');
		$this->executor->expects($this->never())->method('run');
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->actionListener()->handle($event);
		$this->gateListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAFailedLookupIsSilentForActionsAndTheGate()

	/**
	 * The lookup reads across tenants, on the object's own schema reference.
	 */
	public function testTheLookupIgnoresTenancy(): void {
		$this->schemaMapper->expects($this->exactly(4))->method('find')->with('golden', [], true, false)->willReturn(new Schema());

		$this->actionListener()->handle($this->update(['status' => 'draft'], ['status' => 'sent']));
		$this->gateListener()->handle($this->update(['status' => 'draft'], ['status' => 'sent']));
		$this->validationListener()->handle($this->update(['status' => 'draft'], ['status' => 'sent']));
		$this->initialListener()->handle(new ObjectCreatingEvent(object: $this->object([])));
	}//end testTheLookupIgnoresTenancy()

	/**
	 * The validation listener names the failed lookup in its warning.
	 */
	public function testAFailedLookupWarnsForValidation(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('Lifecycle listener could not load schema "golden": gone');
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->validationListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAFailedLookupWarnsForValidation()

	/**
	 * The initial-state listener names itself in its warning.
	 */
	public function testAFailedLookupWarnsForTheInitialState(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('Lifecycle initial-state listener could not load schema "golden": gone');

		$this->initialListener()->handle(new ObjectCreatingEvent(object: $this->object([])));
	}//end testAFailedLookupWarnsForTheInitialState()

	/**
	 * A schema that does not declare a usable lifecycle leaves every listener idle.
	 *
	 * @dataProvider idleConfigurations
	 */
	public function testAnUndeclaredLifecycleLeavesTheEditAlone(array $configuration): void {
		$this->schema($configuration);
		$this->executor->expects($this->never())->method('run');
		$this->sequenceService->expects($this->never())->method('provision');
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->actionListener()->handle($event);
		$this->gateListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(['status' => 'sent'], $this->data($event->getNewObject()));
	}//end testAnUndeclaredLifecycleLeavesTheEditAlone()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function idleConfigurations(): array {
		$chains = ['x-openregister-approval-chains' => ['pay' => ['transition' => 'submit', 'approvers' => [['role' => 'clerks', 'min' => 1]]]]];
		return [
			'nothing declared'    => [[]],
			'chains only'         => [$chains],
			'lifecycle not array' => [['x-openregister-lifecycle' => 'status'] + $chains],
			'no field'            => [['x-openregister-lifecycle' => ['transitions' => []]] + $chains],
			'empty transitions'   => [['x-openregister-lifecycle' => ['field' => 'status', 'transitions' => []]] + $chains],
			'no transitions'      => [['x-openregister-lifecycle' => ['field' => 'status']] + $chains],
		];
	}//end idleConfigurations()

	/**
	 * The action listener leaves a transition list that is not a list alone; a blank field name means no lifecycle.
	 */
	public function testActionsNeedAFieldAndATransitionList(): void {
		$event = $this->update(['status' => 'draft', '' => 'a'], ['status' => 'sent', '' => 'b']);
		$this->executor->expects($this->never())->method('run');

		$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'transitions' => 'submit']]);
		$this->actionListener()->handle($event);

		$blank = new Schema();
		$blank->setConfiguration(
			['x-openregister-lifecycle' => ['field' => '', 'transitions' => ['t' => ['from' => 'a', 'to' => 'b', 'actions' => [['action' => 'x']]]]]]
		);
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturn($blank);
		$this->schemaMapper = $mapper;
		$this->actionListener()->handle($event);

		$this->assertSame(['status' => 'sent', '' => 'b'], $this->data($event->getNewObject()));
	}//end testActionsNeedAFieldAndATransitionList()

	/**
	 * The gate does not read a chain when the lifecycle field did not move to a text value or no transition allows it.
	 *
	 * @dataProvider editsTheGateSkips
	 */
	public function testEditsThatDoNotMoveTheStateAreSkipped(array $old, array $new): void {
		$this->schema(
			$this->chain(['transition' => 'submit', 'approvers' => [['role' => 'clerks', 'min' => 1]]])
		);
		$this->sequenceMapper->expects($this->never())->method('findNewestForAnchor');
		$this->executor->expects($this->never())->method('run');
		$event = $this->update($old, $new);

		$this->gateListener()->handle($event);
		$this->actionListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame($new, $this->data($event->getNewObject()));
	}//end testEditsThatDoNotMoveTheStateAreSkipped()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function editsTheGateSkips(): array {
		return [
			'unchanged'       => [['status' => 'draft', 'n' => 1], ['status' => 'draft', 'n' => 2]],
			'not text'        => [['status' => 'draft'], ['status' => 7]],
			'no transition'   => [['status' => 'draft'], ['status' => 'elsewhere']],
			'wrong from'      => [['status' => 'old'], ['status' => 'sent']],
		];
	}//end editsTheGateSkips()

	/**
	 * The `property` alias names the field when `field` is absent, and `field` wins when both are there.
	 */
	public function testPropertyIsAnAliasForField(): void {
		$actions = [['action' => 'stamp']];
		$this->schema(
			[
				'x-openregister-lifecycle' => [
					'property' => 'status',
					'transitions' => ['submit' => ['from' => 'draft', 'to' => 'sent', 'actions' => $actions]],
				],
			]
		);
		$this->executor->expects($this->once())->method('run')
			->with($actions, ['id' => 'obj-1', 'status' => 'sent'], ['id' => 'obj-1', 'status' => 'draft'], 'submit')
			->willReturn(['id' => 'obj-1', 'status' => 'sent', 'stamped' => true]);

		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);
		$this->actionListener()->handle($event);

		$this->assertSame(['status' => 'sent', 'stamped' => true], $this->data($event->getNewObject()));
	}//end testPropertyIsAnAliasForField()

	/**
	 * `field` wins over `property` for every listener that reads the edit.
	 */
	public function testFieldWinsOverProperty(): void {
		$this->schema(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'property' => 'other',
					'transitions' => ['submit' => ['from' => 'draft', 'to' => 'sent']],
				],
			]
		);
		$event = $this->update(['status' => 'draft', 'other' => 'a'], ['status' => 'wrong', 'other' => 'b']);

		$this->validationListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('status', $event->getErrors()['field']);
	}//end testFieldWinsOverProperty()

	/**
	 * A stopped event never reaches the executor, and a transition without actions does nothing.
	 */
	public function testActionsSkipAStoppedEventAndAnEmptyList(): void {
		$this->schema($this->lifecycle(['transitions' => ['submit' => ['from' => 'draft', 'to' => 'sent', 'actions' => []]]]));
		$this->executor->expects($this->never())->method('run');

		$stopped = $this->update(['status' => 'draft'], ['status' => 'sent']);
		$stopped->stopPropagation();
		$this->actionListener()->handle($stopped);
		$this->actionListener()->handle($this->update(['status' => 'draft'], ['status' => 'sent']));
		$this->addToAssertionCount(1);
	}//end testActionsSkipAStoppedEventAndAnEmptyList()

	/**
	 * A transition whose `actions` is no list does nothing either.
	 */
	public function testActionsThatAreNoListAreSkipped(): void {
		$this->schema($this->lifecycle(['transitions' => ['submit' => ['from' => 'draft', 'to' => 'sent', 'actions' => 'stamp']]]));
		$this->executor->expects($this->never())->method('run');

		$this->actionListener()->handle($this->update(['status' => 'draft'], ['status' => 'sent']));
	}//end testActionsThatAreNoListAreSkipped()

	/**
	 * Actions run for the named action of a declared call, and the listener logs what it ran.
	 */
	public function testTheDeclaredActionsRunAndAreLogged(): void {
		$first  = ['action' => 'first'];
		$second = ['action' => 'second'];
		$this->schema(
			$this->lifecycle(
				[
					'transitions' => [
						'plain' => ['from' => 'draft', 'to' => 'sent', 'actions' => [$first]],
						'named' => ['from' => 'draft', 'to' => 'sent', 'actions' => [$first, $second]],
					],
				]
			)
		);
		$this->actions->declare(uuid: 'obj-1', action: 'named');
		$this->executor->method('run')->willReturnArgument(1);
		$this->logger->expects($this->once())->method('debug')
			->with('[LifecycleActionListener] executed 2 action(s) for transition "named".');

		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);
		$this->actionListener()->handle($event);

		$this->assertSame(['status' => 'sent'], $this->data($event->getNewObject()));
	}//end testTheDeclaredActionsRunAndAreLogged()

	/**
	 * A chain entry that is malformed, or gates another transition, is passed over.
	 */
	public function testChainEntriesThatDoNotGateTheTransitionArePassedOver(): void {
		$this->schema(
			$this->lifecycle() + [
				'x-openregister-approval-chains' => [
					0       => ['transition' => 'submit', 'approvers' => [['role' => 'clerks', 'min' => 1]]],
					'text'  => 'submit',
					'other' => ['transition' => 'archive', 'approvers' => [['role' => 'clerks', 'min' => 1]]],
				],
			]
		);
		$this->sequenceService->expects($this->never())->method('provision');
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->gateListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testChainEntriesThatDoNotGateTheTransitionArePassedOver()

	/**
	 * A chain that cannot be compiled fails closed, logged, and the next chain is not read.
	 */
	public function testAChainThatDoesNotCompileBlocksTheTransition(): void {
		$this->schema(
			$this->lifecycle() + [
				'x-openregister-approval-chains' => [
					'broken' => ['transition' => 'submit', 'approvers' => []],
					'next'   => ['transition' => 'submit', 'approvers' => [['role' => 'clerks', 'min' => 1]]],
				],
			]
		);
		$this->logger->expects($this->once())->method('error')
			->with('[ApprovalChainGateListener] chain "broken" could not be compiled for schema 9');
		$this->sequenceService->expects($this->never())->method('provision');
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->gateListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			['code' => 'approval-chain-misconfigured', 'message' => 'Approval chain "broken" is declared but not provisioned.'],
			$event->getErrors()
		);
	}//end testAChainThatDoesNotCompileBlocksTheTransition()

	/**
	 * Provisioning carries the requester, the anchor and a numeric register as an int.
	 */
	public function testAFirstAttemptProvisionsWithTheRequesterAndRegister(): void {
		$this->loginAs('alice');
		$this->schema($this->chain(['transition' => 'submit', 'approvers' => [['role' => 'clerks', 'min' => 1]]]));
		$captured = [];
		$this->sequenceService->expects($this->once())->method('provision')->willReturnCallback(
			function (...$arguments) use (&$captured): TaskSequence {
				$captured = $arguments;
				return $this->sequence(TaskSequence::STATUS_RUNNING);
			}
		);
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->gateListener()->handle($event);

		$this->assertSame('obj-1', $captured[1]);
		$this->assertSame('alice', $captured[2]);
		$this->assertNull($captured[3]);
		$this->assertSame(12, $captured[4]);
		$this->assertSame('pay', $captured[0]['name']);
		$this->assertSame(
			['code' => 'approval-chain-pending', 'message' => 'Transition requires approval via chain "pay".'],
			$event->getErrors()
		);
	}//end testAFirstAttemptProvisionsWithTheRequesterAndRegister()

	/**
	 * A register that is no number provisions without a register id, and no user without a requester.
	 */
	public function testAnAnonymousAttemptOnASlugRegisterProvisionsWithoutEither(): void {
		$this->schema($this->chain(['transition' => 'submit', 'approvers' => [['role' => 'clerks', 'min' => 1]]]));
		$captured = [];
		$this->sequenceService->method('provision')->willReturnCallback(
			function (...$arguments) use (&$captured): TaskSequence {
				$captured = $arguments;
				return $this->sequence(TaskSequence::STATUS_RUNNING);
			}
		);
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);
		$event->getNewObject()->setRegister('slug');

		$this->gateListener()->handle($event);

		$this->assertNull($captured[2]);
		$this->assertNull($captured[4]);
	}//end testAnAnonymousAttemptOnASlugRegisterProvisionsWithoutEither()

	/**
	 * The anchor lookup carries the object's uuid and the compiled template id.
	 */
	public function testARunningSequenceRefusesWithoutProvisioning(): void {
		$schema = $this->schema($this->chain(['transition' => 'submit', 'approvers' => [['role' => 'clerks', 'min' => 1]]]));
		$installer = new ApprovalChainAnnotationInstaller(logger: $this->logger);
		$template  = $installer->compile(schema: $schema, chainKey: 'pay');
		$this->sequenceMapper->expects($this->once())->method('findNewestForAnchor')
			->with('obj-1', (string)$template['templateId'])
			->willReturn($this->sequence(TaskSequence::STATUS_RUNNING));
		$this->sequenceService->expects($this->never())->method('provision');
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->gateListener()->handle($event);

		$this->assertSame(
			['code' => 'approval-chain-pending', 'message' => 'Approval chain "pay" is still pending a decision.'],
			$event->getErrors()
		);
		$this->assertTrue($event->isPropagationStopped());
	}//end testARunningSequenceRefusesWithoutProvisioning()

	/**
	 * A completed sequence releases one chain and the next chain of the transition is still read.
	 */
	public function testACompletedChainReleasesAndTheNextIsRead(): void {
		$approver = [['role' => 'clerks', 'min' => 1]];
		$this->schema(
			$this->lifecycle() + [
				'x-openregister-approval-chains' => [
					'first'  => ['transition' => 'submit', 'approvers' => $approver],
					'second' => ['transition' => 'submit', 'approvers' => $approver],
				],
			]
		);
		$this->sequenceMapper->expects($this->exactly(2))->method('findNewestForAnchor')->willReturnOnConsecutiveCalls(
			$this->sequence(TaskSequence::STATUS_COMPLETED),
			$this->sequence(TaskSequence::STATUS_RUNNING)
		);
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->gateListener()->handle($event);

		$this->assertSame('Approval chain "second" is still pending a decision.', $event->getErrors()['message']);
	}//end testACompletedChainReleasesAndTheNextIsRead()

	/**
	 * A rejected sequence opens a new one.
	 */
	public function testARejectedSequenceProvisionsAgain(): void {
		$this->schema($this->chain(['transition' => 'submit', 'approvers' => [['role' => 'clerks', 'min' => 1]]]));
		$this->sequenceMapper->method('findNewestForAnchor')->willReturn($this->sequence(TaskSequence::STATUS_REJECTED));
		$this->sequenceService->expects($this->once())->method('provision')->willReturn($this->sequence(TaskSequence::STATUS_RUNNING));
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->gateListener()->handle($event);

		$this->assertSame('Transition requires approval via chain "pay".', $event->getErrors()['message']);
	}//end testARejectedSequenceProvisionsAgain()

	/**
	 * The highest tier at or below the amount wins, the first one on a tie; it is numbered 1.
	 *
	 * @dataProvider highestTierAmounts
	 */
	public function testTheHighestTierAtOrBelowTheAmountIsProvisioned(int $amount, ?string $role): void {
		$this->schema($this->chain(['transition' => 'submit', 'amountField' => 'amount', 'approvers' => $this->approvers()]));
		$captured = [];
		$this->sequenceService->method('provision')->willReturnCallback(
			function (...$arguments) use (&$captured): TaskSequence {
				$captured = $arguments;
				return $this->sequence(TaskSequence::STATUS_RUNNING);
			}
		);

		$this->gateListener()->handle($this->update(['status' => 'draft'], ['status' => 'sent', 'amount' => $amount]));

		$this->assertArrayHasKey(3, $captured);
		if ($role === null) {
			$this->assertNull($captured[3]);
			return;
		}

		$this->assertCount(1, $captured[3]);
		$this->assertSame($role, $captured[3][0]['role']);
		$this->assertSame(1, $captured[3][0]['order']);
	}//end testTheHighestTierAtOrBelowTheAmountIsProvisioned()

	/**
	 * @return array<string, array{0: int, 1: string|null}>
	 */
	public static function highestTierAmounts(): array {
		return [
			'below every tier' => [99, null],
			'on the lowest'    => [100, 'clerks'],
			'between'          => [499, 'clerks'],
			'on a tie'         => [500, 'heads'],
			'above'            => [9000, 'heads'],
		];
	}//end highestTierAmounts()

	/**
	 * Cumulative tiers keep every tier at or below the amount, lowest first, numbered from 1; none means no approval.
	 */
	public function testCumulativeTiers(): void {
		$approvers = array_reverse($this->approvers());
		$this->schema($this->chain(['transition' => 'submit', 'amountField' => 'amount', 'tiers' => 'cumulative', 'approvers' => $approvers]));
		$captured = [];
		$this->sequenceService->expects($this->once())->method('provision')->willReturnCallback(
			function (...$arguments) use (&$captured): TaskSequence {
				$captured = $arguments;
				return $this->sequence(TaskSequence::STATUS_RUNNING);
			}
		);

		$below = $this->update(['status' => 'draft'], ['status' => 'sent', 'amount' => 50]);
		$this->gateListener()->handle($below);
		$this->assertFalse($below->isPropagationStopped());

		$this->gateListener()->handle($this->update(['status' => 'draft'], ['status' => 'sent', 'amount' => 500]));
		$this->assertSame(['clerks', 'heads-too', 'heads'], array_column($captured[3], 'role'));
		$this->assertSame([1, 2, 3], array_column($captured[3], 'order'));
	}//end testCumulativeTiers()

	/**
	 * A create on a schema without a lifecycle block, or without a field or an initial, stays as it came.
	 *
	 * @dataProvider createsLeftAlone
	 */
	public function testACreateWithoutADeclaredInitialIsLeftAlone(array $configuration): void {
		$this->schema($configuration);
		$event = new ObjectCreatingEvent(object: $this->object(['status' => 'free']));

		$this->initialListener()->handle($event);

		$this->assertSame(['status' => 'free'], $this->data($event->getObject()));
		$this->assertFalse($event->isPropagationStopped());
	}//end testACreateWithoutADeclaredInitialIsLeftAlone()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function createsLeftAlone(): array {
		return [
			'no block'    => [[]],
			'no array'    => [['x-openregister-lifecycle' => 'status']],
			'no field'    => [['x-openregister-lifecycle' => ['initial' => 'new']]],
			'no initial'  => [['x-openregister-lifecycle' => ['field' => 'status']]],
		];
	}//end createsLeftAlone()

	/**
	 * The `property` alias reads for the initial state too; an initial that is neither text nor a map fills nothing.
	 */
	public function testAnInitialOfAnotherTypeFillsNothing(): void {
		$this->schema(['x-openregister-lifecycle' => ['property' => 'status', 'initial' => 7]]);
		$event = new ObjectCreatingEvent(object: $this->object([]));

		$this->initialListener()->handle($event);

		$this->assertSame([], $this->data($event->getObject()));
	}//end testAnInitialOfAnotherTypeFillsNothing()

	/**
	 * A half reference, as a map or a token, resolves nothing and reads no reference.
	 *
	 * @dataProvider halfReferences
	 */
	public function testAHalfReferenceResolvesNothing(mixed $initial): void {
		$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => $initial]]);
		$this->references->expects($this->never())->method('resolveAll');
		$this->logger->expects($this->never())->method('warning');
		$event = new ObjectCreatingEvent(object: $this->object([]));

		$this->initialListener()->handle($event);

		$this->assertSame([], $this->data($event->getObject()));
	}//end testAHalfReferenceResolvesNothing()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function halfReferences(): array {
		return [
			'map without from'  => [['field' => 'x']],
			'map without field' => [['from' => 'x']],
			'token without dot' => ['@ref.case'],
			'empty token'       => ['@ref.'],
		];
	}//end halfReferences()

	/**
	 * A reference fills the field from the related object, on the object's own register and organisation.
	 *
	 * @dataProvider fullReferences
	 */
	public function testAReferenceFillsTheField(mixed $initial): void {
		$this->schema(
			[
				'x-openregister-lifecycle' => ['field' => 'status', 'initial' => $initial],
				'x-openregister-references' => ['caseType' => ['via' => 'type'], 'unused' => ['via' => 'other']],
			]
		);
		$this->references->expects($this->once())->method('resolveAll')
			->with(['type' => 't1', 'id' => 'obj-1'], ['caseType' => ['via' => 'type']], '12', 'org-1')
			->willReturn(['caseType' => ['start.at' => 42]]);
		$event = new ObjectCreatingEvent(object: $this->object(['type' => 't1']));

		$this->initialListener()->handle($event);

		$this->assertSame(['type' => 't1', 'status' => '42'], $this->data($event->getObject()));
	}//end testAReferenceFillsTheField()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function fullReferences(): array {
		return [
			'map'   => [['from' => 'caseType', 'field' => 'start.at']],
			'token' => ['@ref.caseType.start.at'],
		];
	}//end fullReferences()

	/**
	 * An undeclared reference warns; so does an empty references block.
	 */
	public function testAnUndeclaredReferenceWarns(): void {
		$this->schema(
			[
				'x-openregister-lifecycle' => ['field' => 'status', 'initial' => '@ref.nope.x'],
				'x-openregister-references' => [],
			]
		);
		$this->logger->expects($this->once())->method('warning')
			->with('Lifecycle dynamic initial: reference "nope" is not declared on the schema.');
		$this->references->expects($this->never())->method('resolveAll');

		$this->initialListener()->handle(new ObjectCreatingEvent(object: $this->object([])));
	}//end testAnUndeclaredReferenceWarns()

	/**
	 * A reference that resolves without the field warns, and one with an empty value does not.
	 */
	public function testAReferenceWithoutItsField(): void {
		$this->schema(
			[
				'x-openregister-lifecycle' => ['field' => 'status', 'initial' => ['from' => 'r', 'field' => 'x']],
				'x-openregister-references' => ['r' => ['via' => 'type']],
			]
		);
		$this->references->method('resolveAll')->willReturnOnConsecutiveCalls(['r' => ['y' => 1]], ['r' => 'text'], ['r' => ['x' => '']], ['r' => ['x' => null]]);
		$this->logger->expects($this->exactly(2))->method('warning')
			->with('Lifecycle dynamic initial: reference "r" resolved no "x" field.');

		foreach ([1, 2, 3, 4] as $_) {
			$event = new ObjectCreatingEvent(object: $this->object([]));
			$this->initialListener()->handle($event);
			$this->assertSame([], $this->data($event->getObject()));
		}
	}//end testAReferenceWithoutItsField()

	/**
	 * A caller's state beside an unresolved initial is refused, with the whole error.
	 */
	public function testAChosenStateIsRefusedWithTheWholeError(): void {
		$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'new', 'createStates' => ['kept', 3, '']]]);
		$event = new ObjectCreatingEvent(object: $this->object(['status' => ['not', 'text']]));

		$this->initialListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => 'lifecycle-invalid-initial',
				'field' => 'status',
				'attempted' => ['not', 'text'],
				'initial' => 'new',
				'allowed' => ['kept'],
				'message' => 'A new object starts in "new"; "a non-text value" cannot be set when it is created.',
			],
			$event->getErrors()
		);
	}//end testAChosenStateIsRefusedWithTheWholeError()

	/**
	 * An empty chosen state counts as not chosen.
	 */
	public function testAnEmptyStateIsFilled(): void {
		$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'new']]);
		$event = new ObjectCreatingEvent(object: $this->object(['status' => '']));

		$this->initialListener()->handle($event);

		$this->assertSame(['status' => 'new'], $this->data($event->getObject()));
		$this->assertFalse($event->isPropagationStopped());
	}//end testAnEmptyStateIsFilled()

	/**
	 * A create state list that is a map allows nothing extra.
	 */
	public function testACreateStateMapAllowsNothing(): void {
		$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'new', 'createStates' => ['a' => 'kept']]]);
		$event = new ObjectCreatingEvent(object: $this->object(['status' => 'kept']));

		$this->initialListener()->handle($event);

		$this->assertSame([], $event->getErrors()['allowed']);
		$this->assertSame('A new object starts in "new"; "kept" cannot be set when it is created.', $event->getErrors()['message']);
	}//end testACreateStateMapAllowsNothing()

	/**
	 * Validation stays out of the edit that leaves the state as it was, and of a schema without a block.
	 */
	public function testValidationIgnoresAnUnchangedStateAndAnUndeclaredLifecycle(): void {
		$this->schema($this->lifecycle());
		$same = $this->update(['status' => 'draft', 'n' => 1], ['status' => 'draft', 'n' => 2]);
		$this->validationListener()->handle($same);
		$this->assertFalse($same->isPropagationStopped());

		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturn(new Schema());
		$this->schemaMapper = $mapper;
		$open = $this->update(['status' => 'draft'], ['status' => 'x']);
		$this->validationListener()->handle($open);
		$this->assertFalse($open->isPropagationStopped());
	}//end testValidationIgnoresAnUnchangedStateAndAnUndeclaredLifecycle()

	/**
	 * A state that is not non-empty text is refused before any transition is read.
	 *
	 * @dataProvider invalidStates
	 */
	public function testAnInvalidStateValueIsRefused(mixed $value): void {
		$this->schema($this->lifecycle());
		$event = $this->update(['status' => 'draft'], ['status' => $value]);

		$this->validationListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => 'lifecycle-invalid-value',
				'field' => 'status',
				'attempted' => $value,
				'message' => 'Lifecycle field "status" must be a non-empty string.',
			],
			$event->getErrors()
		);
	}//end testAnInvalidStateValueIsRefused()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalidStates(): array {
		return ['empty' => [''], 'null' => [null], 'number' => [3], 'list' => [['a']]];
	}//end invalidStates()

	/**
	 * Without transitions the app owns its states: any text value passes.
	 */
	public function testNoTransitionsMeansTheAppOwnsTheStates(): void {
		$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'new']]);
		$event = $this->update(['status' => 'draft'], ['status' => 'anything']);

		$this->validationListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testNoTransitionsMeansTheAppOwnsTheStates()

	/**
	 * A move no transition allows is refused with the whole error.
	 */
	public function testAMoveNoTransitionAllowsIsRefused(): void {
		$this->schema($this->lifecycle());
		$event = $this->update(['status' => 'draft'], ['status' => 'elsewhere']);

		$this->validationListener()->handle($event);

		$this->assertSame(
			[
				'code' => 'lifecycle-invalid-transition',
				'field' => 'status',
				'from' => 'draft',
				'attempted' => 'elsewhere',
				'message' => 'No transition allows moving "status" from "draft" to "elsewhere".',
			],
			$event->getErrors()
		);
	}//end testAMoveNoTransitionAllowsIsRefused()

	/**
	 * A seed's write of declared state skips the transition check, only as the system and only for that write.
	 */
	public function testADeclaredStateWriteSkipsTheCheckOnlyAsTheSystem(): void {
		$this->schema($this->lifecycle());
		$listener = $this->validationListener();

		$asSystem = $this->update(['status' => 'draft'], ['status' => 'elsewhere']);
		$this->actions->enterDeclaredState(uuid: 'obj-1');
		SystemOperationContext::run(static fn () => $listener->handle($asSystem));
		$this->assertFalse($asSystem->isPropagationStopped());

		$asUser = $this->update(['status' => 'draft'], ['status' => 'elsewhere']);
		$listener->handle($asUser);
		$this->assertTrue($asUser->isPropagationStopped());

		$this->actions->leaveDeclaredState(uuid: 'obj-1');
		$undeclared = $this->update(['status' => 'draft'], ['status' => 'elsewhere']);
		SystemOperationContext::run(static fn () => $listener->handle($undeclared));
		$this->assertTrue($undeclared->isPropagationStopped());
	}//end testADeclaredStateWriteSkipsTheCheckOnlyAsTheSystem()

	/**
	 * An `authorization` list is judged for the caller on the schema, before the guard; an empty list is no gate.
	 */
	public function testAuthorizationComesFirstAndCarriesTheCaller(): void {
		$this->loginAs('alice');
		$schema = $this->schema(
			$this->lifecycle(['transitions' => ['submit' => ['from' => 'draft', 'to' => 'sent', 'authorization' => ['admins'], 'requires' => 'tag']]])
		);
		$this->permission->expects($this->once())->method('isTransitionAuthorized')
			->with(['admins'], 'alice', $schema)->willReturn(false);
		$this->guards->expects($this->never())->method('get');
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->validationListener()->handle($event);

		$this->assertSame(
			[
				'code' => 'lifecycle-transition-unauthorized',
				'field' => 'status',
				'action' => 'submit',
				'message' => 'You are not authorized to perform transition "submit" on "status".',
			],
			$event->getErrors()
		);
	}//end testAuthorizationComesFirstAndCarriesTheCaller()

	/**
	 * No user reaches the check as null; an empty list skips it, and a list that is no list too.
	 */
	public function testAuthorizationWithoutAUserAndWithoutAList(): void {
		$this->schema(
			$this->lifecycle(
				[
					'transitions' => [
						'submit' => ['from' => 'draft', 'to' => 'sent', 'authorization' => ['admins']],
						'empty'  => ['from' => 'a', 'to' => 'b', 'authorization' => []],
						'text'   => ['from' => 'c', 'to' => 'd', 'authorization' => 'admins'],
					],
				]
			)
		);
		$this->permission->expects($this->once())->method('isTransitionAuthorized')
			->with(['admins'], null, $this->anything())->willReturn(true);

		foreach ([['draft', 'sent'], ['a', 'b'], ['c', 'd']] as [$from, $to]) {
			$event = $this->update(['status' => $from], ['status' => $to]);
			$this->validationListener()->handle($event);
			$this->assertFalse($event->isPropagationStopped());
		}
	}//end testAuthorizationWithoutAUserAndWithoutAList()

	/**
	 * A condition on a schema without a slug still judges the edit, and records nothing.
	 */
	public function testAConditionWithoutASlugRecordsNothing(): void {
		$schema = $this->schema(
			$this->lifecycle(['transitions' => ['submit' => ['from' => 'draft', 'to' => 'sent', 'condition' => ['==' => [1, 1]]]]])
		);
		$schema->setSlug(null);
		$this->ruleRuns->expects($this->never())->method('record');
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->validationListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAConditionWithoutASlugRecordsNothing()

	/**
	 * A condition that ran is recorded with its verdict on the object it judged; one that is switched off is not.
	 */
	public function testAConditionRunIsRecorded(): void {
		$condition = ['==' => [['var' => 'object.amount'], 1]];
		$this->schema(
			$this->lifecycle(
				[
					'transitions' => [
						'submit' => ['from' => 'draft', 'to' => 'sent', 'condition' => $condition],
						'quiet'  => ['from' => 'draft', 'to' => 'muted', 'condition' => $condition, 'enabled' => false],
					],
				]
			)
		);
		$records = [];
		$this->ruleRuns->method('record')->willReturnCallback(
			static function (RuleRunSubject $subject, RuleTrace $trace) use (&$records): void {
				$records[] = [$subject->ruleId, $subject->schemaSlug, $trace, $subject->objectUuid, $subject->registerSlug];
			}
		);

		$this->validationListener()->handle($this->update(['status' => 'draft'], ['status' => 'muted', 'amount' => 2]));
		$this->assertSame([], $records);

		$event = $this->update(['status' => 'draft'], ['status' => 'sent', 'amount' => 2]);
		$this->validationListener()->handle($event);

		$this->assertCount(1, $records);
		[$ruleId, $slug, $trace, $uuid, $register] = $records[0];
		$this->assertStringContainsString('golden', $ruleId);
		$this->assertStringContainsString('submit', $ruleId);
		$this->assertSame('golden', $slug);
		$this->assertSame('obj-1', $uuid);
		$this->assertSame('12', $register);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('refused', $trace->jsonSerialize()['verdict']);
	}//end testAConditionRunIsRecorded()

	/**
	 * An unchanged state on a self-loop, and a number that equals a declared target, run no actions and open no approval.
	 *
	 * @dataProvider editsThatLookLikeATransition
	 */
	public function testOnlyAChangeToTextIsATransition(array $old, array $new): void {
		$this->schema(
			$this->lifecycle(
				[
					'transitions' => [
						'stay'   => ['from' => 'draft', 'to' => 'draft', 'actions' => [['action' => 'x']]],
						'number' => ['from' => 'draft', 'to' => '7', 'actions' => [['action' => 'x']]],
					],
				]
			) + ['x-openregister-approval-chains' => [
				'a' => ['transition' => 'stay', 'approvers' => [['role' => 'clerks', 'min' => 1]]],
				'b' => ['transition' => 'number', 'approvers' => [['role' => 'clerks', 'min' => 1]]],
			],
			]
		);
		$this->executor->expects($this->never())->method('run');
		$this->sequenceMapper->expects($this->never())->method('findNewestForAnchor');
		$event = $this->update($old, $new);

		$this->gateListener()->handle($event);
		$this->actionListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testOnlyAChangeToTextIsATransition()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function editsThatLookLikeATransition(): array {
		return [
			'self loop' => [['status' => 'draft', 'n' => 1], ['status' => 'draft', 'n' => 2]],
			'number'    => [['status' => 'draft'], ['status' => 7]],
		];
	}//end editsThatLookLikeATransition()

	/**
	 * A `requires` guard that is no text, or empty, runs nothing.
	 */
	public function testARequiresThatIsNoTagRunsNoGuard(): void {
		$this->schema(
			$this->lifecycle(
				[
					'transitions' => [
						'blank' => ['from' => 'a', 'to' => 'b', 'requires' => ''],
						'list'  => ['from' => 'c', 'to' => 'd', 'requires' => ['tag']],
					],
				]
			)
		);
		$this->guards->expects($this->never())->method('get');

		foreach ([['a', 'b'], ['c', 'd']] as [$from, $to]) {
			$event = $this->update(['status' => $from], ['status' => $to]);
			$this->validationListener()->handle($event);
			$this->assertFalse($event->isPropagationStopped());
		}
	}//end testARequiresThatIsNoTagRunsNoGuard()

	/**
	 * A guard that asks for the stored object sees it, with the action and the caller; its message is the refusal.
	 */
	public function testAGuardSeesTheStoredObjectAndRefuses(): void {
		$this->loginAs('alice');
		$this->schema($this->lifecycle(['transitions' => ['submit' => ['from' => 'draft', 'to' => 'sent', 'requires' => 'tag']]]));
		$seen  = [];
		$guard = new class($seen) implements LifecycleTransitionGuardInterface {
			public function __construct(private array &$seen) {
			}

			public function check(array $object, string $action, string $userId): GuardResult {
				return GuardResult::allow();
			}

			public function checkTransition(LifecycleGuardContext $context): GuardResult {
				$this->seen = [$context->object, $context->stored->getObject(), $context->action, $context->userId];
				return GuardResult::deny('Not today.');
			}
		};
		$this->guards->method('get')->willReturn($guard);
		$event = $this->update(['status' => 'draft'], ['status' => 'sent']);

		$this->validationListener()->handle($event);

		$this->assertSame([['id' => 'obj-1', 'status' => 'sent'], ['id' => 'obj-1', 'status' => 'draft'], 'submit', 'alice'], $seen);
		$this->assertSame(
			['code' => 'lifecycle-guard-denied', 'field' => 'status', 'action' => 'submit', 'message' => 'Not today.'],
			$event->getErrors()
		);
	}//end testAGuardSeesTheStoredObjectAndRefuses()
}//end class
