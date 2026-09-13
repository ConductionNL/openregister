<?php

/**
 * OpenRegister TransitionEngine
 *
 * Action-based sugar over the lifecycle annotation. Looks up the
 * transition by action name, mutates the lifecycle field, saves through
 * the standard ObjectService path (so all the existing validation,
 * eventing, and audit machinery runs unchanged), and dispatches the
 * typed ObjectTransitionedEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Apply named lifecycle transitions and report which actions are
 * available from the object's current state.
 *
 * Not declared `final`: TransitionControllerTest doubles this class, and
 * the controller injects it by concrete type. If sealing is reintroduced,
 * extract an interface for the controller to depend on first.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The third lifecycle mode
 * (`provider`) is what carried this past the 1000-line threshold. The three
 * modes answer one question and share one published contract, so splitting
 * them across classes would duplicate `publishedInputs()` and let the shape
 * a client reads drift per mode, which is the failure this change exists to
 * avoid. Graph-mode derivation is the extractable block if the class grows
 * again; it is left alone here so the provider change stays readable as a
 * diff.
 */
class TransitionEngine {
	/**
	 * App-config key opting an instance into the documented slug contract.
	 *
	 * Default 'no' — see {@see transitionEventScope()} for why a contract fix
	 * ships disabled.
	 *
	 * @var string
	 */
	public const SLUG_CONTRACT_FLAG = 'transition_event_slug_contract';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Object CRUD service used to load + save the entity.
	 * @param SchemaMapper $schemaMapper Mapper to resolve the entity's schema.
	 * @param IEventDispatcher $eventDispatcher Dispatcher used to fire ObjectTransitionedEvent.
	 * @param IUserSession $userSession Current user session, for actor attribution.
	 * @param PermissionHandler $permissionHandler RBAC verdict on the object's `update`/`read` actions (F03).
	 * @param RegisterMapper $registerMapper Mapper used to resolve the register slug.
	 * @param IAppConfig $appConfig App config, for the slug-contract opt-in.
	 * @param LoggerInterface $logger Logger for post-commit listener failures.
	 * @param LifecycleWriteBoundary $writeBoundary Names the transition for the listeners and wraps
	 *                               the request-scoped automatic-transition pass.
	 * @param LifecycleActionProviderRegistry $providerRegistry Resolves a provider-mode annotation's
	 *                               `provider` tag to the app service that answers available actions.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Ten collaborators, all
	 * constructor-injected because Nextcloud's DI offers no other route. The
	 * tenth is the provider registry; folding it into an existing collaborator
	 * would hide a lifecycle dependency inside something that is not about
	 * lifecycles, which reads worse than the count.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SchemaMapper $schemaMapper,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IUserSession $userSession,
		private readonly PermissionHandler $permissionHandler,
		private readonly RegisterMapper $registerMapper,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly LifecycleWriteBoundary $writeBoundary,
		private readonly LifecycleActionProviderRegistry $providerRegistry,
	) {
	}//end __construct()

	/**
	 * Dispatch `ObjectTransitionedEvent` without letting a listener undo the write.
	 *
	 * `ObjectTransitionedEvent` is a POST event: by the time it is dispatched the
	 * lifecycle mutation has already been saved and committed. Propagating a
	 * listener's exception out of here therefore reports failure for work that
	 * succeeded — the caller sees a 500 (or a 422 "transition refused", when the
	 * listener happens to throw a `RuntimeException` that
	 * {@see \OCA\OpenRegister\Controller\TransitionController::transition()}
	 * maps to that status) while the object sits in its NEW state. Retrying then
	 * fails a second time with "not allowed from the current state", because the
	 * transition it is being asked to repeat has in fact already happened.
	 *
	 * So a post-event listener MUST NOT be able to fail a committed transition.
	 * That is deliberately NOT extended to the pre-event (`*ing`) family: those
	 * exist precisely so a listener can veto, they are dispatched before the
	 * save, and their exceptions must keep propagating. Nothing here touches
	 * them — the veto path for a transition is `HookStoppedException` raised
	 * inside `saveObject()`, which is upstream of this method and unaffected.
	 *
	 * Swallowing silently would trade a loud wrong answer for a quiet one, so
	 * the failure is logged at ERROR with the exception attached: a listener
	 * that throws here is a real bug, and the side effect it owns (a legal hold,
	 * a ledger posting, an outbound notification) did not happen.
	 *
	 * @param ObjectEntity $object The saved object, in its post-transition state.
	 * @param string $action The transition action that was applied.
	 * @param string $from The lifecycle value before the transition.
	 * @param string $to The lifecycle value after the transition.
	 * @param string|null $userId The acting user, when there is a session.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function dispatchTransitioned(
		ObjectEntity $object,
		string $action,
		string $from,
		string $to,
		?string $userId,
	): void {
		$scope = $this->transitionEventScope(object: $object);

		// Read from the pass's ambient frame, never from a parameter on
		// transition(): a caller that could pass `automatic: true` could claim
		// a move a person asked for was made by a rule.
		$applying = $this->writeBoundary->applyingAction();

		try {
			$this->eventDispatcher->dispatchTyped(
				new ObjectTransitionedEvent(
					object: $object,
					action: $action,
					from: $from,
					to: $to,
					userId: $userId,
					register: $scope['register'],
					schema: $scope['schema'],
					automatic: ($applying !== null && $applying === $action)
				)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[TransitionEngine] A listener threw on ObjectTransitionedEvent. '
				. 'The transition itself is COMMITTED; the listener\'s side effect did not run.',
				[
					'app' => 'openregister',
					'uuid' => $object->getUuid(),
					'register' => $scope['register'],
					'schema' => $scope['schema'],
					'action' => $action,
					'from' => $from,
					'to' => $to,
					'exception' => $e,
				]
			);
		}//end try

	}//end dispatchTransitioned()

	/**
	 * Resolve the register/schema pair to advertise on ObjectTransitionedEvent.
	 *
	 * ObjectTransitionedEvent documents both params as SLUGS, but this engine has
	 * always passed `(string) $object->getRegister()` / `getSchema()`, which are
	 * numeric ids. Every listener comparing them to a slug literal has therefore
	 * never matched and never run — 44 of them across scholiq, shillinq and
	 * openbuild.
	 *
	 * Honouring the documented contract is a one-line change and a very large
	 * behaviour change: it simultaneously activates dormant general-ledger
	 * posting, outbound HTTP to external parties, and bulk-write handlers that
	 * have never executed against this data. So the corrected contract ships
	 * DISABLED and is opted into per instance, after that instance has assessed
	 * its own listeners. See `docs/transition-event-slug-contract.md`.
	 *
	 * Resolution failures fall back to the id rather than throwing: a lifecycle
	 * transition must not start failing because a slug lookup missed.
	 *
	 * @param ObjectEntity $object The object being transitioned.
	 *
	 * @return array{register: string, schema: string} Values for the event.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function transitionEventScope(ObjectEntity $object): array {
		$registerRef = (string)$object->getRegister();
		$schemaRef = (string)$object->getSchema();

		if ($this->appConfig->getValueString('openregister', self::SLUG_CONTRACT_FLAG, 'no') !== 'yes') {
			return [
				'register' => $registerRef,
				'schema' => $schemaRef,
			];
		}

		try {
			$register = $this->registerMapper->find($registerRef);
			$slug = $register->getSlug();
			if ($slug !== null && $slug !== '') {
				$registerRef = $slug;
			}
		} catch (\Throwable $e) {
			// Keep the id; a missing slug must not break the transition.
		}

		try {
			$schema = $this->schemaMapper->find($schemaRef, _multitenancy: false);
			$slug = $schema->getSlug();
			if ($slug !== null && $slug !== '') {
				$schemaRef = $slug;
			}
		} catch (\Throwable $e) {
			// Keep the id; a missing slug must not break the transition.
		}

		return [
			'register' => $registerRef,
			'schema' => $schemaRef,
		];

	}//end transitionEventScope()

	/**
	 * Apply a named transition to an object.
	 *
	 * When the transition declares `inputs`, the (optional) `$data` payload is
	 * validated against that allowlist and the accepted values are merged into
	 * the SAME write that flips the lifecycle field — so pre-save listeners
	 * (ObjectUpdatingEvent) observe the status change and the inputs together,
	 * and the normal schema validation / readOnly enforcement applies to them.
	 *
	 * @param string $objectId Object id/uuid/slug.
	 * @param string $action Transition action name.
	 * @param array<string, mixed> $data Optional input values for the transition's declared `inputs`.
	 *
	 * @return ObjectEntity The saved object after the transition.
	 *
	 * @throws RuntimeException When the object/schema/transition is missing,
	 *                          the action is not allowed from the current
	 *                          state, or the underlying save is rejected.
	 * @throws NotAuthorizedException When the caller lacks `update` permission
	 *                          on the object.
	 * @throws InvalidTransitionInputException When `$data` contains a key the
	 *                          transition does not declare, or a `required`
	 *                          input is absent or empty-string.
	 * @throws \Exception When the save path refuses the merged write — schema
	 *                          validation, readOnly enforcement or a vetoing
	 *                          hook ({@see HookStoppedException}) — exactly as
	 *                          it would refuse any other object write.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function transition(string $objectId, string $action, array $data = []): ObjectEntity {
		// OPEN THE AUTOMATIC-TRANSITION BOUNDARY around the whole transition,
		// not around its save: this transition's own `ObjectTransitionedEvent`
		// is dispatched after the save returns, and a listener must see the
		// named move before any automatic move that followed from it. Leaving
		// the OUTERMOST boundary drains, so the entity answered here is the one
		// the last automatic move produced, when there was one.
		return $this->writeBoundary->around(
			write: fn (): ObjectEntity => $this->applyTransition(objectId: $objectId, action: $action, data: $data)
		);
	}//end transition()

	/**
	 * Resolve the object/schema/annotation a transition acts on, guarding presence and permission.
	 *
	 * Extracted from {@see applyTransition()} to keep its own mode/transition/from-state
	 * branching separate from "can this call proceed at all".
	 *
	 * @param string $objectId Object id/uuid/slug.
	 *
	 * @return array{object: ObjectEntity, schema: Schema, annotation: array<string, mixed>}
	 */
	private function resolveTransitionSubject(string $objectId): array {
		$object = $this->objectService->find(id: $objectId);
		if ($object === null) {
			// A distinct type, still a RuntimeException: the write endpoint has
			// to tell "this object is gone" (404) apart from "this move was
			// refused" (422) and "the provider broke" (502), and those first two
			// shared a status code until this type existed.
			throw new LifecycleSubjectNotFoundException(sprintf('Object "%s" not found.', $objectId));
		}

		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			throw new RuntimeException('Object schema could not be resolved.');
		}

		// Per-object RBAC: a transition mutates the lifecycle field, so the
		// caller MUST hold `update` permission on this object. Gated explicitly
		// (rather than relying solely on saveObject()'s own RBAC pass) so a
		// denial surfaces as a clear 403 before the annotation lookup runs.
		$callerId = $this->userSession->getUser()?->getUID();
		$allowed = $this->permissionHandler->hasPermission(
			schema: $schema,
			action: 'update',
			userId: $callerId,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);
		if ($allowed === false) {
			throw new NotAuthorizedException(
				message: sprintf(
					'You do not have permission to transition object "%s".',
					$objectId
				)
			);
		}

		$annotation = $this->getLifecycleAnnotation(schema: $schema);
		if ($annotation === null) {
			throw new RuntimeException(
				sprintf('Schema "%s" does not declare x-openregister-lifecycle.', (string)$schema->getSlug())
			);
		}

		return ['object' => $object, 'schema' => $schema, 'annotation' => $annotation];
	}//end resolveTransitionSubject()

	/**
	 * Apply a named transition, without the automatic-transition boundary.
	 *
	 * The whole of the pre-existing `transition()` body, split out so the boundary
	 * wraps it in one place and nothing inside can return past the drain.
	 *
	 * @param string $objectId Object id/uuid/slug.
	 * @param string $action Transition action name.
	 * @param array<string, mixed> $data Optional input values for the transition's declared `inputs`.
	 *
	 * @return ObjectEntity The saved object after the transition.
	 */
	private function applyTransition(string $objectId, string $action, array $data = []): ObjectEntity {
		$subject = $this->resolveTransitionSubject(objectId: $objectId);
		$object = $subject['object'];
		$annotation = $subject['annotation'];

		$field = (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
		$transitions = (array)($annotation['transitions'] ?? []);

		// Static transitions take precedence, then the two delegating modes in
		// the SAME order the read path resolves them: `provider` before
		// `graph` (design: mode selection & precedence — zero regression for
		// static schemas). The two paths must agree here or a schema can be
		// offered moves by one mode and judged by another, which is the
		// disagreement a user meets as a stage that highlights and then fails.
		if ($transitions === []) {
			$provider = trim((string)($annotation['provider'] ?? ''));
			if ($provider !== '') {
				return $this->applyProviderTransition(
					object: $object,
					tag: $provider,
					field: $field,
					action: $action,
					data: $data
				);
			}

			$graph = (array)($annotation['graph'] ?? []);
			if ($graph !== []) {
				return $this->applyGraphTransition(
					object: $object,
					graph: $graph,
					field: $field,
					action: $action,
					data: $data
				);
			}
		}

		if (isset($transitions[$action]) === false || is_array($transitions[$action]) === false) {
			throw new RuntimeException(
				sprintf('Transition "%s" is not declared on this schema.', $action)
			);
		}

		$spec = $transitions[$action];
		$targetState = (string)($spec['to'] ?? '');
		$from = (array)($spec['from'] ?? []);

		$objectData = $object->getObject() ?? [];
		$currentValue = (string)($objectData[$field] ?? '');

		if (in_array($currentValue, $from, true) === false) {
			throw new RuntimeException(
				sprintf(
					'Transition "%s" is not allowed from current state "%s".',
					$action,
					$currentValue
				)
			);
		}

		// Validate the payload against the transition's `inputs` allowlist and
		// merge the accepted values BEFORE flipping the lifecycle field, so the
		// status write always wins and both land in the same save.
		$accepted = $this->resolveTransitionInputs(
			inputs: (array)($spec['inputs'] ?? []),
			data: $data,
			action: $action
		);
		$objectData = array_merge($objectData, $accepted);

		// Mutate the lifecycle field. The validator listener will re-check
		// the transition on save; the guard (if any) will run there too.
		$objectData[$field] = $targetState;

		// Snapshot the session user at the transition boundary and forward it
		// explicitly to the save path, so the @self.folder check uses the SAME
		// identity that authorised this transition. Note this does NOT rescue
		// the null-session case: with no session user $actingUser is null and
		// the downstream check default-denies — as intended (PR #1431 4th-pass).
		$actingUser = $this->userSession->getUser();

		// Name the transition for the listeners. They see only object data and
		// would otherwise pick the first transition with this from/to pair,
		// judging and acting on a twin rather than the action asked for.
		$uuid = (string)$object->getUuid();
		$saved = $this->writeBoundary->declaringAction(
			uuid: $uuid,
			action: $action,
			write: fn (): ObjectEntity => $this->objectService->saveObject(
				object: $objectData,
				register: $object->getRegister(),
				schema: $object->getSchema(),
				uuid: $object->getUuid(),
				currentUser: $actingUser
			)
		);

		$userId = $actingUser?->getUID();

		$this->dispatchTransitioned(
			object: $saved,
			action: $action,
			from: $currentValue,
			to: $targetState,
			userId: $userId
		);

		return $saved;
	}//end applyTransition()

	/**
	 * Hand a provider-mode transition to the app that owns the state machine.
	 *
	 * THE PROVIDER PERFORMS THE WRITE. OpenRegister resolves the tag, hands
	 * over the object, the caller and the payload, and then re-reads. It does
	 * not mutate the lifecycle field itself and it does not call
	 * `saveObject()`. An app whose state machine is data owns more than the
	 * field the status lands in — dossiq's `StatusTransitionService::execute()`
	 * re-evaluates the transition's guards, takes an optimistic version lock,
	 * refuses a closing move that carries no result, writes the status record
	 * and dispatches the transition's side effects. A branch that took a new
	 * value back and saved it would strand every one of those.
	 *
	 * THE ACTION IS NOT RE-DERIVED HERE, deliberately. OpenRegister does not
	 * call `availableActions()` to check the posted action is one that was
	 * published: the provider re-validates with the same reader it answered
	 * the read with, so there is ONE authority. A check here would be a second
	 * derivation, and a second derivation is what eventually disagrees with
	 * the first. For the same reason `$data` is passed through untouched
	 * rather than allowlisted: the `inputs` a client was shown came from the
	 * provider, not from the schema, so the provider is the only thing that
	 * can say whether they were satisfied.
	 *
	 * NO WRITE-BOUNDARY DECLARATION, and not by omission. The static path
	 * calls {@see LifecycleWriteBoundary::declaringAction()} so the lifecycle
	 * listeners judge the named transition rather than the first one sharing
	 * its from/to pair. In provider mode there is no such name to look up: the
	 * annotation carries no `transitions` map, so the listeners have nothing
	 * to match and the declaration would tell them a name they cannot resolve.
	 * Worse, it would claim OpenRegister is applying a named transition to
	 * this uuid through its own save pipeline while in fact the app is writing
	 * — across several objects — and it would hold that claim open for the
	 * whole app call rather than around one save, which is precisely the
	 * widened frame `declaringAction()`'s `finally` exists to prevent. The
	 * enforcement the declaration supports has not been lost, it has moved:
	 * the provider re-validates the move itself. That is the difference from
	 * graph mode, which skips the declaration AND has nothing re-checking it,
	 * which is why graph mode is documented as unenforced and this is not.
	 *
	 * The automatic-transition boundary still frames the write: `transition()`
	 * wraps every mode in {@see LifecycleWriteBoundary::around()}, so a
	 * rule-driven move that follows from this one is still drained and still
	 * wins the entity that is answered.
	 *
	 * @param ObjectEntity $object The transitioning object, as it stood before the move.
	 * @param string $tag The `provider` DI tag off the annotation.
	 * @param string $field The lifecycle field name on the object.
	 * @param string $action The action name the caller posted.
	 * @param array<string, mixed> $data The caller-supplied input values.
	 *
	 * @return ObjectEntity The object re-read after the provider's write.
	 *
	 * @throws RuntimeException When the provider refuses the move.
	 * @throws LifecycleProviderException When the tag resolves to nothing, resolves to the wrong
	 *                          type, the provider fails unexpectedly, or the object cannot be
	 *                          re-read afterwards.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applyProviderTransition(
		ObjectEntity $object,
		string $tag,
		string $field,
		string $action,
		array $data = [],
	): ObjectEntity {
		$provider = $this->providerRegistry->resolve(tag: $tag);

		$actingUser = $this->userSession->getUser();
		$objectId = (string)$object->getUuid();
		$before = ($object->getObject() ?? []);
		$from = (string)($before[$field] ?? '');

		try {
			$report = $provider->execute(
				object: $before,
				userId: (string)($actingUser?->getUID() ?? ''),
				action: $action,
				data: $data
			);
		} catch (RuntimeException $e) {
			// THE APP SPEAKING, in the vocabulary the interface documents, so
			// nothing is wrapped and nothing is reclassified. An ordinary
			// RuntimeException is a refusal and reaches the caller as 422 with
			// the provider's own sentence; a LifecycleProviderException is a
			// declared breakage and reaches it as 502. Both are already the
			// type the controller maps, and rewriting either would turn "the
			// deadline has passed" into "the upstream is down" or the reverse.
			throw $e;
		} catch (Throwable $e) {
			// ANYTHING ELSE IS NOT A REFUSAL. A TypeError or an Error means
			// the provider failed in a way it never planned for, and reporting
			// that as 422 would tell a handler the move was considered and
			// declined when it was never considered at all.
			$this->logger->error(
				sprintf(
					'Lifecycle provider "%s" failed to apply action "%s" to object "%s": %s',
					$tag,
					$action,
					$objectId,
					$e->getMessage()
				),
				['exception' => $e]
			);
			throw new LifecycleProviderException(
				message: sprintf('Lifecycle provider "%s" could not apply action "%s".', $tag, $action),
				code: 0,
				previous: $e
			);
		}//end try

		// Re-read rather than trust the report: what the client is answered
		// with is the stored object, including whatever the provider's own
		// side effects stamped onto it.
		$saved = $this->objectService->find(id: $objectId);
		if ($saved === null) {
			$this->logger->error(
				sprintf(
					'Lifecycle provider "%s" applied action "%s" but object "%s" could not be re-read.',
					$tag,
					$action,
					$objectId
				)
			);
			throw new LifecycleProviderException(
				message: sprintf(
					'Lifecycle provider "%s" applied action "%s" but the object could not be read back.',
					$tag,
					$action
				)
			);
		}

		$this->dispatchTransitioned(
			object: $saved,
			action: $action,
			from: $from,
			to: $this->providerTargetState(report: $report, saved: $saved, field: $field),
			userId: $actingUser?->getUID()
		);

		return $saved;
	}//end applyProviderTransition()

	/**
	 * The state a provider moved the object into, for the transitioned event.
	 *
	 * The provider's report MAY name it as `to`, which is the only key
	 * OpenRegister reads off that array. When it does not — dossiq's own
	 * result shape is `status`/`statusRecord`/`dispatchedActions`/`version`,
	 * and returning it verbatim is the point of ignoring the rest — the value
	 * is read off the re-read object's lifecycle field, which is the stored
	 * truth and cannot disagree with what was persisted.
	 *
	 * @param array<string, mixed> $report What the provider answered.
	 * @param ObjectEntity $saved The object as re-read after the write.
	 * @param string $field The lifecycle field name on the object.
	 *
	 * @return string The target state, empty when neither source names one.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function providerTargetState(array $report, ObjectEntity $saved, string $field): string {
		$reported = trim((string)($report['to'] ?? ''));
		if ($reported !== '') {
			return $reported;
		}

		return (string)(($saved->getObject() ?? [])[$field] ?? '');
	}//end providerTargetState()

	/**
	 * List actions whose `from` includes the object's current lifecycle value.
	 *
	 * @param string $objectId Object id/uuid/slug.
	 *
	 * Every entry carries the transition's declared `inputs` in the contract's
	 * own shape (`[{field, required}]`), EMPTY rather than absent when the
	 * transition declares none: empty is the positive statement "this
	 * transition accepts no payload", which is exactly what the allowlist in
	 * {@see resolveTransitionInputs()} enforces.
	 *
	 * Three modes answer this question. A static `transitions` map is read
	 * here; a `provider` tag delegates to the app that owns the state
	 * machine; a `graph` block derives moves from FK-scoped siblings. Static
	 * wins over both delegating modes wherever more than one is declared.
	 *
	 * @return list<array{action:string,to:string,requires:?string,description:?string,inputs:list<array{field:string,required:bool}>,label?:string,blocked?:bool}>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) RBAC check + missing-object guard + annotation-absent
	 * guard + per-transition from/requires/description checks each add one branch; none can be removed
	 * without losing safety or fidelity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      RBAC check + missing-object guard + annotation-absent
	 * guard + per-transition from/requires/description checks each add one branch; none can be removed
	 * without losing safety or fidelity.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/flow-task-forms/specs/object-lifecycle/spec.md#requirement-the-available-actions-response-must-publish-each-actions-declared-inputs
	 */
	public function availableActions(string $objectId): array {
		$object = $this->objectService->find(id: $objectId);
		if ($object === null) {
			throw new RuntimeException(sprintf('Object "%s" not found.', $objectId));
		}

		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			return [];
		}

		// Only callers with `read` permission on the object can enumerate
		// available actions — the response would otherwise leak the
		// object's current lifecycle state to anyone who could guess the id.
		$callerId = $this->userSession->getUser()?->getUID();
		$allowed = $this->permissionHandler->hasPermission(
			schema: $schema,
			action: 'read',
			userId: $callerId,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);
		if ($allowed === false) {
			throw new NotAuthorizedException(
				message: sprintf(
					'You do not have permission to read object "%s".',
					$objectId
				)
			);
		}

		$annotation = $this->getLifecycleAnnotation(schema: $schema);
		if ($annotation === null) {
			return [];
		}

		$field = (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
		$transitions = (array)($annotation['transitions'] ?? []);

		// Static transitions take precedence. When no non-empty static map is
		// declared, fall through to the delegating modes in declaration order:
		// `provider` (the app answers) before `graph` (FK-scoped siblings at
		// runtime). Mode selection & precedence: a schema that declares a
		// static map keeps it, whatever else it says, so an annotation that
		// grows a second mode never silently loses the transitions it had.
		if ($transitions === []) {
			$provider = trim((string)($annotation['provider'] ?? ''));
			if ($provider !== '') {
				return $this->deriveProviderActions(object: $object, tag: $provider);
			}

			$graph = (array)($annotation['graph'] ?? []);
			if ($graph !== []) {
				return $this->deriveGraphActions(object: $object, graph: $graph, field: $field);
			}
		}

		$data = $object->getObject() ?? [];
		$currentValue = (string)($data[$field] ?? '');

		$available = [];
		foreach ($transitions as $action => $spec) {
			if (is_array($spec) === false) {
				continue;
			}

			$from = (array)($spec['from'] ?? []);
			if (in_array($currentValue, $from, true) === false) {
				continue;
			}

			$requires = null;
			$description = null;
			if (isset($spec['requires']) === true) {
				$requires = (string)$spec['requires'];
			}

			if (isset($spec['description']) === true) {
				$description = (string)$spec['description'];
			}

			$available[] = [
				'action' => (string)$action,
				'to' => (string)($spec['to'] ?? ''),
				'requires' => $requires,
				'description' => $description,
				'inputs' => $this->publishedInputs(inputs: (array)($spec['inputs'] ?? [])),
			];
		}//end foreach

		return $available;
	}//end availableActions()

	/**
	 * Ask the app for a provider-mode object's available actions.
	 *
	 * Provider mode exists for state machines whose shape is data rather than
	 * schema — a per-object workflow template, a per-case-type status list, a
	 * policy table an administrator edits. OpenRegister does not learn that
	 * model; it resolves the declared tag and asks, exactly as it already
	 * delegates guards and actions.
	 *
	 * Two failure modes are deliberately NOT collapsed into an empty list.
	 * An unresolved tag throws out of the registry, and a provider that
	 * throws while answering is wrapped here. Both reach the controller as
	 * `LifecycleProviderException`, which answers 502: an empty list is a
	 * legitimate answer ("this object offers no moves"), so a silent failure
	 * would be indistinguishable from one and would render as a dead
	 * timeline the client believes is correct.
	 *
	 * @param ObjectEntity $object The object whose actions are being listed.
	 * @param string $tag The `provider` DI tag off the annotation.
	 *
	 * @return list<array{action:string,to:string,requires:?string,description:?string,inputs:list<array{field:string,required:bool}>,label?:string,blocked?:bool}>
	 *
	 * @throws LifecycleProviderException When the tag resolves to nothing, resolves to the
	 *                          wrong type, or the provider throws while answering.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function deriveProviderActions(ObjectEntity $object, string $tag): array {
		$provider = $this->providerRegistry->resolve(tag: $tag);
		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');

		try {
			$entries = $provider->availableActions(
				object: ($object->getObject() ?? []),
				userId: $userId
			);
		} catch (Throwable $e) {
			$this->logger->error(
				sprintf(
					'Lifecycle provider "%s" failed to list actions for object "%s": %s',
					$tag,
					(string)$object->getUuid(),
					$e->getMessage()
				),
				['exception' => $e]
			);
			throw new LifecycleProviderException(
				message: sprintf('Lifecycle provider "%s" could not list available actions.', $tag),
				code: 0,
				previous: $e
			);
		}//end try

		return $this->publishProviderActions(entries: $entries);
	}//end deriveProviderActions()

	/**
	 * Normalise a provider's answer onto the published action contract.
	 *
	 * `inputs` runs through the SAME {@see publishedInputs()} the static and
	 * graph modes use, so the contract a client reads cannot drift per mode
	 * and a malformed entry is dropped rather than published. An entry that
	 * names no action is skipped, mirroring how a malformed static transition
	 * spec is skipped rather than published half-formed. `label` and
	 * `blocked` are carried through only when the provider set them, so the
	 * response shape of a provider that does not use them is byte-identical
	 * to a static one.
	 *
	 * @param array<int|string, mixed> $entries Whatever the provider returned.
	 *
	 * @return list<array{action:string,to:string,requires:?string,description:?string,inputs:list<array{field:string,required:bool}>,label?:string,blocked?:bool}>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function publishProviderActions(array $entries): array {
		$published = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$action = (string)($entry['action'] ?? '');
			if ($action === '') {
				continue;
			}

			$requires = null;
			if (isset($entry['requires']) === true) {
				$requires = (string)$entry['requires'];
			}

			$description = null;
			if (isset($entry['description']) === true) {
				$description = (string)$entry['description'];
			}

			$item = [
				'action' => $action,
				'to' => (string)($entry['to'] ?? ''),
				'requires' => $requires,
				'description' => $description,
				'inputs' => $this->publishedInputs(inputs: (array)($entry['inputs'] ?? [])),
			];

			if (isset($entry['label']) === true) {
				$item['label'] = (string)$entry['label'];
			}

			if (isset($entry['blocked']) === true) {
				$item['blocked'] = (bool)$entry['blocked'];
			}

			$published[] = $item;
		}//end foreach

		return $published;
	}//end publishProviderActions()

	/**
	 * Derive the candidate transitions for a graph-mode object.
	 *
	 * Reads the parent reference off the object, fetches the ordered sibling
	 * set of the related schema scoped to that parent (through the standard
	 * ObjectService read path, so RBAC + multitenancy apply), locates the
	 * object's current state, and returns the candidate targets permitted by
	 * `allowedMoves`. Terminal states (`finalField` true) are sinks unless
	 * `allowedMoves` is `any`. An orphaned current value (not among the
	 * siblings) recovers to the first sibling. The SAME method backs both
	 * `availableActions()` and the validation inside `transition()`, so a
	 * client can only apply a `move-to-<uuid>` the graph currently offers.
	 *
	 * @param ObjectEntity $object The transitioning object.
	 * @param array<string, mixed> $graph The `graph` block off the annotation.
	 * @param string $field The lifecycle field name on the object.
	 *
	 * @return list<array{action:string,to:string,label:string,requires:?string,description:?string,inputs:list<array{field:string,required:bool}>}>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) FK read + sibling fetch + current-state
	 * location + per-move-policy branching are each irreducible steps of the derivation.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      FK read + sibling fetch + current-state
	 * location + per-move-policy branching are each irreducible steps of the derivation.
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 */
	private function deriveGraphActions(ObjectEntity $object, array $graph, string $field): array {
		$parentFromKey = (string)($graph['parentFrom'] ?? '');
		$data = $object->getObject() ?? [];
		$parentValue = (string)($data[$parentFromKey] ?? '');

		// No parent reference → nothing to scope the graph to.
		if ($parentValue === '') {
			return [];
		}

		$siblings = $this->fetchOrderedSiblings(graph: $graph, parentValue: $parentValue);
		if ($siblings === []) {
			return [];
		}

		$finalField = (string)($graph['finalField'] ?? '');
		$allowed = (string)($graph['allowedMoves'] ?? '');

		$currentUuid = (string)($data[$field] ?? '');
		$currentIndex = null;
		foreach ($siblings as $i => $sibling) {
			if ((string)$sibling->getUuid() === $currentUuid && $currentUuid !== '') {
				$currentIndex = $i;
				break;
			}
		}

		// Orphaned / unset current value → recover-to-start: offer the first
		// sibling only (design decision, Ruben 2026-07-08).
		if ($currentIndex === null) {
			return [$this->buildGraphAction(sibling: $siblings[0])];
		}

		// Terminal lockout: a final state is a sink for forward/adjacent.
		$currentData = $siblings[$currentIndex]->getObject() ?? [];
		$currentFinal = (bool)($currentData[$finalField] ?? false);
		if ($currentFinal === true && $allowed !== 'any') {
			return [];
		}

		$targets = [];
		switch ($allowed) {
			case 'forward':
				if (isset($siblings[($currentIndex + 1)]) === true) {
					$targets[] = $siblings[($currentIndex + 1)];
				}
				break;
			case 'adjacent':
				if ($currentIndex > 0 && isset($siblings[($currentIndex - 1)]) === true) {
					$targets[] = $siblings[($currentIndex - 1)];
				}

				if (isset($siblings[($currentIndex + 1)]) === true) {
					$targets[] = $siblings[($currentIndex + 1)];
				}
				break;
			case 'any':
				foreach ($siblings as $i => $sibling) {
					if ($i !== $currentIndex) {
						$targets[] = $sibling;
					}
				}
				break;
			default:
				return [];
		}//end switch

		$actions = [];
		foreach ($targets as $target) {
			$actions[] = $this->buildGraphAction(sibling: $target);
		}

		return $actions;
	}//end deriveGraphActions()

	/**
	 * Fetch the ordered sibling set for a graph derivation.
	 *
	 * Uses `ObjectService::findAll` (the standard read path) filtered by the
	 * sibling schema and the parent FK, then sorts ascending by `orderField`
	 * with a deterministic UUID tiebreak so derivation never depends on
	 * database row order.
	 *
	 * @param array<string, mixed> $graph The `graph` block off the annotation.
	 * @param string $parentValue The resolved parent reference.
	 *
	 * @return array<int, ObjectEntity> The ordered sibling entities (0-indexed, re-keyed).
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 */
	private function fetchOrderedSiblings(array $graph, string $parentValue): array {
		$schemaSlug = (string)($graph['schema'] ?? '');
		$parentField = (string)($graph['parentField'] ?? '');
		$orderField = (string)($graph['orderField'] ?? '');
		if ($schemaSlug === '' || $parentField === '') {
			return [];
		}

		$siblings = $this->objectService->findAll(
			config: [
				'filters' => [
					'schema' => $schemaSlug,
					$parentField => $parentValue,
				],
				'sort' => [$orderField => 'ASC'],
			]
		);

		// Keep only ObjectEntity results and re-index.
		$entities = [];
		foreach ($siblings as $sibling) {
			if ($sibling instanceof ObjectEntity) {
				$entities[] = $sibling;
			}
		}

		// Deterministic sort (ascending order, UUID tiebreak) — do not rely on
		// the storage layer's ordering.
		usort(
			$entities,
			function (ObjectEntity $a, ObjectEntity $b) use ($orderField): int {
				$aOrder = (float)(($a->getObject() ?? [])[$orderField] ?? 0);
				$bOrder = (float)(($b->getObject() ?? [])[$orderField] ?? 0);
				if ($aOrder === $bOrder) {
					return strcmp((string)$a->getUuid(), (string)$b->getUuid());
				}

				return ($aOrder <=> $bOrder);
			}
		);

		return $entities;
	}//end fetchOrderedSiblings()

	/**
	 * Build a derived graph action envelope for a target sibling.
	 *
	 * @param ObjectEntity $sibling The target sibling to move to.
	 *
	 * A graph-derived action declares no `inputs`, and says so: the key is
	 * present and empty, so a client handles static and graph responses alike.
	 *
	 * @return array{action:string, to:string, label:string, requires:?string, description:?string, inputs:array<int,array{field:string,required:bool}>}
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/flow-task-forms/specs/object-lifecycle/spec.md#requirement-the-available-actions-response-must-publish-each-actions-declared-inputs
	 */
	private function buildGraphAction(ObjectEntity $sibling): array {
		$uuid = (string)$sibling->getUuid();
		$data = $sibling->getObject() ?? [];
		$name = $sibling->getName();
		if ($name === null || trim((string)$name) === '') {
			$name = (string)($data['name'] ?? ($data['title'] ?? $uuid));
		}

		return [
			'action' => 'move-to-' . $uuid,
			'to' => $uuid,
			'label' => (string)$name,
			'requires' => null,
			'description' => null,
			'inputs' => [],
		];
	}//end buildGraphAction()

	/**
	 * A transition's declared `inputs`, normalised to the contract's shape.
	 *
	 * Null when the schema declares no lifecycle annotation or no static
	 * transition of that name, so a caller can tell "declares nothing" (an
	 * empty list) from "no such transition". Graph-mode schemas declare no
	 * static transitions and therefore answer null for every action; their
	 * derived `move-to-*` actions accept no payload, which is what
	 * {@see availableActions()} publishes for them.
	 *
	 * The second consumer of the contract, next to the write path: a
	 * user-task step that names an action inherits this list as its form,
	 * verbatim, so a schema change and a form change are the same edit.
	 *
	 * @param Schema $schema The schema whose lifecycle declares the transition.
	 * @param string $action The transition action name.
	 *
	 * @return array<int, array{field: string, required: bool}>|null The declared inputs, or null when the action is not declared.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/object-lifecycle/spec.md#requirement-a-transition-may-declare-inputs-bounding-the-payload-it-accepts
	 */
	public function declaredInputs(Schema $schema, string $action): ?array {
		$annotation = $this->getLifecycleAnnotation(schema: $schema);
		if ($annotation === null) {
			return null;
		}

		$transitions = (array)($annotation['transitions'] ?? []);
		$spec = ($transitions[$action] ?? null);
		if (is_array($spec) === false) {
			return null;
		}

		return $this->publishedInputs(inputs: (array)($spec['inputs'] ?? []));
	}//end declaredInputs()

	/**
	 * The response shape of a declared `inputs` list: `[{field, required}]`.
	 *
	 * Runs the declaration through the SAME normalisation the allowlist uses,
	 * so what a client is told the transition accepts is exactly what the
	 * write path will accept; a malformed entry the allowlist skips is not
	 * published either.
	 *
	 * @param array<int, mixed> $inputs The transition's declared `inputs` list.
	 *
	 * @return array<int, array{field: string, required: bool}> The published list, in declaration order.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/object-lifecycle/spec.md#requirement-the-available-actions-response-must-publish-each-actions-declared-inputs
	 */
	private function publishedInputs(array $inputs): array {
		$published = [];
		foreach ($this->normaliseDeclaredInputs(inputs: $inputs) as $field => $required) {
			$published[] = [
				'field' => (string)$field,
				'required' => $required,
			];
		}

		return $published;
	}//end publishedInputs()

	/**
	 * Validate a transition `data` payload against the declared `inputs` allowlist.
	 *
	 * A transition may declare `inputs: [{"field": "<propertyName>", "required": true|false}, ...]`
	 * on its `x-openregister-lifecycle.transitions.<action>` block. Only declared
	 * fields are accepted from the payload; anything else is rejected — a
	 * transition with no `inputs` therefore rejects ANY payload, keeping today's
	 * behaviour for schemas that never opted in. The accepted values are NOT
	 * validated here against the property definitions: they are merged into the
	 * carrying object write, so the standard save-path validation (and readOnly
	 * enforcement) applies to them exactly like any other object write.
	 *
	 * Public since the task form became its second caller: a user-task
	 * completion that carries field values runs THIS allowlist over its
	 * payload before writing the subject object, so the form layer never
	 * grows a validator of its own. The signature and the throws are the
	 * write path's, unchanged; a test asserts both callers refuse the same
	 * payloads identically.
	 *
	 * @param array<int, mixed> $inputs The transition's declared `inputs` list.
	 * @param array<string, mixed> $data The caller-supplied payload.
	 * @param string $action The transition action name, for error messages.
	 *
	 * @return array<string, mixed> The accepted field => value pairs to merge into the write.
	 *
	 * @throws InvalidTransitionInputException When `$data` contains an undeclared
	 *                          key, or a `required` input is absent or empty-string.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/flow-task-forms/specs/object-lifecycle/spec.md#requirement-a-transition-may-declare-inputs-bounding-the-payload-it-accepts
	 */
	public function resolveTransitionInputs(array $inputs, array $data, string $action): array {
		$declared = $this->normaliseDeclaredInputs(inputs: $inputs);

		// Reject any payload key the transition does not declare.
		$unknown = array_diff(array_keys($data), array_keys($declared));
		if ($unknown !== []) {
			$unknown = array_values(array_map('strval', $unknown));
			throw new InvalidTransitionInputException(
				message: sprintf(
					'Transition "%s" does not accept input field(s): %s.',
					$action,
					'"'.implode('", "', $unknown).'"'
				),
				fields: $unknown
			);
		}

		// Reject when a required input is absent or empty-string.
		$missing = $this->collectMissingRequiredInputs(declared: $declared, data: $data);
		if ($missing !== []) {
			throw new InvalidTransitionInputException(
				message: sprintf(
					'Transition "%s" is missing required input field(s): %s.',
					$action,
					'"'.implode('", "', $missing).'"'
				),
				fields: $missing
			);
		}

		// Everything present is declared — merge it all.
		return $data;
	}//end resolveTransitionInputs()


	/**
	 * Collect the declared `required` inputs a payload fails to satisfy.
	 *
	 * A required input counts as missing when the payload omits the key entirely
	 * or supplies an empty string. Extracted from {@see resolveTransitionInputs()}
	 * so each rejection (undeclared keys, missing required) reads as one guard.
	 *
	 * @param array<string, bool> $declared Map of declared field name to its `required` flag.
	 * @param array<string, mixed> $data The caller-supplied payload.
	 *
	 * @return array<int, string> The missing required field names, empty when satisfied.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function collectMissingRequiredInputs(array $declared, array $data): array {
		$missing = [];
		foreach ($declared as $fieldName => $required) {
			if ($required === false) {
				continue;
			}

			if (array_key_exists($fieldName, $data) === false || $data[$fieldName] === '') {
				$missing[] = $fieldName;
			}
		}

		return $missing;
	}//end collectMissingRequiredInputs()

	/**
	 * Normalise a transition's `inputs` declaration into fieldName => required.
	 *
	 * Malformed entries (non-arrays, or entries without a `field` name) are
	 * skipped rather than fatal: a broken declaration must not take the whole
	 * transition down, it simply allowlists nothing.
	 *
	 * @param array<int, mixed> $inputs The transition's declared `inputs` list.
	 *
	 * @return array<string, bool> Map of declared field name to its `required` flag.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function normaliseDeclaredInputs(array $inputs): array {
		$declared = [];
		foreach ($inputs as $input) {
			if (is_array($input) === false) {
				continue;
			}

			$fieldName = (string)($input['field'] ?? '');
			if ($fieldName === '') {
				continue;
			}

			$declared[$fieldName] = (bool)($input['required'] ?? false);
		}

		return $declared;
	}//end normaliseDeclaredInputs()

	/**
	 * Apply a graph-mode transition.
	 *
	 * Re-runs the SAME derivation as `availableActions()`, accepts the posted
	 * action only when it is a current candidate, mutates the lifecycle field
	 * to the target UUID, saves through the unchanged ObjectService path, and
	 * dispatches `ObjectTransitionedEvent`. Rejection never mutates the object.
	 *
	 * @param ObjectEntity $object The transitioning object.
	 * @param array<string, mixed> $graph The `graph` block off the annotation.
	 * @param string $field The lifecycle field name on the object.
	 * @param string $action The requested `move-to-<uuid>` action.
	 * @param array<string, mixed> $data Caller-supplied input payload; graph-derived
	 *                                   actions declare no `inputs`, so any payload is rejected.
	 *
	 * @return ObjectEntity The saved object after the transition.
	 *
	 * @throws RuntimeException When the action is not a current candidate.
	 * @throws InvalidTransitionInputException When `$data` is non-empty.
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 */
	private function applyGraphTransition(
		ObjectEntity $object,
		array $graph,
		string $field,
		string $action,
		array $data = [],
	): ObjectEntity {
		// Graph-derived actions carry no `inputs` declaration, so nothing is
		// allowlisted: a non-empty payload is rejected just like an undeclared
		// key on a static transition.
		$this->resolveTransitionInputs(inputs: [], data: $data, action: $action);

		$candidates = $this->deriveGraphActions(object: $object, graph: $graph, field: $field);

		$match = null;
		foreach ($candidates as $candidate) {
			if ($candidate['action'] === $action) {
				$match = $candidate;
				break;
			}
		}

		if ($match === null) {
			throw new RuntimeException(
				sprintf('Transition "%s" is not allowed from the current state.', $action)
			);
		}

		$targetState = (string)$match['to'];
		$objectData = $object->getObject() ?? [];
		$from = (string)($objectData[$field] ?? '');

		$objectData[$field] = $targetState;

		// Snapshot the session user at the transition boundary and forward it
		// explicitly to the save path, mirroring the static-mode contract.
		$actingUser = $this->userSession->getUser();

		$saved = $this->objectService->saveObject(
			object: $objectData,
			register: $object->getRegister(),
			schema: $object->getSchema(),
			uuid: $object->getUuid(),
			currentUser: $actingUser
		);

		$this->dispatchTransitioned(
			object: $saved,
			action: $action,
			from: $from,
			to: $targetState,
			userId: $actingUser?->getUID()
		);

		return $saved;
	}//end applyGraphTransition()

	/**
	 * Load the schema referenced by an object, returning null on failure.
	 *
	 * @param ObjectEntity $object The object whose schema should be resolved.
	 *
	 * @return Schema|null The resolved schema, or null when missing/unresolvable.
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$schemaRef = $object->getSchema();
		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find($schemaRef, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end loadSchema()

	/**
	 * Pull the `x-openregister-lifecycle` annotation off a schema.
	 *
	 * @param Schema $schema The schema to inspect.
	 *
	 * @return array<string, mixed>|null The decoded annotation, or null when absent.
	 */
	private function getLifecycleAnnotation(Schema $schema): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$annotation = ($config['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === true) {
			return $annotation;
		}

		return null;
	}//end getLifecycleAnnotation()
}//end class
