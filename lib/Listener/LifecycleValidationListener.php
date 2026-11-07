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

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardContext;
use OCA\OpenRegister\Lifecycle\LifecycleTransitionGuardInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistryInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rules\ConditionTracerInterface;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

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
	 * @param LifecycleEditReader                  $edits              Reads the edit and the transition it amounts to.
	 * @param LifecycleGuardRegistryInterface               $guardRegistry      Registry resolving guard ids to instances.
	 * @param IUserSession                         $userSession        Current user session.
	 * @param PermissionHandlerInterface                    $permissionHandler  RBAC handler used to evaluate declarative per-transition authorization.
	 * @param LifecycleConditionEvaluatorInterface $conditionEvaluator Decides whether a transition `condition` lets it through.
	 * @param ConditionTracerInterface                      $conditionTracer    Names the operand that decided a condition.
	 * @param RuleRunRecorderInterface                      $ruleRuns           Records each condition's verdict for the rule inventory.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LifecycleEditReader $edits,
		private readonly LifecycleGuardRegistryInterface $guardRegistry,
		private readonly IUserSession $userSession,
		private readonly PermissionHandlerInterface $permissionHandler,
		private readonly LifecycleConditionEvaluatorInterface $conditionEvaluator,
		private readonly ConditionTracerInterface $conditionTracer,
		private readonly RuleRunRecorderInterface $ruleRuns,
	) {
	}//end __construct()

	/**
	 * Validate the attempted lifecycle transition before persistence.
	 *
	 * An update without a stored object has nothing to validate against: the
	 * initial state is LifecycleInitialStateListener's.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === true) {
			$this->validate(event: $event);
		}
	}//end handle()

	/**
	 * Refuse the update when its change of state may not go ahead.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return void
	 */
	private function validate(ObjectUpdatingEvent $event): void {
		$edit = $this->edits->editOf(event: $event, logAs: 'Lifecycle listener');
		if ($edit === null || $edit->changesState() === false) {
			return;
		}

		$refusal = $this->refusal(edit: $edit);
		if ($refusal !== null) {
			$edit->refuse(error: $refusal);
		}
	}//end validate()

	/**
	 * What refuses this change of state, or null when it may go ahead.
	 *
	 * An initial-only lifecycle declares no `transitions`: a schema may use the
	 * block solely to derive the start state (procest's `case` schema) and own
	 * transition validation itself. There is then nothing to enforce here, and
	 * validating would fail-close every status change of such an app.
	 *
	 * @param LifecycleEdit $edit The edit.
	 *
	 * @return array<string, mixed>|null
	 */
	private function refusal(LifecycleEdit $edit): ?array {
		if ($this->writesDeclaredState(edit: $edit) === true) {
			return null;
		}

		if ($edit->hasTextState() === false) {
			return $this->invalidValue(edit: $edit);
		}

		if (empty($edit->declaration->transitions()) === true) {
			return null;
		}

		return $this->transitionRefusal(edit: $edit);
	}//end refusal()

	/**
	 * Whether this is a seed or example-set refresh writing the state its declaration names.
	 *
	 * As the system, that is the declared value, not a move a user makes, so no
	 * transition has to allow it.
	 *
	 * @param LifecycleEdit $edit The edit.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::isActive is the static scope check every system guard reads.
	 */
	private function writesDeclaredState(LifecycleEdit $edit): bool {
		return SystemOperationContext::isActive() === true && $this->edits->writesDeclaredState(edit: $edit) === true;
	}//end writesDeclaredState()

	/**
	 * The refusal of a lifecycle value that is not a non-empty string.
	 *
	 * @param LifecycleEdit $edit The edit.
	 *
	 * @return array<string, mixed>
	 */
	private function invalidValue(LifecycleEdit $edit): array {
		$field = $edit->declaration->field();

		return [
			'code' => 'lifecycle-invalid-value',
			'field' => $field,
			'attempted' => $edit->getNewValue(),
			'message' => sprintf('Lifecycle field "%s" must be a non-empty string.', $field),
		];
	}//end invalidValue()

	/**
	 * What refuses this move: no transition allows it, or the matched one turns it down.
	 *
	 * The order is authorization, then condition, then guard: an unauthorized caller
	 * is turned away before any condition is evaluated, and a refused condition never
	 * resolves, let alone runs, a guard.
	 *
	 * @param LifecycleEdit $edit The edit.
	 *
	 * @return array<string, mixed>|null
	 */
	private function transitionRefusal(LifecycleEdit $edit): ?array {
		$attempt = $this->edits->attemptOf(edit: $edit);
		if ($attempt === null) {
			return $this->noTransition(edit: $edit);
		}

		return ($this->authorizationRefusal(attempt: $attempt)
			?? $this->conditionRefusal(attempt: $attempt)
			?? $this->guardRefusal(attempt: $attempt));
	}//end transitionRefusal()

	/**
	 * The refusal of a move no declared transition allows.
	 *
	 * @param LifecycleEdit $edit The edit.
	 *
	 * @return array<string, mixed>
	 */
	private function noTransition(LifecycleEdit $edit): array {
		$field = $edit->declaration->field();

		return [
			'code' => 'lifecycle-invalid-transition',
			'field' => $field,
			'from' => $edit->getOldValue(),
			'attempted' => $edit->getNewValue(),
			'message' => sprintf('No transition allows moving "%s" from "%s" to "%s".', $field, $edit->getFromState(), $edit->getToState()),
		];
	}//end noTransition()

	/**
	 * The refusal of the transition's `authorization` list, or null when the caller satisfies it.
	 *
	 * The list holds NC group ids and/or `{ "role": "<name>" }` entries. A transition
	 * without one is open to everyone who may write the object.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 *
	 * @return array<string, mixed>|null
	 */
	private function authorizationRefusal(LifecycleAttempt $attempt): ?array {
		$authorizationList = ($attempt->spec['authorization'] ?? null);
		if (is_array($authorizationList) === false || $authorizationList === []) {
			return null;
		}

		$authorized = $this->permissionHandler->isTransitionAuthorized(
			authorizationList: $authorizationList,
			userId: ($this->userSession->getUser()?->getUID() ?? null),
			schema: $attempt->edit->declaration->schema
		);
		if ($authorized === true) {
			return null;
		}

		return $this->unauthorized(attempt: $attempt);
	}//end authorizationRefusal()

	/**
	 * The refusal of a caller the `authorization` list does not admit.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 *
	 * @return array<string, mixed>
	 */
	private function unauthorized(LifecycleAttempt $attempt): array {
		$field = $attempt->edit->declaration->field();

		return [
			'code' => 'lifecycle-transition-unauthorized',
			'field' => $field,
			'action' => $attempt->action,
			'message' => sprintf('You are not authorized to perform transition "%s" on "%s".', $attempt->action, $field),
		];
	}//end unauthorized()

	/**
	 * The refusal of the transition's JSONLogic `condition` on the object's own data, recorded either way.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 *
	 * @return array<string, mixed>|null
	 */
	private function conditionRefusal(LifecycleAttempt $attempt): ?array {
		$edit    = $attempt->edit;
		$refusal = $this->conditionEvaluator->refusal(
			spec: $attempt->spec,
			newData: $edit->getNewData(),
			oldData: $edit->getOldData(),
			action: $attempt->action,
			from: $edit->getFromState(),
			to: $edit->getToState(),
			schemaSlug: (string)$edit->declaration->schema->getSlug(),
			field: $edit->declaration->field()
		);
		$this->recordCondition(attempt: $attempt, refusal: $refusal);

		return $refusal;
	}//end conditionRefusal()

	/**
	 * The refusal of the transition's `requires` guard, or null when it allows or there is none.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-transition-guard-can-judge-the-stored-object
	 */
	private function guardRefusal(LifecycleAttempt $attempt): ?array {
		$requires = ($attempt->spec['requires'] ?? null);
		if (is_string($requires) === false || $requires === '') {
			return null;
		}

		$result = $this->runGuard(requires: $requires, context: $this->guardContext(attempt: $attempt));
		if ($result->isAllowed() === true) {
			return null;
		}

		return $this->guardDenied(attempt: $attempt, message: ($result->getMessage() ?? 'Transition denied by guard.'));
	}//end guardRefusal()

	/**
	 * The refusal of a guard that denied the transition.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 * @param string           $message The guard's message.
	 *
	 * @return array<string, mixed>
	 */
	private function guardDenied(LifecycleAttempt $attempt, string $message): array {
		return [
			'code' => 'lifecycle-guard-denied',
			'field' => $attempt->edit->declaration->field(),
			'action' => $attempt->action,
			'message' => $message,
		];
	}//end guardDenied()

	/**
	 * What a guard gets to judge: the incoming data, the stored object, the action and the caller.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 *
	 * @return LifecycleGuardContext
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-transition-guard-can-judge-the-stored-object
	 */
	private function guardContext(LifecycleAttempt $attempt): LifecycleGuardContext {
		return new LifecycleGuardContext(
			object: $attempt->edit->getNewData(),
			stored: $attempt->edit->stored,
			action: $attempt->action,
			userId: ($this->userSession->getUser()?->getUID() ?? '')
		);
	}//end guardContext()

	/**
	 * Run a transition's guard: with the stored object when it asks for it, else on the incoming data.
	 *
	 * @param string                $requires The guard's tag.
	 * @param LifecycleGuardContext $context  The incoming data, the stored object, the action and the caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-transition-guard-can-judge-the-stored-object
	 */
	private function runGuard(string $requires, LifecycleGuardContext $context): GuardResult {
		$guard = $this->guardRegistry->resolve($requires);
		if ($guard instanceof LifecycleTransitionGuardInterface) {
			return $guard->checkTransition(context: $context);
		}

		return $guard->check($context->object, $context->action, $context->userId);
	}//end runGuard()

	/**
	 * Record what a transition's condition decided, and on which operand.
	 *
	 * This is the run log's whole purpose: "waarom is de flow niet gelopen" is
	 * answered here rather than out of a log file. A transition with no
	 * condition is not a rule and records nothing, and a condition switched off
	 * records nothing either, because it was not evaluated.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 * @param array<string, mixed>|null $refusal The refusal the evaluator produced, or null.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RuleDescriptor::idFor is the published
	 *   derivation of a rule id.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function recordCondition(LifecycleAttempt $attempt, ?array $refusal): void {
		$slug = (string)($attempt->edit->declaration->schema->getSlug() ?? '');
		if ($this->conditionRuns(spec: $attempt->spec) === false || $slug === '') {
			return;
		}

		$this->ruleRuns->record(
			ruleId: RuleDescriptor::idFor(kind: RuleVocabulary::KIND_LIFECYCLE_CONDITION, schemaSlug: $slug, key: $attempt->action),
			schemaSlug: $slug,
			trace: $this->conditionTrace(attempt: $attempt, refusal: $refusal),
			objectUuid: ($attempt->edit->object()->getUuid() ?? null),
			registerSlug: ($attempt->edit->object()->getRegister() ?? null)
		);
	}//end recordCondition()

	/**
	 * Whether a transition has a condition that is switched on.
	 *
	 * @param array<string, mixed> $spec The matched transition's spec.
	 *
	 * @return bool
	 */
	private function conditionRuns(array $spec): bool {
		return ($spec['condition'] ?? null) !== null && ($spec['enabled'] ?? true) !== false;
	}//end conditionRuns()

	/**
	 * The trace of a condition's verdict.
	 *
	 * A condition that held against the write did what it declares, which is to
	 * refuse: `refused`, not `no_match`.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 * @param array<string, mixed>|null $refusal The refusal the evaluator produced, or null.
	 *
	 * @return RuleTrace
	 */
	private function conditionTrace(LifecycleAttempt $attempt, ?array $refusal): RuleTrace {
		$verdict = RuleVocabulary::VERDICT_FIRED;
		$message = null;
		if ($refusal !== null) {
			$verdict = RuleVocabulary::VERDICT_REFUSED;
			$message = (string)($refusal['message'] ?? '');
		}

		return $this->conditionTracer->trace(
			condition: $attempt->spec['condition'],
			document: $this->conditionDocument(attempt: $attempt),
			verdict: $verdict,
			message: $message
		);
	}//end conditionTrace()

	/**
	 * The document a transition's condition is evaluated against.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 *
	 * @return array<string, mixed>
	 */
	private function conditionDocument(LifecycleAttempt $attempt): array {
		$edit = $attempt->edit;

		return $this->conditionEvaluator->document(
			newData: $edit->getNewData(),
			oldData: $edit->getOldData(),
			action: $attempt->action,
			from: $edit->getFromState(),
			to: $edit->getToState()
		);
	}//end conditionDocument()
}//end class
