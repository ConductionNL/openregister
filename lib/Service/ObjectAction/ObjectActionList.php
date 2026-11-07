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
	 * @param ObjectActionDefinitions $definitions The schema's actions, inherited ones included.
	 * @param ObjectActionGate        $gate        The right and the precondition.
	 * @param ObjectActionInputs      $inputs      Defaults and the transition input translation.
	 * @param TransitionEngine        $transitions The lifecycle's available moves.
	 * @param IL10N                   $l10n        Reasons.
	 */
	public function __construct(
		private readonly ObjectActionDefinitions $definitions,
		private readonly ObjectActionGate $gate,
		private readonly ObjectActionInputs $inputs,
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
		if ($this->gate->may(schema: $schema, right: 'read', object: $object) === false) {
			throw new NotAuthorizedException(message: 'You do not have permission to read this object.');
		}

		$available = [];
		foreach ($this->transitions->availableActions((string)$object->getUuid()) as $move) {
			$available[(string)$move['action']] = $move;
		}

		$entries = [];
		$named = [];
		foreach ($this->definitions->forSchema(schema: $schema, register: $object->getRegister()) as $definition) {
			if ($this->mayRun(schema: $schema, definition: $definition, object: $object) === false) {
				continue;
			}

			$move = null;
			if ($definition->kind === ObjectActionDefinition::KIND_TRANSITION) {
				$named[(string)$definition->body] = true;
				$move = ($available[(string)$definition->body] ?? false);
			}

			$entries[] = $this->declaredEntry(definition: $definition, object: $object, move: $move);
		}

		foreach ($available as $key => $move) {
			if (isset($named[$key]) === false) {
				$entries[] = $this->transitionEntry(move: $move);
			}
		}

		return $entries;
	}//end forObject()

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
	 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
	 */
	public function canRun(ObjectEntity $object, Schema $schema): array {
		$can = [];
		foreach ($this->definitions->forSchema(schema: $schema, register: $object->getRegister()) as $definition) {
			if ($definition->isRunnable() === false) {
				continue;
			}

			$can[$definition->key] = $this->mayRun(schema: $schema, definition: $definition, object: $object)
				&& $this->gate->selfAssignOpen(definition: $definition, object: $object)
				&& $this->preconditionHolds(definition: $definition, object: $object);
		}

		return $can;
	}//end canRun()

	/**
	 * Whether the caller holds the right to run this declared action on the object.
	 *
	 * @param Schema                 $schema     The schema.
	 * @param ObjectActionDefinition $definition The action.
	 * @param ObjectEntity           $object     The object.
	 *
	 * @return bool True when it is runnable and granted.
	 */
	private function mayRun(Schema $schema, ObjectActionDefinition $definition, ObjectEntity $object): bool {
		return $definition->isRunnable() === true
			&& $this->gate->mayRun(schema: $schema, right: $definition->key, definition: $definition, object: $object) === true;
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
		$inputs = $this->inputs->withDefaults(schema: $definition->inputs, given: [], object: $object, userId: $this->gate->userId());

		return $this->gate->whenHolds(definition: $definition, object: $object, inputs: $inputs);
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
		foreach ($this->definitions->forSchema(schema: $schema, register: $register) as $definition) {
			if ($definition->isRunnable() === false || $definition->offersBulk() === false
				|| $this->gate->mayRun(schema: $schema, right: $definition->key, definition: $definition, object: null) === false
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
	 * @param ObjectActionDefinition    $definition The action.
	 * @param ObjectEntity              $object     The object.
	 * @param array<string, mixed>|false|null $move The transition it names, false when not available now, null when none.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function declaredEntry(ObjectActionDefinition $definition, ObjectEntity $object, array|false|null $move): array {
		$entry = $this->shape(definition: $definition);
		$entry['available'] = true;

		if ($move === false) {
			$entry['available'] = false;
			$entry['reason'] = $this->l10n->t('This step is not available from the current status.');
			return $entry;
		}

		if ($this->gate->selfAssignOpen(definition: $definition, object: $object) === false) {
			$entry['available'] = false;
			$entry['reason'] = $this->l10n->t('This object is already assigned.');
			return $entry;
		}

		if ($this->preconditionHolds(definition: $definition, object: $object) === false) {
			$entry['available'] = false;
			$entry['reason'] = $this->gate->reason(definition: $definition);
		}

		if ($definition->inputs === null && is_array($move) === true) {
			$entry['inputs'] = $this->inputs->fromTransitionInputs(inputs: (array)($move['inputs'] ?? []));
		}

		return $entry;
	}//end declaredEntry()

	/**
	 * The entry of a lifecycle transition no declared action names.
	 *
	 * @param array<string, mixed> $move The transition from availableActions().
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function transitionEntry(array $move): array {
		$entry = [
			'key' => (string)$move['action'],
			'kind' => ObjectActionDefinition::KIND_TRANSITION,
			'label' => (string)($move['label'] ?? $move['action']),
			'description' => ($move['description'] ?? null),
			'icon' => null,
			'surfaces' => ObjectActionDefinition::DEFAULT_SURFACES,
			'available' => (($move['blocked'] ?? false) !== true),
			'inputs' => $this->inputs->fromTransitionInputs(inputs: (array)($move['inputs'] ?? [])),
			'confirm' => null,
			'mode' => 'sync',
			'next' => FlowNextHint::STAY,
		];
		if ($entry['available'] === false) {
			$entry['reason'] = $this->l10n->t('This step is blocked by a condition on the object.');
		}

		return $entry;
	}//end transitionEntry()

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
