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
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

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
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
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
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Resolves the object's schema.
	 * @param StateFieldRuleResolver $resolver Resolves the rules that apply to this object and user.
	 * @param StateConditionEvaluator $stateConditions Evaluates a state's entry and exit conditions.
	 * @param RuleRunRecorder $ruleRuns Records a refusal for the rule run log.
	 * @param LoggerInterface $logger PSR logger for lookup failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly StateFieldRuleResolver $resolver,
		private readonly StateConditionEvaluator $stateConditions,
		private readonly RuleRunRecorder $ruleRuns,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Enforce the resulting state's field rules before the write lands.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
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
	private function enforce(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		ObjectEntity $newObject,
		?ObjectEntity $oldObject,
	): void {
		$schema = $this->loadSchema(object: $newObject);
		if ($schema === null) {
			return;
		}

		$annotation = $this->resolver->annotationOf(schema: $schema);
		if ($annotation === null || isset($annotation['states']) === false) {
			// No state block: nothing this listener can say about the write.
			// Every schema saved before this change takes this exit.
			return;
		}

		$newData = ($newObject->getObject() ?? []);
		$oldData = ($oldObject?->getObject() ?? []);
		$toState = $this->resolver->stateOf(annotation: $annotation, data: $newData);
		$fromState = null;
		if ($oldObject !== null) {
			$fromState = $this->resolver->stateOf(annotation: $annotation, data: $oldData);
		}

		$refusal = $this->stateConditions->refusal(
			annotation: $annotation,
			newData: $newData,
			from: $fromState,
			to: $toState
		);

		if ($refusal === null) {
			$refusal = $this->fieldRefusal(
				annotation: $annotation,
				newData: $newData,
				oldData: $oldData,
				fromState: ($fromState ?? $toState),
				toState: $toState,
				isCreate: ($oldObject === null)
			);
		}

		if ($refusal === null) {
			return;
		}

		$this->record(object: $newObject, schema: $schema, refusal: $refusal);
		$event->setErrors($refusal);
		$event->stopPropagation();
	}//end enforce()

	/**
	 * The first field rule this write breaks, or null when it breaks none.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 * @param string|null $fromState The state the edit was made in.
	 * @param string|null $toState The state the object ends in.
	 * @param bool $isCreate Whether this is a create.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function fieldRefusal(
		array $annotation,
		array $newData,
		array $oldData,
		?string $fromState,
		?string $toState,
		bool $isCreate,
	): ?array {
		if ($isCreate === false && $fromState !== null) {
			$current = $this->resolver->resolveFromAnnotation(
				annotation: $annotation,
				data: $oldData,
				state: $fromState
			);
			$frozen = $this->frozenRefusal(
				rules: $current,
				newData: $newData,
				oldData: $oldData,
				state: $fromState
			);
			if ($frozen !== null) {
				return $frozen;
			}
		}

		if ($toState === null) {
			return null;
		}

		$resulting = $this->resolver->resolveFromAnnotation(
			annotation: $annotation,
			data: $newData,
			state: $toState
		);

		foreach ($resulting->getRequired() as $property) {
			if (self::isFilled(value: ($newData[$property] ?? null)) === true) {
				continue;
			}

			return [
				'code' => self::CODE_REQUIRED,
				'field' => $property,
				'state' => $toState,
				'message' => ($resulting->messageFor(property: $property) ?? sprintf(
					'Field "%s" is required in state "%s".',
					$property,
					$toState
				)),
			];
		}

		return null;
	}//end fieldRefusal()

	/**
	 * The refusal a change to a frozen or hidden field earns.
	 *
	 * @param StateFieldRules $rules The rules of the state the edit was made in.
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 * @param string $state The state the rules came from.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function frozenRefusal(
		StateFieldRules $rules,
		array $newData,
		array $oldData,
		string $state,
	): ?array {
		foreach ($rules->getReadOnly() as $property) {
			if (self::hasChanged(property: $property, newData: $newData, oldData: $oldData) === false) {
				continue;
			}

			return [
				'code' => self::CODE_READ_ONLY,
				'field' => $property,
				'state' => $state,
				'message' => ($rules->messageFor(property: $property) ?? sprintf(
					'Field "%s" is read only in state "%s".',
					$property,
					$state
				)),
			];
		}

		foreach ($rules->getHidden() as $property) {
			if (self::hasChanged(property: $property, newData: $newData, oldData: $oldData) === false) {
				continue;
			}

			return [
				'code' => self::CODE_HIDDEN,
				'field' => $property,
				'state' => $state,
				'message' => sprintf('Field "%s" cannot be written in state "%s".', $property, $state),
			];
		}

		return null;
	}//end frozenRefusal()

	/**
	 * Whether an incoming write actually changes a property.
	 *
	 * A payload that resubmits the stored value is not a change, which is what
	 * lets a PATCH carry the whole object back without tripping over its own
	 * frozen fields. It is the same allowance property authorization makes.
	 *
	 * @param string $property The property name.
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 *
	 * @return bool True when the value differs.
	 */
	private static function hasChanged(string $property, array $newData, array $oldData): bool {
		if (array_key_exists($property, $newData) === false) {
			return false;
		}

		return (($oldData[$property] ?? null) !== $newData[$property]);
	}//end hasChanged()

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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RuleDescriptor::idFor is the published
	 *   derivation of a rule id, and a rule id has no instance to derive it from.
	 */
	private function record(ObjectEntity $object, Schema $schema, array $refusal): void {
		$slug = (string)($schema->getSlug() ?? '');
		$state = (string)($refusal['state'] ?? '');
		if ($slug === '' || $state === '') {
			return;
		}

		try {
			$this->ruleRuns->record(
				ruleId: RuleDescriptor::idFor(
					kind: RuleVocabulary::KIND_STATE_FIELD_RULE,
					schemaSlug: $slug,
					key: $state
				),
				schemaSlug: $slug,
				trace: new RuleTrace(
					verdict: RuleVocabulary::VERDICT_REFUSED,
					operand: (string)($refusal['field'] ?? ($refusal['clause'] ?? '')),
					message: (string)($refusal['message'] ?? '')
				),
				objectUuid: ($object->getUuid() ?? null),
				registerSlug: ($object->getRegister() ?? null)
			);
		} catch (Throwable $e) {
			// The run log is a courtesy on a decision already taken. Losing the
			// row must never turn a refusal into a 500.
			$this->logger->warning(
				sprintf('State field rule run could not be recorded: %s', $e->getMessage())
			);
		}
	}//end record()

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
