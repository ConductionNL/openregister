<?php

/**
 * The actions a user may run on an object, in one list.
 *
 * Two sources merge here:
 *
 * 1. every runnable declared action the caller holds the right to, inherited
 *    ones included. One whose `when` does not hold is listed with
 *    `available: false` and its reason, so a host can show a disabled button
 *    with a tooltip; one the caller may not run is left out;
 * 2. every transition `TransitionEngine::availableActions()` offers (static,
 *    provider and graph modes) as `kind: transition`, its inputs translated to
 *    JSON Schema. A transition a declared action names is listed once, under
 *    the declared action's key.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Flow\FlowNextHint;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\IL10N;

/**
 * Lists the actions on one object, and the actions offered on a selection.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */
class ObjectActionList implements ObjectActionListInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectActionCatalogue $catalogue   The declared actions and their inputs.
	 * @param ObjectActionGate      $gate        The right and the precondition.
	 * @param TransitionEngine      $transitions The lifecycle's available moves.
	 * @param IL10N                 $l10n        Reasons.
	 */
	public function __construct(
		private readonly ObjectActionCatalogue $catalogue,
		private readonly ObjectActionGate $gate,
		private readonly TransitionEngine $transitions,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Every action the caller may run on this object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema       $schema Its schema.
	 *
	 * @return list<array<string, mixed>> The entries.
	 *
	 * @throws NotAuthorizedException When the caller may not read the object.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
	 */
	public function forObject(ObjectEntity $object, Schema $schema): array {
		if ($this->gate->holds(check: new RightCheck(schema: $schema, right: 'read', object: $object)) === false) {
			throw new NotAuthorizedException(message: 'You do not have permission to read this object.');
		}

		$available = [];
		foreach ($this->transitions->availableActions((string)$object->getUuid()) as $move) {
			$available[(string)$move['action']] = $move;
		}

		[$entries, $named] = $this->declaredEntries(run: new ActionListingRun(object: $object, schema: $schema, available: $available));
		foreach (array_diff_key($available, $named) as $move) {
			$entries[] = $this->transitionEntry(move: $move);
		}

		return $entries;
	}//end forObject()

	/**
	 * The entries of the declared actions the caller may run, and the transitions they name.
	 *
	 * @param ActionListingRun $run The object, its schema and the lifecycle's available moves.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: array<string, true>} The entries, and the transitions named.
	 */
	private function declaredEntries(ActionListingRun $run): array {
		$entries = [];
		$named   = [];
		foreach ($this->catalogue->definitions->forSchema(schema: $run->schema, register: $run->object->getRegister()) as $definition) {
			if ($this->mayRun(run: $run, definition: $definition) === false) {
				continue;
			}

			if ($definition->kind === ObjectActionDefinition::KIND_TRANSITION) {
				$named[(string)$definition->body] = true;
			}

			$entries[] = $this->declaredEntry(run: $run, definition: $definition);
		}

		return [$entries, $named];
	}//end declaredEntries()

	/**
	 * The lifecycle move a declared action names: null for any other kind, false when not available now.
	 *
	 * @param ObjectActionDefinition              $definition The action.
	 * @param array<string, array<string, mixed>> $available  The lifecycle's available moves, by action.
	 *
	 * @return array<string, mixed>|false|null The move.
	 */
	private function moveOf(ObjectActionDefinition $definition, array $available): array|false|null {
		if ($definition->kind !== ObjectActionDefinition::KIND_TRANSITION) {
			return null;
		}

		return ($available[(string)$definition->body] ?? false);
	}//end moveOf()

	/**
	 * Which declared actions the caller may run on this object now, by key.
	 *
	 * The same three questions a run asks before it executes: the right, the
	 * declared `when`, and for a self-assign an empty property. Computed for
	 * the caller of this request on every call.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema       $schema Its schema.
	 *
	 * @return array<string, bool> Action key => whether the caller may run it.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public function canRun(ObjectEntity $object, Schema $schema): array {
		$can = [];
		$run = new ActionListingRun(object: $object, schema: $schema, available: []);
		foreach ($this->catalogue->definitions->forSchema(schema: $schema, register: $object->getRegister()) as $definition) {
			if ($definition->isRunnable() === false) {
				continue;
			}

			$can[$definition->key] = $this->mayRun(run: $run, definition: $definition)
				&& $this->gate->selfAssignOpen(definition: $definition, object: $object)
				&& $this->preconditionHolds(definition: $definition, object: $object);
		}

		return $can;
	}//end canRun()

	/**
	 * Whether the caller holds the right to run this declared action on the object.
	 *
	 * @param ActionListingRun       $run        The object and its schema.
	 * @param ObjectActionDefinition $definition The action.
	 *
	 * @return bool True when it is runnable and granted.
	 */
	private function mayRun(ActionListingRun $run, ObjectActionDefinition $definition): bool {
		$check = new RightCheck(schema: $run->schema, right: $definition->key, object: $run->object);

		return $definition->isRunnable() === true && $this->gate->mayRun(check: $check, definition: $definition) === true;
	}//end mayRun()

	/**
	 * Whether the action's declared `when` holds for the caller, with default inputs.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param ObjectEntity           $object     The object.
	 *
	 * @return bool True when the precondition holds.
	 */
	private function preconditionHolds(ObjectActionDefinition $definition, ObjectEntity $object): bool {
		$scope  = new DefaultScope(object: $object, userId: $this->gate->userId());
		$inputs = $this->catalogue->inputs->withDefaults(invocation: new ObjectActionInvocation(definition: $definition, inputs: []), scope: $scope);

		return $this->gate->whenHolds(invocation: new ObjectActionInvocation(definition: $definition, inputs: $inputs), object: $object);
	}//end preconditionHolds()

	/**
	 * The actions offered on a selection of this schema's objects.
	 *
	 * The right is asked at schema level, so only owner-independent rules
	 * answer; each object is checked again when the action runs on it.
	 *
	 * @param Schema          $schema   The schema.
	 * @param int|string|null $register The register, for inherited actions.
	 *
	 * @return list<array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function forSelection(Schema $schema, int|string|null $register): array {
		$entries = [];
		foreach ($this->catalogue->definitions->forSchema(schema: $schema, register: $register) as $definition) {
			if ($definition->isRunnable() === false || $definition->offersBulk() === false
				|| $this->gate->mayRun(check: new RightCheck(schema: $schema, right: $definition->key, object: null), definition: $definition) === false
			) {
				continue;
			}

			$entry = $this->shape(definition: $definition);
			$entry['available'] = true;
			$entries[] = $entry;
		}

		return $entries;
	}//end forSelection()

	/**
	 * The entry of a declared action on one object.
	 *
	 * @param ActionListingRun       $run        The object and the lifecycle's available moves.
	 * @param ObjectActionDefinition $definition The action.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function declaredEntry(ActionListingRun $run, ObjectActionDefinition $definition): array {
		$entry  = $this->shape(definition: $definition);
		$move   = $this->moveOf(definition: $definition, available: $run->available);
		$reason = $this->unavailableReason(run: $run, definition: $definition);
		$entry['available'] = ($reason === null);
		if ($reason !== null) {
			$entry['reason'] = $reason;
		}

		if ($definition->inputs === null && is_array($move) === true) {
			$entry['inputs'] = $this->catalogue->inputs->fromTransitionInputs(inputs: (array)($move['inputs'] ?? []));
		}

		return $entry;
	}//end declaredEntry()

	/**
	 * Why a declared action the caller may run is not available now, or null when it is.
	 *
	 * Asked in order, and the first answer stands: the transition it names is
	 * not reachable from the current status, its self-assign property is
	 * already set, its `when` does not hold.
	 *
	 * @param ActionListingRun       $run        The object and the lifecycle's available moves.
	 * @param ObjectActionDefinition $definition The action.
	 *
	 * @return string|null The reason.
	 */
	private function unavailableReason(ActionListingRun $run, ObjectActionDefinition $definition): ?string {
		if ($this->moveOf(definition: $definition, available: $run->available) === false) {
			return $this->l10n->t('This step is not available from the current status.');
		}

		if ($this->gate->selfAssignOpen(definition: $definition, object: $run->object) === false) {
			return $this->l10n->t('This object is already assigned.');
		}

		if ($this->preconditionHolds(definition: $definition, object: $run->object) === false) {
			return $this->gate->reason(definition: $definition);
		}

		return null;
	}//end unavailableReason()

	/**
	 * The entry of a lifecycle transition no declared action names.
	 *
	 * @param array<string, mixed> $move The transition from availableActions().
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function transitionEntry(array $move): array {
		$entry = $this->moveFields(move: $move) + ['confirm' => null, 'mode' => 'sync', 'next' => FlowNextHint::STAY];
		if ($entry['available'] === false) {
			$entry['reason'] = $this->l10n->t('This step is blocked by a condition on the object.');
		}

		return $entry;
	}//end transitionEntry()

	/**
	 * What a lifecycle move says of itself, in entry order up to its inputs.
	 *
	 * @param array<string, mixed> $move The transition from availableActions().
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function moveFields(array $move): array {
		return [
			'key' => (string)$move['action'],
			'kind' => ObjectActionDefinition::KIND_TRANSITION,
			'label' => (string)($move['label'] ?? $move['action']),
			'description' => ($move['description'] ?? null),
			'icon' => null,
			'surfaces' => ObjectActionDefinition::DEFAULT_SURFACES,
			'available' => (($move['blocked'] ?? false) !== true),
			'inputs' => $this->catalogue->inputs->fromTransitionInputs(inputs: (array)($move['inputs'] ?? [])),
		];
	}//end moveFields()

	/**
	 * The fields every declared-action entry carries.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 *
	 * @return array<string, mixed> The entry, without availability.
	 */
	private function shape(ObjectActionDefinition $definition): array {
		return [
			'key' => $definition->key,
			'kind' => $definition->kind,
			'label' => $definition->label,
			'description' => $definition->description,
			'icon' => $definition->icon,
			'surfaces' => $definition->surfaces,
			'inputs' => $definition->inputs,
			'confirm' => $definition->confirm,
			'mode' => $definition->mode,
			'next' => ($definition->next ?? FlowNextHint::STAY),
		];
	}//end shape()
}//end class
