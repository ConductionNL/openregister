<?php

/**
 * Move every selected object through one lifecycle action.
 *
 * Each member goes through TransitionEngine::transition(), the call a single
 * move makes, as the user who started the job, so the permission check, the
 * transition's guards and inputs, its lifecycle actions and its audit entry
 * are exactly those of a single move. A member already in the target state is
 * skipped; a member the lifecycle refuses fails with the message the single
 * move answers. The preview asks the engine which actions are available to
 * the actor on each member and changes nothing.
 *
 * Parameters: `{action, data}`, the same inputs for every member.
 *
 * Not reversible: a lifecycle move is not undone by writing old values back.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-bulk-transition/specs/object-lifecycle/spec.md#requirement-a-bulk-job-can-move-selected-records-through-one-lifecycle-action
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\IUser;
use RuntimeException;

/**
 * The bulk action that moves each member through one lifecycle action.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) BulkActionResult's named constructors are the outcome
 *   vocabulary every bulk action answers in.
 *
 * @spec openspec/changes/records-bulk-transition/specs/object-lifecycle/spec.md#requirement-a-bulk-job-can-move-selected-records-through-one-lifecycle-action
 */
class TransitionAction implements TransitionActionInterface {

	/**
	 * Constructor.
	 *
	 * @param TransitionEngine $engine The single-move path.
	 * @param BulkObjectAccess $access Reads a member's schema, and runs the preview as the job's actor.
	 */
	public function __construct(
		private readonly TransitionEngine $engine,
		private readonly BulkObjectAccess $access,
	) {
	}//end __construct()

	/**
	 * The bulk action id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.2
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.2
	 */
	public function getLabel(): string {
		return 'Change status';
	}//end getLabel()

	/**
	 * The description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.2
	 */
	public function getDescription(): string {
		return 'Moves every selected object through one lifecycle action, each as if it was moved on its own. Cannot be undone.';
	}//end getDescription()

	/**
	 * No reason is asked: each move is audited as the move it is.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.2
	 */
	public function requiresJustification(): bool {
		return false;
	}//end requiresJustification()

	/**
	 * One schema per selection: an action name means one lifecycle (D-3).
	 *
	 * @return array<int, string> The homogeneity guard.
	 *
	 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.2
	 */
	public function getGuards(): array {
		return [self::GUARD_HOMOGENEITY];
	}//end getGuards()

	/**
	 * Refuse parameters that name no action or carry data that is not an object.
	 *
	 * @param array<string, mixed> $parameters The parameters.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When they are unusable.
	 *
	 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.2
	 */
	public function validateParameters(array $parameters): void {
		if (is_string($parameters['action'] ?? null) === false || trim((string)$parameters['action']) === '') {
			throw new InvalidArgumentException('The action '.self::ID.' needs the lifecycle action in "action".');
		}

		$data = ($parameters['data'] ?? []);
		if (is_array($data) === false || ($data !== [] && array_is_list($data) === true)) {
			throw new InvalidArgumentException('The action '.self::ID.' needs "data" as an object of input values.');
		}
	}//end validateParameters()

	/**
	 * Rehearse or make the move on one member.
	 *
	 * @param BulkStep $step The member, the action and its data, preview or commit, and the actor.
	 *
	 * @return BulkActionResult The outcome.
	 *
	 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.2
	 */
	public function apply(BulkStep $step): BulkActionResult {
		$action = trim((string)($step->parameters['action'] ?? ''));
		$state  = $this->lifecycleState(object: $step->object, action: $action);
		if ($state['target'] !== '' && $state['current'] === $state['target']) {
			return BulkActionResult::skipped(reason: sprintf('the object is already in state "%s"', $state['target']));
		}

		if ($step->commit === false) {
			return $this->preview(step: $step, action: $action, current: $state['current']);
		}

		try {
			$this->engine->transition(
				objectId: $this->idOf(object: $step->object),
				action: $action,
				data: (array)($step->parameters['data'] ?? []),
				actor: $step->actor
			);
		} catch (InvalidTransitionInputException | NotAuthorizedException | HookStoppedException | RuntimeException $exception) {
			// The same four refusals, and the same words, as the single move.
			return BulkActionResult::failed(message: $exception->getMessage());
		}

		return BulkActionResult::applied();
	}//end apply()

	/**
	 * Whether the action is available to the actor on the member; nothing changes.
	 *
	 * @param BulkStep $step    The member and the actor.
	 * @param string   $action  The lifecycle action.
	 * @param string   $current The member's current state, for the message.
	 *
	 * @return BulkActionResult Applied when available, else failed saying why.
	 */
	private function preview(BulkStep $step, string $action, string $current): BulkActionResult {
		try {
			$available = $this->asActor(
				actor: $step->actor,
				call: fn (): array => $this->engine->availableActions(objectId: $this->idOf(object: $step->object))
			);
		} catch (NotAuthorizedException | RuntimeException $exception) {
			return BulkActionResult::failed(message: $exception->getMessage());
		}

		foreach ($available as $entry) {
			if (($entry['action'] ?? null) === $action && ($entry['blocked'] ?? false) !== true) {
				return BulkActionResult::applied();
			}
		}

		return BulkActionResult::failed(message: sprintf('"%s" is not available in state "%s"', $action, $current));
	}//end preview()

	/**
	 * Run a call as the job's actor, or as the session user when the job has none.
	 *
	 * @param IUser|null $actor The actor.
	 * @param callable   $call  The call.
	 *
	 * @return array<int, array<string, mixed>> What the call answered.
	 */
	private function asActor(?IUser $actor, callable $call): array {
		if ($actor === null) {
			return $call();
		}

		return $this->access->objects->runAs($actor, $call);
	}//end asActor()

	/**
	 * The member's current lifecycle state and the state the action leads to.
	 *
	 * Read from the schema's static `transitions` map; a delegating lifecycle
	 * (provider or graph) names no target here, and the engine decides.
	 *
	 * @param ObjectEntity $object The member.
	 * @param string       $action The lifecycle action.
	 *
	 * @return array{current: string, target: string}
	 */
	private function lifecycleState(ObjectEntity $object, string $action): array {
		try {
			// Read without RBAC or multitenancy: only the lifecycle declaration
			// is read; the engine checks the actor's rights on the member.
			$schema = $this->access->schemas->find((string)$object->getSchema(), _multitenancy: false, _rbac: false);
		} catch (\Throwable) {
			return ['current' => '', 'target' => ''];
		}

		$lifecycle = ($schema->getConfiguration()['x-openregister-lifecycle'] ?? null);
		if (is_array($lifecycle) === false) {
			return ['current' => '', 'target' => ''];
		}

		$field   = (string)($lifecycle['field'] ?? ($lifecycle['property'] ?? ''));
		$current = (string)(($object->getObject() ?? [])[$field] ?? '');

		return ['current' => $current, 'target' => (string)($lifecycle['transitions'][$action]['to'] ?? '')];
	}//end lifecycleState()

	/**
	 * The member's id as the engine takes it.
	 *
	 * @param ObjectEntity $object The member.
	 *
	 * @return string The uuid, else the id.
	 */
	private function idOf(ObjectEntity $object): string {
		return (string)($object->getUuid() ?? $object->getId());
	}//end idOf()
}//end class
