<?php

/**
 * The bindings a schema's declared actions make to manual flows.
 *
 * A declared action already answers WHO may do it. Binding a flow to it keeps
 * that answer and adds no second permission model (D-1): the run executes as
 * the person, so a macro cannot do what its user cannot.
 *
 * ```json
 * "x-openregister-action": {
 *   "close-and-notify": {
 *     "name": "Close and notify",
 *     "description": "Close the case and tell the applicant.",
 *     "macro": true,
 *     "flow": "0f6a…-the flow's uuid"
 *   }
 * }
 * ```
 *
 * 🔴 THE SHAPE REFUSALS ARE HERE AND NOTHING ELSE IS. Whether the named flow
 * exists, is published and has a manual trigger is a question about OTHER
 * records, so it lives in {@see MacroActionValidator}. Keeping the two apart is
 * what lets the shape be checked without a database, and it is the reason a
 * malformed binding is refused identically wherever it is read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Reads and shape-checks the macro bindings on declared actions.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */
final class MacroActionBinding {

	/**
	 * The configuration key holding declared actions.
	 *
	 * @var string
	 */
	public const ACTION_BLOCK = 'x-openregister-action';

	/**
	 * Constructor.
	 *
	 * @param string $action The declared action key.
	 * @param string $flow   The flow the action runs.
	 */
	private function __construct(
		public readonly string $action,
		public readonly string $flow,
	) {
	}//end __construct()

	/**
	 * The macro bindings a schema configuration declares.
	 *
	 * Only well-formed bindings are returned. A malformed one is a REFUSAL, not
	 * a binding, and {@see refusals()} is what reports it: returning it here as
	 * well would let a caller act on a binding the save is about to reject.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return self[] The bindings, keyed by nothing; each carries its action.
	 *
	 * @psalm-return list<self>
	 */
	public static function parse(array $configuration): array {
		$bindings = [];
		foreach (self::declarations(configuration: $configuration) as $action => $definition) {
			$macro = ($definition['macro'] ?? false);
			$flow = ($definition['flow'] ?? null);

			if ($macro !== true || is_string($flow) === false || trim($flow) === '') {
				continue;
			}

			$bindings[] = new self(action: $action, flow: trim($flow));
		}

		return $bindings;
	}//end parse()

	/**
	 * The refusals the SHAPE of these declarations earns.
	 *
	 * Each names the action, because a schema can declare many and "a macro is
	 * misconfigured" sends the author looking through all of them.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return string[] The refusals, empty when the shape is sound.
	 *
	 * @psalm-return list<string>
	 */
	public static function refusals(array $configuration): array {
		$refusals = [];
		foreach (self::declarations(configuration: $configuration) as $action => $definition) {
			$hasMacro = array_key_exists('macro', $definition);
			$hasFlow = array_key_exists('flow', $definition);

			if ($hasMacro === false && $hasFlow === false) {
				continue;
			}

			if ($hasMacro === true && is_bool($definition['macro']) === false) {
				$refusals[] = sprintf('Action "%s": "macro" must be true or false.', $action);
			}

			if ($hasFlow === true
				&& (is_string($definition['flow']) === false || trim((string)$definition['flow']) === '')
			) {
				$refusals[] = sprintf('Action "%s": "flow" must name a flow.', $action);
				continue;
			}

			// A macro with nothing to run is the mistake this refusal exists
			// for: the action would save, appear in the menu, and do nothing
			// when clicked, which is indistinguishable from a flow that ran and
			// changed nothing.
			if (($definition['macro'] ?? false) === true && $hasFlow === false) {
				$refusals[] = sprintf('Action "%s": "macro" is true but no "flow" is named.', $action);
			}

			// The mirror: a flow nothing will ever run. Saved quietly, it reads
			// as a bound macro to anyone looking at the schema afterwards.
			if ($hasFlow === true && ($definition['macro'] ?? false) !== true) {
				$refusals[] = sprintf('Action "%s": "flow" is named but "macro" is not true.', $action);
			}
		}//end foreach

		return $refusals;
	}//end refusals()

	/**
	 * The declared-action definitions, normalised.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return array<string, array<string, mixed>> action key => definition.
	 */
	private static function declarations(array $configuration): array {
		$declared = ($configuration[self::ACTION_BLOCK] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$definitions = [];
		foreach ($declared as $action => $definition) {
			if (is_string($action) === false || $action === '' || is_array($definition) === false) {
				continue;
			}

			$definitions[$action] = $definition;
		}

		return $definitions;
	}//end declarations()
}//end class
