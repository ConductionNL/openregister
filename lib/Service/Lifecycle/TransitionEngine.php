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
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
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
	 */
	private function dispatchTransitioned(
		ObjectEntity $object,
		string $action,
		string $from,
		string $to,
		?string $userId,
	): void {
		$scope = $this->transitionEventScope(object: $object);

		try {
			$this->eventDispatcher->dispatchTyped(
				new ObjectTransitionedEvent(
					object: $object,
					action: $action,
					from: $from,
					to: $to,
					userId: $userId,
					register: $scope['register'],
					schema: $scope['schema']
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
	 * @throws InvalidTransitionInputException When `$data` contains a key the
	 *                          transition does not declare, or a `required`
	 *                          input is absent or empty-string.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Linear resolve→guard→mutate→save flow; splitting would obscure the transition contract.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 */
	public function transition(string $objectId, string $action, array $data = []): ObjectEntity {
		$object = $this->objectService->find(id: $objectId);
		if ($object === null) {
			throw new RuntimeException(sprintf('Object "%s" not found.', $objectId));
		}

		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			throw new RuntimeException('Object schema could not be resolved.');
		}

		// Per-object RBAC: a transition mutates the lifecycle field, so
		// the caller MUST hold `update` permission on this specific
		// object. The downstream `saveObject()` does its own RBAC pass,
		// but we gate explicitly here so that (a) a denial surfaces as
		// 403 with a clear message instead of being absorbed by the
		// save path's generic error envelope, and (b) we don't redo the
		// (potentially expensive) lifecycle annotation lookup before
		// discovering the caller had no business calling /transition
		// in the first place.
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

		$field = (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
		$transitions = (array)($annotation['transitions'] ?? []);

		// Static transitions take precedence. Graph mode applies only when no
		// non-empty static `transitions` map is declared (design: mode
		// selection & precedence — zero regression for static schemas).
		if ($transitions === []) {
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

		$saved = $this->objectService->saveObject(
			object: $objectData,
			register: $object->getRegister(),
			schema: $object->getSchema(),
			uuid: $object->getUuid(),
			currentUser: $actingUser
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
	}//end transition()

	/**
	 * List actions whose `from` includes the object's current lifecycle value.
	 *
	 * @param string $objectId Object id/uuid/slug.
	 *
	 * @return array<int, array{action: string, to: string, requires: ?string, description: ?string}>
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
		// declared but a `graph` block is, derive actions from FK-scoped
		// siblings at runtime (design: mode selection & precedence).
		if ($transitions === []) {
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
			];
		}//end foreach

		return $available;
	}//end availableActions()

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
	 * @return array<int, array{action: string, to: string, label: string, requires: ?string, description: ?string}>
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
	 * @return array{action: string, to: string, label: string, requires: ?string, description: ?string}
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
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
		];
	}//end buildGraphAction()

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
	 */
	private function resolveTransitionInputs(array $inputs, array $data, string $action): array {
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
		$missing = [];
		foreach ($declared as $fieldName => $required) {
			if ($required === false) {
				continue;
			}

			if (array_key_exists($fieldName, $data) === false || $data[$fieldName] === '') {
				$missing[] = $fieldName;
			}
		}

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
