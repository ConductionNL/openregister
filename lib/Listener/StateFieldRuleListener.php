<?php

/**
 * OpenRegister StateFieldRuleListener
 *
 * Refuses a write that leaves a field the state requires empty, that changes a
 * field the state freezes, or that fails a state's entry or exit condition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\Support\StateFieldWrite;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolverInterface;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRulesInterface;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Listener\Support\StateFieldRulePlatform;

/**
 * The save half of a state's field rules.
 *
 * The render half tells a form what to show; this half makes it true. A form
 * that ignores `@self.fieldRules`, an integration that never asked for it and a
 * script posting JSON all meet the same refusal here, which is the whole reason
 * the rule lives on the schema rather than in each app's form code.
 *
 * Which state each rule is read from is deliberate and different per kind:
 *
 * - `required` is read from the state the object ENDS in. A field required in
 *   `closed` need not be filled while the object is `open`, which is the point
 *   of a per-state rule (design D-2).
 * - `readOnly` and `hidden` are read from the state the object is IN when the
 *   edit is made. "Frozen while it sits in this state" is what an author means,
 *   and it is also the state whose rules the editing form was rendered under.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The listener is the save path's one
 *   field-rule gate, and every type it names is one step of that gate: the two events it
 *   answers, the schema it reads the declaration from, the two evaluators that decide, and
 *   the four rule-engine types that record the refusal. The same count and the same reason
 *   as LifecycleValidationListener beside it.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class StateFieldRuleListener implements IEventListener {

	/**
	 * The refusal code an empty required field earns.
	 */
	public const CODE_REQUIRED = 'state-field-required';

	/**
	 * The refusal code a changed read-only field earns.
	 */
	public const CODE_READ_ONLY = 'state-field-read-only';

	/**
	 * The refusal code a written hidden field earns.
	 */
	public const CODE_HIDDEN = 'state-field-hidden';

	/**
	 * Resolves the object's schema.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Records a refusal for the rule run log.
	 *
	 * @var RuleRunRecorderInterface
	 */
	private readonly RuleRunRecorderInterface $ruleRuns;

	/**
	 * PSR logger for lookup failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param StateFieldRulePlatform $platform Schema lookup, rule-run recorder and logger.
	 * @param StateFieldRuleResolverInterface  $resolver        Resolves the rules that apply to this object and user.
	 * @param StateConditionEvaluatorInterface $stateConditions Evaluates a state's entry and exit conditions.
	 *
	 * @return void
	 */
	public function __construct(
		StateFieldRulePlatform $platform,
		private readonly StateFieldRuleResolverInterface $resolver,
		private readonly StateConditionEvaluatorInterface $stateConditions,
	) {
		$this->schemaMapper = $platform->schemaMapper;
		$this->ruleRuns = $platform->ruleRuns;
		$this->logger = $platform->logger;
	}//end __construct()

	/**
	 * Enforce the resulting state's field rules before the write lands.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->enforce(event: $event, newObject: $event->getObject(), oldObject: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->enforce(event: $event, newObject: $event->getNewObject(), oldObject: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Run every rule the two states carry, and stop the event on the first refusal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event to stop when a rule refuses.
	 * @param ObjectEntity $newObject The object as it would be saved.
	 * @param ObjectEntity|null $oldObject The object as currently stored, null on a create.
	 *
	 * @return void
	 */
	private function enforce(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $newObject, ?ObjectEntity $oldObject): void {
		$schema = $this->loadSchema(object: $newObject);
		if ($schema === null) {
			return;
		}

		$refusal = $this->refusalForSave(schema: $schema, newObject: $newObject, oldObject: $oldObject);
		if ($refusal === null) {
			return;
		}

		$this->record(object: $newObject, schema: $schema, refusal: $refusal);
		$event->setErrors($refusal);
		$event->stopPropagation();
	}//end enforce()

	/**
	 * The refusal this save earns under the schema's state rules, or null.
	 *
	 * @param Schema $schema The object's schema.
	 * @param ObjectEntity $newObject The object as it would be saved.
	 * @param ObjectEntity|null $oldObject The object as currently stored, null on a create.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function refusalForSave(Schema $schema, ObjectEntity $newObject, ?ObjectEntity $oldObject): ?array {
		$annotation = $this->stateAnnotationOf(schema: $schema);
		if ($annotation === null) {
			return null;
		}

		return $this->refusalOf(write: $this->writeOf(annotation: $annotation, newObject: $newObject, oldObject: $oldObject));
	}//end refusalForSave()

	/**
	 * The schema's lifecycle annotation when it declares states, else null.
	 *
	 * @param Schema $schema The object's schema.
	 *
	 * @return array<string, mixed>|null The annotation.
	 */
	private function stateAnnotationOf(Schema $schema): ?array {
		// No state block: nothing this listener can say about the write.
		// Every schema saved before this change takes this exit.
		$annotation = $this->resolver->annotationOf(schema: $schema);
		if ($annotation === null || isset($annotation['states']) === false) {
			return null;
		}

		return $annotation;
	}//end stateAnnotationOf()

	/**
	 * The save as the rules read it: both data sets and both states.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param ObjectEntity $newObject The object as it would be saved.
	 * @param ObjectEntity|null $oldObject The object as currently stored, null on a create.
	 *
	 * @return StateFieldWrite The write.
	 */
	private function writeOf(array $annotation, ObjectEntity $newObject, ?ObjectEntity $oldObject): StateFieldWrite {
		$newData = ($newObject->getObject() ?? []);
		$toState = $this->resolver->stateOf(annotation: $annotation, data: $newData);
		if ($oldObject === null) {
			return new StateFieldWrite(annotation: $annotation, newData: $newData, oldData: null, toState: $toState, fromState: null);
		}

		$oldData = ($oldObject->getObject() ?? []);
		$fromState = $this->resolver->stateOf(annotation: $annotation, data: $oldData);
		return new StateFieldWrite(annotation: $annotation, newData: $newData, oldData: $oldData, toState: $toState, fromState: $fromState);
	}//end writeOf()

	/**
	 * The refusal of the states' conditions, else of their field rules, else null.
	 *
	 * @param StateFieldWrite $write The save.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function refusalOf(StateFieldWrite $write): ?array {
		$refusal = $this->stateConditions->refusal(
			annotation: $write->annotation,
			newData: $write->newData,
			from: $write->fromState,
			to: $write->toState
		);
		if ($refusal !== null) {
			return $refusal;
		}

		return ($this->frozenRefusal(write: $write) ?? $this->requiredRefusal(write: $write));
	}//end refusalOf()

	/**
	 * The refusal a change to a frozen or hidden field earns, read from the state the edit was made in.
	 *
	 * @param StateFieldWrite $write The save.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function frozenRefusal(StateFieldWrite $write): ?array {
		$state = $write->editState();
		if ($write->isCreate() === true || $state === null) {
			return null;
		}

		$rules = $this->resolver->resolveFromAnnotation(annotation: $write->annotation, data: $write->oldData(), state: $state);
		return ($this->readOnlyRefusal(rules: $rules, write: $write) ?? $this->hiddenRefusal(rules: $rules, write: $write));
	}//end frozenRefusal()

	/**
	 * The refusal a changed read-only field earns.
	 *
	 * @param StateFieldRulesInterface $rules The rules of the state the edit was made in.
	 * @param StateFieldWrite $write The save.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function readOnlyRefusal(StateFieldRulesInterface $rules, StateFieldWrite $write): ?array {
		$state = (string)$write->editState();
		foreach ($rules->getReadOnly() as $property) {
			if ($write->changes(property: $property) === true) {
				return [
					'code' => self::CODE_READ_ONLY,
					'field' => $property,
					'state' => $state,
					'message' => ($rules->messageFor(property: $property) ?? sprintf('Field "%s" is read only in state "%s".', $property, $state)),
				];
			}
		}

		return null;
	}//end readOnlyRefusal()

	/**
	 * The refusal a written hidden field earns.
	 *
	 * @param StateFieldRulesInterface $rules The rules of the state the edit was made in.
	 * @param StateFieldWrite $write The save.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function hiddenRefusal(StateFieldRulesInterface $rules, StateFieldWrite $write): ?array {
		$state = (string)$write->editState();
		foreach ($rules->getHidden() as $property) {
			if ($write->changes(property: $property) === true) {
				return [
					'code' => self::CODE_HIDDEN,
					'field' => $property,
					'state' => $state,
					'message' => sprintf('Field "%s" cannot be written in state "%s".', $property, $state),
				];
			}
		}

		return null;
	}//end hiddenRefusal()

	/**
	 * The refusal an empty required field earns, read from the state the object ends in.
	 *
	 * @param StateFieldWrite $write The save.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function requiredRefusal(StateFieldWrite $write): ?array {
		$state = $write->toState;
		if ($state === null) {
			return null;
		}

		$rules = $this->resolver->resolveFromAnnotation(annotation: $write->annotation, data: $write->newData, state: $state);
		$property = self::firstUnanswered(required: $rules->getRequired(), data: $write->newData);
		if ($property === null) {
			return null;
		}

		return self::requiredRefusalFor(rules: $rules, property: $property, state: $state);
	}//end requiredRefusal()

	/**
	 * The refusal an unanswered required property earns.
	 *
	 * @param StateFieldRulesInterface $rules    The rules of the state the object ends in.
	 * @param string          $property The unanswered property.
	 * @param string          $state    The state the object ends in.
	 *
	 * @return array<string, mixed> The structured refusal.
	 */
	private static function requiredRefusalFor(StateFieldRulesInterface $rules, string $property, string $state): array {
		return [
			'code' => self::CODE_REQUIRED,
			'field' => $property,
			'state' => $state,
			'message' => ($rules->messageFor(property: $property) ?? sprintf('Field "%s" is required in state "%s".', $property, $state)),
		];
	}//end requiredRefusalFor()

	/**
	 * The first required property the write leaves unanswered, or null.
	 *
	 * @param array<int, string>   $required The properties the state requires.
	 * @param array<string, mixed> $data     The object as it would be saved.
	 *
	 * @return string|null The property.
	 */
	private static function firstUnanswered(array $required, array $data): ?string {
		foreach ($required as $property) {
			if (self::isAnswered(property: $property, data: $data) === false) {
				return $property;
			}
		}

		return null;
	}//end firstUnanswered()

	/**
	 * Whether the write answers a required property: with a value, or recorded as not supplied.
	 *
	 * A property recorded as not supplied was answered, deliberately and with a
	 * reason from the schema's administered list. That is a different fact from
	 * an empty field, and a required rule that cannot tell them apart is the
	 * rule that puts "onbekend" back into the register (REQ-RGC-002).
	 *
	 * @param string $property The property a state rule requires.
	 * @param array<string, mixed> $data The object as it would be saved.
	 *
	 * @return bool True when the property is answered.
	 */
	private static function isAnswered(string $property, array $data): bool {
		return (self::isFilled(value: ($data[$property] ?? null)) === true
			|| self::isRecordedAsNotSupplied(property: $property, data: $data) === true);
	}//end isAnswered()

	/**
	 * Whether a value counts as filled in for a `required` rule.
	 *
	 * `0` and `false` are filled in: a required checkbox answered "no" is
	 * answered. Only absence, null, the empty string and the empty list are
	 * empty, which is the same reading the transition `inputs` contract uses.
	 *
	 * @param mixed $value The value as submitted.
	 *
	 * @return bool True when the value is present.
	 */
	private static function isFilled(mixed $value): bool {
		if ($value === null) {
			return false;
		}

		if (is_string($value) === true) {
			return (trim($value) !== '');
		}

		if (is_array($value) === true) {
			return ($value !== []);
		}

		return true;
	}//end isFilled()

	/**
	 * Whether the write records this property as not supplied.
	 *
	 * The record itself is validated on the save path: the reason has to be one
	 * the schema administers, and a property cannot both carry a value and be
	 * marked. By the time this listener runs, a marker present here is one that
	 * already passed those checks, so reading it is enough.
	 *
	 * @param string $property The property a state rule requires.
	 * @param array<string, mixed> $data The object as it would be saved.
	 *
	 * @return bool True when the property is recorded as not supplied.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private static function isRecordedAsNotSupplied(string $property, array $data): bool {
		$record = ($data[Schema::NOT_SUPPLIED_KEY] ?? null);
		if (is_array($record) === false) {
			return false;
		}

		$reason = ($record[$property] ?? null);

		return (is_string($reason) === true && $reason !== '');
	}//end isRecordedAsNotSupplied()

	/**
	 * Record the refusal against the state's rule, for the run log.
	 *
	 * Only refusals are recorded. A transition condition is evaluated when a
	 * transition is attempted, so recording both its verdicts costs one row per
	 * transition; a state's field rules are evaluated on EVERY save, and
	 * recording the passes would put a row on the hot path of every write in
	 * the register to say that nothing happened. The question the run log
	 * answers for this kind is "why was my save refused", and that is what is
	 * written here.
	 *
	 * @param ObjectEntity $object The object being saved.
	 * @param Schema $schema The schema the rule is declared on.
	 * @param array<string, mixed> $refusal The refusal as reported.
	 *
	 * @return void
	 */
	private function record(ObjectEntity $object, Schema $schema, array $refusal): void {
		$slug = (string)($schema->getSlug() ?? '');
		$state = (string)($refusal['state'] ?? '');
		if ($slug === '' || $state === '') {
			return;
		}

		try {
			$this->recordRefusal(object: $object, slug: $slug, refusal: $refusal);
		} catch (Throwable $e) {
			// The run log is a courtesy on a decision already taken. Losing the
			// row must never turn a refusal into a 500.
			$this->logger->warning(sprintf('State field rule run could not be recorded: %s', $e->getMessage()));
		}
	}//end record()

	/**
	 * Write the refusal to the run log, against its state's rule.
	 *
	 * @param ObjectEntity $object The object being saved.
	 * @param string $slug The schema's slug.
	 * @param array<string, mixed> $refusal The refusal as reported; it names a state.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RuleDescriptor::idFor is the published
	 *   derivation of a rule id, and a rule id has no instance to derive it from.
	 */
	private function recordRefusal(ObjectEntity $object, string $slug, array $refusal): void {
		$this->ruleRuns->record(
			ruleId: RuleDescriptor::idFor(kind: RuleVocabulary::KIND_STATE_FIELD_RULE, schemaSlug: $slug, key: (string)$refusal['state']),
			schemaSlug: $slug,
			trace: self::traceOf(refusal: $refusal),
			objectUuid: ($object->getUuid() ?? null),
			registerSlug: ($object->getRegister() ?? null)
		);
	}//end recordRefusal()

	/**
	 * The run log's trace of a refusal.
	 *
	 * @param array<string, mixed> $refusal The refusal as reported.
	 *
	 * @return RuleTrace The refused verdict, naming the field (or the condition's clause) and the message.
	 */
	private static function traceOf(array $refusal): RuleTrace {
		return new RuleTrace(
			verdict: RuleVocabulary::VERDICT_REFUSED,
			operand: (string)($refusal['field'] ?? ($refusal['clause'] ?? '')),
			message: (string)($refusal['message'] ?? '')
		);
	}//end traceOf()

	/**
	 * Look up the schema referenced by an object instance.
	 *
	 * @param ObjectEntity $object Object whose schema reference to resolve.
	 *
	 * @return Schema|null Resolved schema, or null on lookup failure.
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$schemaRef = $object->getSchema();
		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find($schemaRef, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('State field rule listener could not load schema "%s": %s', (string)$schemaRef, $e->getMessage())
			);
			return null;
		}
	}//end loadSchema()
}//end class
