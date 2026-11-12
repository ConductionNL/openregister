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
	 * Whether the binding names a stored flow by uuid, rather than a flow its
	 * schema declares by name (`x-openregister-flows`).
	 *
	 * @param string $flow The binding's `flow`.
	 *
	 * @return bool True for a uuid.
	 *
	 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-can-name-a-flow-its-schema-declares
	 */
	public static function namesUuid(string $flow): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $flow) === 1;
	}//end namesUuid()

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
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public static function parse(array $configuration): array {
		$bindings = [];
		foreach (self::declarations(configuration: $configuration) as $action => $definition) {
			$macro = ($definition['macro'] ?? null);
			$flow = ($definition['flow'] ?? null);

			if ($macro === false || is_string($flow) === false || trim($flow) === '') {
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
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public static function refusals(array $configuration): array {
		$refusals = [];
		foreach (self::declarations(configuration: $configuration) as $action => $definition) {
			$refusals = array_merge(
				$refusals,
				self::refusalsFor(action: $action, definition: $definition)
			);
		}//end foreach

		return $refusals;
	}//end refusals()

	/**
	 * Why ONE declared action's macro binding may not be saved.
	 *
	 * An unnamed flow stops the checks after it: the pairing refusals are
	 * about a flow that IS named, and reporting them as well would name the
	 * same mistake twice.
	 *
	 * @param string               $action     The action key.
	 * @param array<string, mixed> $definition The action's definition.
	 *
	 * @return string[] The refusals, empty when this action's shape is sound.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	private static function refusalsFor(string $action, array $definition): array {
		if (array_key_exists('macro', $definition) === false && array_key_exists('flow', $definition) === false) {
			return [];
		}

		$refusals = self::macroTypeRefusals(action: $action, definition: $definition);
		if (self::namesNoFlow(definition: $definition) === true) {
			$refusals[] = sprintf('Action "%s": "flow" must name a flow.', $action);
			return $refusals;
		}

		return array_merge($refusals, self::pairingRefusals(action: $action, definition: $definition));
	}//end refusalsFor()

	/**
	 * Why a present `macro` key is not a boolean.
	 *
	 * @param string               $action     The action key.
	 * @param array<string, mixed> $definition The action's definition.
	 *
	 * @return string[] The refusal, or none.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	private static function macroTypeRefusals(string $action, array $definition): array {
		if (array_key_exists('macro', $definition) === true && is_bool($definition['macro']) === false) {
			return [sprintf('Action "%s": "macro" must be true or false.', $action)];
		}

		return [];
	}//end macroTypeRefusals()

	/**
	 * Whether a present `flow` key names nothing usable.
	 *
	 * @param array<string, mixed> $definition The action's definition.
	 *
	 * @return bool True when `flow` is present but empty or not a string.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	private static function namesNoFlow(array $definition): bool {
		if (array_key_exists('flow', $definition) === false) {
			return false;
		}

		return (is_string($definition['flow']) === false || trim((string)$definition['flow']) === '');
	}//end namesNoFlow()

	/**
	 * Why a macro and its flow do not pair up.
	 *
	 * A macro with nothing to run would save, appear in the menu and do
	 * nothing when clicked. A named flow is the action's body on its own
	 * (object-actions D-1); only an explicit `macro: false` beside it
	 * contradicts itself.
	 *
	 * @param string               $action     The action key.
	 * @param array<string, mixed> $definition The action's definition.
	 *
	 * @return string[] The refusal, or none when the pair is sound.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	private static function pairingRefusals(string $action, array $definition): array {
		if (array_key_exists('flow', $definition) === true) {
			return self::refusedIf(
				refused: ($definition['macro'] ?? null) === false,
				refusal: sprintf('Action "%s": "flow" is named but "macro" is false.', $action)
			);
		}

		return self::refusedIf(
			refused: ($definition['macro'] ?? null) === true,
			refusal: sprintf('Action "%s": "macro" is true but no "flow" is named.', $action)
		);
	}//end pairingRefusals()

	/**
	 * The refusal as a list when it applies, else none.
	 *
	 * @param bool   $refused Whether the refusal applies.
	 * @param string $refusal The refusal.
	 *
	 * @return string[] The refusal, or none.
	 *
	 * @psalm-return list<string>
	 */
	private static function refusedIf(bool $refused, string $refusal): array {
		if ($refused === true) {
			return [$refusal];
		}

		return [];
	}//end refusedIf()

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

		return array_filter($declared, self::isDeclaration(...), ARRAY_FILTER_USE_BOTH);
	}//end declarations()

	/**
	 * Whether an entry of the action block is a definition under a usable key.
	 *
	 * @param mixed $definition The entry.
	 * @param mixed $action     Its key.
	 *
	 * @return bool True for an array under a non-empty string key.
	 */
	private static function isDeclaration(mixed $definition, mixed $action): bool {
		return is_string($action) === true && $action !== '' && is_array($definition) === true;
	}//end isDeclaration()
}//end class
