<?php

/**
 * StateFieldRuleListener save-path enforcement tests.
 *
 * These are the change's headline scenarios, asserted on the door every write
 * goes through rather than on the resolver that decides:
 *  - closing without an outcome is refused with 422 naming `outcome` and the
 *    state, and the object keeps its old status;
 *  - a closed object's decision is read only for handlers;
 *  - a field is required because of another field's value, and is not below
 *    the threshold;
 *  - a state's entry condition refuses the move;
 *  - a schema with no `states` block is entirely unaffected.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\StateFieldRuleListener;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Listener\StateFieldRuleListener
 */
class StateFieldRuleListenerTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RuleRunRecorder&MockObject $ruleRuns;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->ruleRuns = $this->createMock(RuleRunRecorder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}

	/**
	 * A listener whose caller sits in the given groups, over the given schema.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation to install.
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return StateFieldRuleListener The listener.
	 */
	private function listenerFor(array $annotation, array $groups = []): StateFieldRuleListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);

		// A real Schema entity, not a double: `getSlug()` and `getConfiguration()`
		// are magic accessors on the Nextcloud Entity base class, so a mock
		// cannot be told about them — and a double that COULD would be a test
		// that passes against a class no longer shaped this way.
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('zaak');
		$schema->setConfiguration(['x-openregister-lifecycle' => $annotation]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$dialect = new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($this->userSession)));
		$resolver = new StateFieldRuleResolver($this->userSession, $this->groupManager, $dialect);

		return new StateFieldRuleListener(
			$this->schemaMapper,
			$resolver,
			new StateConditionEvaluator($resolver, $dialect, new ConditionTracer($dialect)),
			$this->ruleRuns,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * An object entity carrying the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('11111111-2222-3333-4444-555555555555');
		$entity->setSchema('7');
		$entity->setRegister('1');
		$entity->setObject($data);

		return $entity;
	}

	/**
	 * The annotation the scenarios are written against.
	 *
	 * @return array<string, mixed> The lifecycle annotation.
	 */
	private function annotation(): array {
		return [
			'field' => 'status',
			'initial' => 'open',
			'transitions' => ['sluiten' => ['from' => ['open'], 'to' => 'closed']],
			'states' => [
				'open' => [
					'fields' => [
						'required' => [
							['fields' => ['motivering'], 'when' => ['>' => [['var' => 'object.bedrag'], 50000]]],
						],
					],
				],
				'closed' => [
					'fields' => [
						'required' => [['fields' => ['outcome']]],
						'readOnly' => [['fields' => ['decision'], 'groups' => ['handlers']]],
					],
				],
			],
		];
	}

	/**
	 * @return void
	 */
	public function testClosingWithoutAnOutcomeIsRefused(): void {
		$listener = $this->listenerFor($this->annotation());
		$old = $this->entity(['status' => 'open']);
		$new = $this->entity(['status' => 'closed']);
		$event = new ObjectUpdatingEvent($new, $old);

		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		$this->assertSame(StateFieldRuleListener::CODE_REQUIRED, $errors['code']);
		$this->assertSame('outcome', $errors['field']);
		$this->assertSame('closed', $errors['state']);
		// The object stays open: the write never lands, so the stored entity
		// still carries the old value.
		$this->assertSame('open', $old->getObject()['status']);
	}

	/**
	 * @return void
	 */
	public function testClosingWithAnOutcomeIsAllowed(): void {
		$listener = $this->listenerFor($this->annotation());
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed', 'outcome' => 'toegekend']),
			$this->entity(['status' => 'open'])
		);

		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}

	/**
	 * @return void
	 */
	public function testAClosedObjectsDecisionIsReadOnlyForHandlers(): void {
		$listener = $this->listenerFor($this->annotation(), ['handlers']);
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed', 'outcome' => 'toegekend', 'decision' => 'afgewezen']),
			$this->entity(['status' => 'closed', 'outcome' => 'toegekend', 'decision' => 'toegekend'])
		);

		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		$this->assertSame(StateFieldRuleListener::CODE_READ_ONLY, $errors['code']);
		$this->assertSame('decision', $errors['field']);
	}

	/**
	 * @return void
	 */
	public function testANonHandlerMayStillChangeTheDecision(): void {
		$listener = $this->listenerFor($this->annotation(), ['frontdesk']);
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed', 'outcome' => 'toegekend', 'decision' => 'afgewezen']),
			$this->entity(['status' => 'closed', 'outcome' => 'toegekend', 'decision' => 'toegekend'])
		);

		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * @return void
	 */
	public function testResubmittingTheSameValueIsNotAChange(): void {
		$listener = $this->listenerFor($this->annotation(), ['handlers']);
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed', 'outcome' => 'toegekend', 'decision' => 'toegekend']),
			$this->entity(['status' => 'closed', 'outcome' => 'toegekend', 'decision' => 'toegekend'])
		);

		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * @return void
	 */
	public function testAFieldBecomesRequiredBecauseOfAValue(): void {
		$listener = $this->listenerFor($this->annotation());
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'open', 'bedrag' => 60000]),
			$this->entity(['status' => 'open', 'bedrag' => 400])
		);

		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('motivering', $event->getErrors()['field']);
	}

	/**
	 * @return void
	 */
	public function testTheSameFieldIsNotRequiredBelowTheThreshold(): void {
		$listener = $this->listenerFor($this->annotation());
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'open', 'bedrag' => 400]),
			$this->entity(['status' => 'open', 'bedrag' => 100])
		);

		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * A create into a state whose rules demand a field is refused too.
	 *
	 * @return void
	 */
	public function testACreateIntoARequiringStateIsRefused(): void {
		$listener = $this->listenerFor($this->annotation());
		$event = new ObjectCreatingEvent($this->entity(['status' => 'closed']));

		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('outcome', $event->getErrors()['field']);
	}

	/**
	 * @return void
	 */
	public function testAnEntryConditionRefusesTheMove(): void {
		$annotation = $this->annotation();
		$annotation['states']['closed']['entry'] = ['!!' => ['var' => 'object.besluit']];

		$listener = $this->listenerFor($annotation);
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed', 'outcome' => 'toegekend']),
			$this->entity(['status' => 'open'])
		);

		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(StateConditionEvaluator::CODE_ENTRY, $event->getErrors()['code']);
	}

	/**
	 * An empty string is not an answer; `0` and `false` are.
	 *
	 * @return void
	 */
	public function testAnEmptyStringDoesNotSatisfyARequiredField(): void {
		$listener = $this->listenerFor($this->annotation());
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed', 'outcome' => '   ']),
			$this->entity(['status' => 'open'])
		);

		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}

	/**
	 * @return void
	 */
	public function testAFalseValueSatisfiesARequiredField(): void {
		$listener = $this->listenerFor($this->annotation());
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed', 'outcome' => false]),
			$this->entity(['status' => 'open'])
		);

		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * @return void
	 */
	public function testASchemaWithoutStatesIsUnaffected(): void {
		$listener = $this->listenerFor([
			'field' => 'status',
			'initial' => 'open',
			'transitions' => ['sluiten' => ['from' => ['open'], 'to' => 'closed']],
		]);
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed']),
			$this->entity(['status' => 'open'])
		);

		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * The refusal reaches the rule run log, so the operator surface can answer
	 * "why was my save refused" for this rule kind too.
	 *
	 * @return void
	 */
	public function testARefusalIsRecordedAgainstTheStatesRule(): void {
		$this->ruleRuns->expects($this->once())
			->method('record')
			->with(
				$this->stringContains('stateFieldRule'),
				'zaak',
				$this->anything(),
				$this->anything(),
				$this->anything()
			);

		$listener = $this->listenerFor($this->annotation());
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'closed']),
			$this->entity(['status' => 'open'])
		);

		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}
}
