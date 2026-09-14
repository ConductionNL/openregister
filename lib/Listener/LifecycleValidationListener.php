<?php

/**
 * OpenRegister LifecycleValidationListener
 *
 * Subscribes to ObjectUpdatingEvent and rejects updates that move the
 * lifecycle field to a value that no declared transition allows from the
 * current value. Uses the existing StoppableEventInterface contract.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Rejects invalid lifecycle transitions before they are written.
 *
 * Reads `x-openregister-lifecycle` from the schema's configuration block
 * (placed there by SchemaMapper at save time). When the lifecycle field
 * value differs between old and new object:
 * 1. Finds a transition whose `to` matches the new value.
 * 2. Verifies the old value is in that transition's `from` list.
 * 3. Resolves and runs the optional `requires` guard.
 *
 * Any failure stops propagation and sets a structured error on the event,
 * which the controller surfaces as HTTP 422 (invalid transition) or 403
 * (guard denial).
 *
 * Trust contract: lifecycle validation only fires on `ObjectUpdatingEvent`,
 * which is dispatched by `ObjectService::saveObject()`. Any code path that
 * mutates an object outside of `saveObject()` — direct `MagicMapper::update`
 * calls, raw SQL updates, import pipelines that bypass the service layer —
 * will skip this listener and can persist an invalid state value silently.
 * Callers MUST go through `ObjectService::saveObject()` (the public mutation
 * surface) for the lifecycle guarantee to hold. A future hardening step is a
 * DB-level CHECK constraint on the lifecycle column once the enum vocabulary
 * is treated as a closed set rather than a schema-author-defined list.
 *
 * @template-implements IEventListener<ObjectUpdatingEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The listener is the save path's one
 *   lifecycle gate; every dependency is one of its steps.
 */
class LifecycleValidationListener implements IEventListener {
	/**
	 * Wire collaborators used to validate transitions and run guards.
	 *
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param LifecycleGuardRegistry $guardRegistry Registry resolving guard ids to instances.
	 * @param IUserSession $userSession Current user session.
	 * @param PermissionHandler $permissionHandler RBAC handler used to evaluate declarative per-transition authorization.
	 * @param LoggerInterface $logger PSR logger for warnings.
	 * @param LifecycleConditionEvaluator $conditionEvaluator Decides whether a transition `condition` lets it through.
	 * @param LifecycleTransitionResolver $transitionResolver Decides which declared transition an edit is.
	 * @param ConditionTracer $conditionTracer Names the operand that decided a condition.
	 * @param RuleRunRecorder $ruleRuns Records each condition's verdict for the rule inventory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly LifecycleGuardRegistry $guardRegistry,
		private readonly IUserSession $userSession,
		private readonly PermissionHandler $permissionHandler,
		private readonly LoggerInterface $logger,
		private readonly LifecycleConditionEvaluator $conditionEvaluator,
		private readonly LifecycleTransitionResolver $transitionResolver,
		private readonly ConditionTracer $conditionTracer,
		private readonly RuleRunRecorder $ruleRuns,
	) {
	}//end __construct()

	/**
	 * Validate the attempted lifecycle transition before persistence.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$oldObject = $event->getOldObject();
		if ($oldObject === null) {
			// No prior state — nothing to validate against. Initial state
			// is enforced by LifecycleInitialStateListener.
			return;
		}

		$newObject = $event->getNewObject();
		$schema = $this->loadSchema(object: $newObject);
		if ($schema === null) {
			return;
		}

		$annotation = $this->getLifecycleAnnotation(schema: $schema);
		if ($annotation === null) {
			return;
		}

		// Accept `property` as an additive alias for `field` so schemas authored
		// against the procest migration shape work verbatim. `field` wins when
		// both are present.
		$field = (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
		$oldData = $oldObject->getObject() ?? [];
		$newData = $newObject->getObject() ?? [];

		$oldValue = $oldData[$field] ?? null;
		$newValue = $newData[$field] ?? null;

		if ($oldValue === $newValue) {
			// No lifecycle change — nothing to validate.
			return;
		}

		if (is_string($newValue) === false || $newValue === '') {
			$this->reject(
				event: $event,
				error: [
					'code' => 'lifecycle-invalid-value',
					'field' => $field,
					'attempted' => $newValue,
					'message' => sprintf('Lifecycle field "%s" must be a non-empty string.', $field),
				]
			);
			return;
		}

		$transitions = ($annotation['transitions'] ?? []);

		// Initial-only lifecycle: a schema may declare `x-openregister-lifecycle`
		// solely to derive the START state (e.g. procest's `case` schema —
		// `{ field: status, initial: { from: caseType, field: initialStatus } }`)
		// while OWNING transition validation itself (procest routes every status
		// change through its workflow-template state engine). With no declared
		// `transitions` there is nothing for OR to enforce, so validating here
		// fail-closes EVERY status change (no transition resolves →
		// reject), silently breaking those apps' status advancement. Treat an
		// empty/absent transition set as "app-managed" and skip enforcement; the
		// initial state is still pinned by LifecycleInitialStateListener.
		if (empty($transitions) === true) {
			return;
		}

		$matched = $this->transitionResolver->resolve(
			transitions: $transitions,
			uuid: (string)$newObject->getUuid(),
			oldValue: (string)$oldValue,
			newValue: $newValue
		);

		if ($matched === null) {
			$this->reject(
				event: $event,
				error: [
					'code' => 'lifecycle-invalid-transition',
					'field' => $field,
					'from' => $oldValue,
					'attempted' => $newValue,
					'message' => sprintf(
						'No transition allows moving "%s" from "%s" to "%s".',
						$field,
						(string)$oldValue,
						$newValue
					),
				]
			);
			return;
		}

		[$action, $spec] = $matched;

		// Declarative per-transition authorization (Engine 1). When the matched
		// transition lists `authorization` (NC group ids and/or `{ "role": "<name>" }`
		// entries), the caller MUST satisfy it. This is the group-based gate
		// procest's role-routing needs WITHOUT a bespoke PHP guard per role.
		// Evaluated BEFORE the `requires` guard so an unauthorized caller is
		// rejected with a 403-shaped error before any guard side-channel runs.
		// Transitions without an `authorization` key skip this entirely
		// (additive / backward-compatible).
		$authorizationList = ($spec['authorization'] ?? null);
		if (is_array($authorizationList) === true && $authorizationList !== []) {
			$userId = ($this->userSession->getUser()?->getUID() ?? null);
			if ($this->permissionHandler->isTransitionAuthorized(
				authorizationList: $authorizationList,
				userId: $userId,
				schema: $schema
			) === false
			) {
				$this->reject(
					event: $event,
					error: [
						'code' => 'lifecycle-transition-unauthorized',
						'field' => $field,
						'action' => $action,
						'message' => sprintf(
							'You are not authorized to perform transition "%s" on "%s".',
							$action,
							$field
						),
					]
				);
				return;
			}
		}//end if

		// Declarative JSONLogic precondition on the object's own data. Runs
		// AFTER `authorization` so an unauthorized caller is turned away
		// before any condition is evaluated, and BEFORE `requires` so a
		// refused condition never resolves, let alone runs, a guard.
		$refusal = $this->conditionEvaluator->refusal(
			spec: $spec,
			newData: $newData,
			oldData: $oldData,
			action: (string)$action,
			from: (string)$oldValue,
			to: $newValue,
			schemaSlug: (string)$schema->getSlug(),
			field: $field
		);
		$this->recordCondition(
			object: $newObject,
			schema: $schema,
			spec: $spec,
			newData: $newData,
			oldData: $oldData,
			action: (string)$action,
			from: (string)$oldValue,
			to: $newValue,
			refusal: $refusal
		);

		if ($refusal !== null) {
			$this->reject(event: $event, error: $refusal);
			return;
		}

		$requires = ($spec['requires'] ?? null);
		if (is_string($requires) === true && $requires !== '') {
			$userId = ($this->userSession->getUser()?->getUID() ?? '');
			$guard = $this->guardRegistry->resolve($requires);
			$result = $guard->check($newData, $action, $userId);
			if ($result->isAllowed() === false) {
				$this->reject(
					event: $event,
					error: [
						'code' => 'lifecycle-guard-denied',
						'field' => $field,
						'action' => $action,
						'message' => ($result->getMessage() ?? 'Transition denied by guard.'),
					]
				);
			}
		}
	}//end handle()

	/**
	 * Record what a transition's condition decided, and on which operand.
	 *
	 * This is the run log's whole purpose: "waarom is de flow niet gelopen" is
	 * answered here rather than out of a log file. A transition with no
	 * condition is not a rule and records nothing, and a condition switched off
	 * records nothing either, because it was not evaluated.
	 *
	 * @param ObjectEntity $object The object being saved.
	 * @param Schema $schema The schema the transition is declared on.
	 * @param array<string, mixed> $spec The matched transition's spec.
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 * @param string $action The matched transition's name.
	 * @param string $from The lifecycle value being moved away from.
	 * @param string $to The lifecycle value being moved to.
	 * @param array<string, mixed>|null $refusal The refusal the evaluator produced, or null.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every parameter is one half of the
	 *   fact being recorded: which rule, on which object, against which document, with
	 *   which verdict. Bundling them into a carrier object would hide exactly that.
	 * @SuppressWarnings(PHPMD.StaticAccess) RuleDescriptor::idFor is the published
	 *   derivation of a rule id.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function recordCondition(
		ObjectEntity $object,
		Schema $schema,
		array $spec,
		array $newData,
		array $oldData,
		string $action,
		string $from,
		string $to,
		?array $refusal,
	): void {
		$condition = ($spec['condition'] ?? null);
		if ($condition === null || ($spec['enabled'] ?? true) === false) {
			return;
		}

		$slug = (string)($schema->getSlug() ?? '');
		if ($slug === '') {
			return;
		}

		$verdict = RuleVocabulary::VERDICT_FIRED;
		$message = null;
		if ($refusal !== null) {
			// The condition held against the write: the rule did what it
			// declares, which is to refuse. `refused`, not `no_match`.
			$verdict = RuleVocabulary::VERDICT_REFUSED;
			$message = (string)($refusal['message'] ?? '');
		}

		$trace = $this->conditionTracer->trace(
			condition: $condition,
			document: $this->conditionEvaluator->document(
				newData: $newData,
				oldData: $oldData,
				action: $action,
				from: $from,
				to: $to
			),
			verdict: $verdict,
			message: $message
		);

		$this->ruleRuns->record(
			ruleId: RuleDescriptor::idFor(
				kind: RuleVocabulary::KIND_LIFECYCLE_CONDITION,
				schemaSlug: $slug,
				key: $action
			),
			schemaSlug: $slug,
			trace: $trace,
			objectUuid: ($object->getUuid() ?? null),
			registerSlug: ($object->getRegister() ?? null)
		);

	}//end recordCondition()

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
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('Lifecycle listener could not load schema "%s": %s', (string)$schemaRef, $e->getMessage())
			);
			return null;
		}
	}//end loadSchema()

	/**
	 * Read the `x-openregister-lifecycle` configuration block.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<string, mixed>|null Lifecycle annotation, or null when missing.
	 */
	private function getLifecycleAnnotation(Schema $schema): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$annotation = ($config['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === true) {
			return $annotation;
		}

		return null;
	}//end getLifecycleAnnotation()

	/**
	 * Stop the event and stamp a structured error onto it.
	 *
	 * @param ObjectUpdatingEvent $event The event being rejected.
	 * @param array<string, mixed> $error Structured error payload.
	 *
	 * @return void
	 */
	private function reject(ObjectUpdatingEvent $event, array $error): void {
		$event->setErrors($error);
		$event->stopPropagation();
	}//end reject()
}//end class
