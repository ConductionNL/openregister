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
	 * @param string                    $key           The action key.
	 * @param array<string, mixed>      $definition    The declared entry.
	 * @param array<string, mixed>      $lifecycle     The schema lifecycle; [] skips the transition check.
	 * @param array<string, mixed>|null $authorization The authorization block; null skips the elevate grant check.
	 *
	 * @return list<string> The refusals.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function refusals(string $key, array $definition, array $lifecycle, ?array $authorization): array {
		$bodies = ObjectActionDefinition::bodiesOf(definition: $definition);
		if (count($bodies) > 1) {
			return [sprintf('Action "%s": an action has one body, but this one declares %s.', $key, implode(' and ', $bodies))];
		}

		$kind = ($bodies[0] ?? null);

		return array_values(
			array_merge(
				self::bodyRefusals(key: $key, kind: $kind, definition: $definition, lifecycle: $lifecycle),
				self::inputRefusals(key: $key, definition: $definition),
				self::surfaceRefusals(key: $key, definition: $definition),
				self::modeRefusals(key: $key, kind: $kind, definition: $definition),
				ObjectActionElevation::refusals(key: $key, definition: $definition, authorization: $authorization)
			)
		);
	}//end refusals()

	/**
	 * Refusals about the body itself.
	 *
	 * @param string               $key        The action key.
	 * @param string|null          $kind       The body kind.
	 * @param array<string, mixed> $definition The declared entry.
	 * @param array<string, mixed> $lifecycle  The schema lifecycle; [] skips the transition check.
	 *
	 * @return list<string> The refusals.
	 */
	private static function bodyRefusals(string $key, ?string $kind, array $definition, array $lifecycle): array {
		if ($kind === ObjectActionDefinition::KIND_TRANSITION) {
			return self::transitionRefusals(key: $key, transition: $definition[$kind], lifecycle: $lifecycle);
		}

		if ($kind === ObjectActionDefinition::KIND_STEPS) {
			return self::stepsRefusals(key: $key, steps: $definition[$kind]);
		}

		return [];
	}//end bodyRefusals()

	/**
	 * Refusals about a `transition` body.
	 *
	 * A lifecycle in `provider` or `graph` mode answers its transitions at run
	 * time, so only a static `transitions` map can be checked here.
	 *
	 * @param string               $key        The action key.
	 * @param mixed                $transition The declared transition key.
	 * @param array<string, mixed> $lifecycle  The schema lifecycle; [] skips the check.
	 *
	 * @return list<string> The refusals.
	 */
	private static function transitionRefusals(string $key, mixed $transition, array $lifecycle): array {
		if (is_string($transition) === false || trim($transition) === '') {
			return [sprintf('Action "%s": "transition" must name a lifecycle transition.', $key)];
		}

		$transitions = ($lifecycle['transitions'] ?? []);
		if (is_array($transitions) === false || $transitions === []) {
			return [];
		}

		if (array_key_exists($transition, $transitions) === false) {
			return [sprintf('Action "%s": the lifecycle declares no transition "%s".', $key, $transition)];
		}

		return [];
	}//end transitionRefusals()

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
		if (is_array($steps) === false || $steps === [] || array_is_list($steps) === false) {
			return [sprintf('Action "%s": "steps" must be a non-empty list of flow steps.', $key)];
		}

		foreach ($steps as $index => $step) {
			$refusal = self::stepRefusal(key: $key, position: ($index + 1), step: $step);
			if ($refusal !== null) {
				return [$refusal];
			}
		}

		return [];
	}//end stepsRefusals()

	/**
	 * The refusal one step earns.
	 *
	 * @param string $key      The action key.
	 * @param int    $position The step's position, from 1.
	 * @param mixed  $step     The step.
	 *
	 * @return string|null The refusal.
	 */
	private static function stepRefusal(string $key, int $position, mixed $step): ?string {
		if (is_array($step) === false || is_string($step['type'] ?? null) === false || trim((string)$step['type']) === '') {
			return sprintf('Action "%s": step %d must name its node "type".', $key, $position);
		}

		if (array_key_exists('config', $step) === true && is_array($step['config']) === false) {
			return sprintf('Action "%s": step %d has a "config" that is not an object.', $key, $position);
		}

		return null;
	}//end stepRefusal()

	/**
	 * Refusals about `inputs` and `when`.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return list<string> The refusals.
	 */
	private static function inputRefusals(string $key, array $definition): array {
		$refusals = [];
		if (array_key_exists('inputs', $definition) === true && self::isObjectSchema(inputs: $definition['inputs']) === false) {
			$refusals[] = sprintf('Action "%s": "inputs" must be a JSON Schema of type "object".', $key);
		}

		if (array_key_exists('when', $definition) === true && FlowExpression::isValid(logic: $definition['when']) === false) {
			$refusals[] = sprintf('Action "%s": "when" is not valid JSONLogic.', $key);
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
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return list<string> The refusals.
	 */
	private static function surfaceRefusals(string $key, array $definition): array {
		$surfaces = ($definition['surfaces'] ?? ObjectActionDefinition::DEFAULT_SURFACES);
		if (is_array($surfaces) === false || array_diff(array_map('strval', $surfaces), ObjectActionDefinition::SURFACES) !== []) {
			return [sprintf('Action "%s": "surfaces" may only name %s.', $key, implode(', ', ObjectActionDefinition::SURFACES))];
		}

		if (in_array('bulk', $surfaces, true) === true
			&& ObjectActionInputSchema::anyProperty(inputs: ($definition['inputs'] ?? null), test: ObjectActionInputSchema::defaultsFromObject(...)) === true
		) {
			return [sprintf('Action "%s": an input defaults from "@object", so the action cannot be offered on "bulk".', $key)];
		}

		return [];
	}//end surfaceRefusals()

	/**
	 * Refusals about `mode` and `next`.
	 *
	 * @param string               $key        The action key.
	 * @param string|null          $kind       The body kind.
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return list<string> The refusals.
	 */
	private static function modeRefusals(string $key, ?string $kind, array $definition): array {
		$refusals = [];
		$mode = ($definition['mode'] ?? 'sync');
		$writeOnly = ObjectActionInputSchema::anyProperty(inputs: ($definition['inputs'] ?? null), test: ObjectActionInputSchema::isWriteOnly(...));
		if (in_array($mode, ObjectActionDefinition::MODES, true) === false) {
			$refusals[] = sprintf('Action "%s": "mode" must be "sync" or "async".', $key);
		} else if ($mode === 'async' && $kind === ObjectActionDefinition::KIND_TRANSITION) {
			$refusals[] = sprintf('Action "%s": a transition is one write and always runs sync.', $key);
		} else if ($mode === 'async' && $writeOnly === true) {
			$refusals[] = sprintf('Action "%s": a writeOnly input is never stored, so the action cannot run async.', $key);
		}

		if (array_key_exists('next', $definition) === true && in_array($definition['next'], FlowNextHint::HINTS, true) === false) {
			$refusals[] = sprintf('Action "%s": "next" must be one of %s.', $key, implode(', ', FlowNextHint::HINTS));
		}

		return $refusals;
	}//end modeRefusals()
}//end class
