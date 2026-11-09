<?php

/**
 * Why a declared action's shape may not be saved.
 *
 * Judged without a database: one body, a transition the lifecycle declares,
 * inputs that are a JSON Schema object, a `when` that is JSONLogic, surfaces
 * from the vocabulary, a mode and a `next` hint that exist. The elevation
 * rules are {@see ObjectActionElevation}'s.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowNextHint;

/**
 * The shape refusals of one declared action.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression::isValid() and ObjectActionElevation::refusals()
 *   are stateless readers; the save path and the read path must reach the same answer.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionShape {

	/**
	 * The refusals one declared action earns.
	 *
	 * @param ObjectActionEntry $action The action key and its declared entry.
	 * @param SchemaRules       $rules  The schema lifecycle and authorization block it is checked against.
	 *
	 * @return list<string> The refusals.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function refusals(ObjectActionEntry $action, SchemaRules $rules): array {
		$bodies = ObjectActionDefinition::bodiesOf(definition: $action->definition);
		if (count($bodies) > 1) {
			return [sprintf('Action "%s": an action has one body, but this one declares %s.', $action->key, implode(' and ', $bodies))];
		}

		return array_values(array_merge(
			self::bodyRefusals(action: $action, lifecycle: $rules->lifecycle),
			self::inputRefusals(action: $action),
			self::surfaceRefusals(action: $action),
			self::modeRefusals(action: $action),
			ObjectActionElevation::refusals(action: $action, authorization: $rules->authorization)
		));
	}//end refusals()

	/**
	 * Refusals about the body itself.
	 *
	 * @param ObjectActionEntry    $action    The declared action.
	 * @param array<string, mixed> $lifecycle The schema lifecycle; [] skips the transition check.
	 *
	 * @return list<string> The refusals.
	 */
	private static function bodyRefusals(ObjectActionEntry $action, array $lifecycle): array {
		return match (ObjectActionDefinition::bodiesOf(definition: $action->definition)[0] ?? null) {
			ObjectActionDefinition::KIND_TRANSITION => self::transitionRefusals(action: $action, lifecycle: $lifecycle),
			ObjectActionDefinition::KIND_STEPS => self::stepsRefusals(key: $action->key, steps: $action->definition[ObjectActionDefinition::KIND_STEPS]),
			ObjectActionDefinition::KIND_SELF_ASSIGN => ObjectActionSelfAssignShape::refusals(key: $action->key, definition: $action->definition),
			default => [],
		};
	}//end bodyRefusals()

	/**
	 * Refusals about a `transition` body.
	 *
	 * A lifecycle in `provider` or `graph` mode answers its transitions at run
	 * time, so only a static `transitions` map can be checked here.
	 *
	 * @param ObjectActionEntry    $action    The declared action, with a `transition` body.
	 * @param array<string, mixed> $lifecycle The schema lifecycle; [] skips the check.
	 *
	 * @return list<string> The refusals.
	 */
	private static function transitionRefusals(ObjectActionEntry $action, array $lifecycle): array {
		$transition = $action->definition[ObjectActionDefinition::KIND_TRANSITION];
		if (is_string($transition) === false || trim($transition) === '') {
			return [sprintf('Action "%s": "transition" must name a lifecycle transition.', $action->key)];
		}

		$declared = self::declaredTransitions(lifecycle: $lifecycle);
		if ($declared !== [] && array_key_exists($transition, $declared) === false) {
			return [sprintf('Action "%s": the lifecycle declares no transition "%s".', $action->key, $transition)];
		}

		return [];
	}//end transitionRefusals()

	/**
	 * The lifecycle's static `transitions` map, [] when it declares none or not as a map.
	 *
	 * @param array<string, mixed> $lifecycle The schema lifecycle.
	 *
	 * @return array<mixed> The declared transitions, keyed by transition key.
	 */
	private static function declaredTransitions(array $lifecycle): array {
		$transitions = ($lifecycle['transitions'] ?? []);
		if (is_array($transitions) === false) {
			return [];
		}

		return $transitions;
	}//end declaredTransitions()

	/**
	 * Refusals about the shape of a `steps` body.
	 *
	 * Whether each step names a known node with a sound config is asked of
	 * the node registry when the steps are lowered.
	 *
	 * @param string $key   The action key.
	 * @param mixed  $steps The declared steps.
	 *
	 * @return list<string> The refusals.
	 */
	private static function stepsRefusals(string $key, mixed $steps): array {
		if (self::isStepList(steps: $steps) === false) {
			return [sprintf('Action "%s": "steps" must be a non-empty list of flow steps.', $key)];
		}

		foreach ($steps as $index => $step) {
			$refusal = self::stepRefusal(prefix: sprintf('Action "%s": step %d', $key, ($index + 1)), step: $step);
			if ($refusal !== null) {
				return [$refusal];
			}
		}

		return [];
	}//end stepsRefusals()

	/**
	 * Whether declared steps are a non-empty list.
	 *
	 * @param mixed $steps The declared steps.
	 *
	 * @return bool True when they are.
	 */
	private static function isStepList(mixed $steps): bool {
		return is_array($steps) === true && $steps !== [] && array_is_list($steps) === true;
	}//end isStepList()

	/**
	 * The refusal one step earns.
	 *
	 * @param string $prefix The refusal's opening, naming the action and the step's position from 1.
	 * @param mixed  $step   The step.
	 *
	 * @return string|null The refusal.
	 */
	private static function stepRefusal(string $prefix, mixed $step): ?string {
		if (is_array($step) === false || self::namesType(step: $step) === false) {
			return $prefix.' must name its node "type".';
		}

		if (array_key_exists('config', $step) === true && is_array($step['config']) === false) {
			return $prefix.' has a "config" that is not an object.';
		}

		return null;
	}//end stepRefusal()

	/**
	 * Whether a step names its node type.
	 *
	 * @param array<mixed> $step The step.
	 *
	 * @return bool True when `type` is a non-blank string.
	 */
	private static function namesType(array $step): bool {
		return is_string($step['type'] ?? null) === true && trim((string)$step['type']) !== '';
	}//end namesType()

	/**
	 * Refusals about `inputs` and `when`.
	 *
	 * @param ObjectActionEntry $action The declared action.
	 *
	 * @return list<string> The refusals.
	 */
	private static function inputRefusals(ObjectActionEntry $action): array {
		$definition = $action->definition;
		$refusals   = [];
		if (array_key_exists('inputs', $definition) === true && self::isObjectSchema(inputs: $definition['inputs']) === false) {
			$refusals[] = sprintf('Action "%s": "inputs" must be a JSON Schema of type "object".', $action->key);
		}

		if (array_key_exists('when', $definition) === true && FlowExpression::isValid(logic: $definition['when']) === false) {
			$refusals[] = sprintf('Action "%s": "when" is not valid JSONLogic.', $action->key);
		}

		return $refusals;
	}//end inputRefusals()

	/**
	 * Whether a value is a JSON Schema of type object.
	 *
	 * @param mixed $inputs The declared inputs.
	 *
	 * @return bool True when it is.
	 */
	private static function isObjectSchema(mixed $inputs): bool {
		if (is_array($inputs) === false || ($inputs['type'] ?? null) !== 'object') {
			return false;
		}

		return (array_key_exists('properties', $inputs) === false || is_array($inputs['properties']) === true);
	}//end isObjectSchema()

	/**
	 * Refusals about `surfaces`.
	 *
	 * @param ObjectActionEntry $action The declared action.
	 *
	 * @return list<string> The refusals.
	 */
	private static function surfaceRefusals(ObjectActionEntry $action): array {
		$surfaces = ($action->definition['surfaces'] ?? ObjectActionDefinition::DEFAULT_SURFACES);
		if (is_array($surfaces) === false || array_diff(array_map('strval', $surfaces), ObjectActionDefinition::SURFACES) !== []) {
			return [sprintf('Action "%s": "surfaces" may only name %s.', $action->key, implode(', ', ObjectActionDefinition::SURFACES))];
		}

		$inputs = ($action->definition['inputs'] ?? null);
		if (in_array('bulk', $surfaces, true) === true
			&& ObjectActionInputSchema::anyProperty(inputs: $inputs, test: ObjectActionInputSchema::defaultsFromObject(...)) === true
		) {
			return [sprintf('Action "%s": an input defaults from "@object", so the action cannot be offered on "bulk".', $action->key)];
		}

		return [];
	}//end surfaceRefusals()

	/**
	 * Refusals about `mode` and `next`.
	 *
	 * @param ObjectActionEntry $action The declared action.
	 *
	 * @return list<string> The refusals.
	 */
	private static function modeRefusals(ObjectActionEntry $action): array {
		$mode     = ($action->definition['mode'] ?? 'sync');
		$refusals = [];
		if (in_array($mode, ObjectActionDefinition::MODES, true) === false) {
			$refusals[] = sprintf('Action "%s": "mode" must be "sync" or "async".', $action->key);
		} else if ($mode === 'async') {
			$refusals = self::asyncRefusals(action: $action);
		}

		if (array_key_exists('next', $action->definition) === true && in_array($action->definition['next'], FlowNextHint::HINTS, true) === false) {
			$refusals[] = sprintf('Action "%s": "next" must be one of %s.', $action->key, implode(', ', FlowNextHint::HINTS));
		}

		return $refusals;
	}//end modeRefusals()

	/**
	 * The refusals an action declared `async` earns.
	 *
	 * The entry declares at most one body here, so a `transition` key is its body.
	 *
	 * @param ObjectActionEntry $action The declared action.
	 *
	 * @return list<string> The refusals.
	 */
	private static function asyncRefusals(ObjectActionEntry $action): array {
		if (array_key_exists(ObjectActionDefinition::KIND_TRANSITION, $action->definition) === true) {
			return [sprintf('Action "%s": a transition is one write and always runs sync.', $action->key)];
		}

		$inputs = ($action->definition['inputs'] ?? null);
		if (ObjectActionInputSchema::anyProperty(inputs: $inputs, test: ObjectActionInputSchema::isWriteOnly(...)) === true) {
			return [sprintf('Action "%s": a writeOnly input is never stored, so the action cannot run async.', $action->key)];
		}

		return [];
	}//end asyncRefusals()
}//end class
