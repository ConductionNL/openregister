<?php

/**
 * Reads the parts of one declared action out of its raw configuration entry.
 *
 * {@see ObjectActionDefinition} is read by most of the object-action code;
 * the reading itself lives here so the value stays a value.
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

/**
 * Stateless reader of declared-action entries.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionDeclarationReader {

	/**
	 * The body keys, in the order the first present one becomes the kind.
	 *
	 * @var list<string>
	 */
	private const BODIES = [
		ObjectActionDefinition::KIND_TRANSITION,
		ObjectActionDefinition::KIND_FLOW,
		ObjectActionDefinition::KIND_STEPS,
		ObjectActionDefinition::KIND_SELF_ASSIGN,
	];

	/**
	 * The key, label, description and icon of an entry.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return ObjectActionIdentity The identity.
	 */
	public static function identity(string $key, array $definition): ObjectActionIdentity {
		return new ObjectActionIdentity(
			key: $key,
			label: self::labelOf(key: $key, definition: $definition),
			description: self::stringOrNull(value: ($definition['description'] ?? null)),
			icon: self::stringOrNull(value: ($definition['icon'] ?? null))
		);
	}//end identity()

	/**
	 * The surfaces, inputs and precondition of an entry.
	 *
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return ObjectActionOffer The offer.
	 */
	public static function offer(array $definition): ObjectActionOffer {
		$inputs = null;
		if (is_array($definition['inputs'] ?? null) === true) {
			$inputs = $definition['inputs'];
		}

		return new ObjectActionOffer(
			surfaces: self::surfacesOf(definition: $definition),
			inputs: $inputs,
			when: ($definition['when'] ?? null),
			whenMessage: ($definition['whenMessage'] ?? null)
		);
	}//end offer()

	/**
	 * The body kind, body, mode and `next` hint of an entry.
	 *
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return ObjectActionBody The body.
	 */
	public static function body(array $definition): ObjectActionBody {
		$kind = (self::bodiesOf(definition: $definition)[0] ?? null);
		$body = null;
		if ($kind !== null) {
			$body = $definition[$kind];
		}

		return new ObjectActionBody(
			mode: (string)($definition['mode'] ?? 'sync'),
			next: self::stringOrNull(value: ($definition['next'] ?? null)),
			kind: $kind,
			body: $body
		);
	}//end body()

	/**
	 * The confirmation, elevations and reversibility of an entry.
	 *
	 * @param array<string, mixed>                                         $definition The declared entry.
	 * @param list<array{register: string, schema: string, verbs: list<string>}> $elevate    The entry's elevations, already read.
	 *
	 * @return ObjectActionRunPolicy The policy.
	 */
	public static function policy(array $definition, array $elevate): ObjectActionRunPolicy {
		return new ObjectActionRunPolicy(
			confirm: ($definition['confirm'] ?? null),
			elevate: $elevate,
			reversible: (($definition['reversible'] ?? false) === true)
		);
	}//end policy()

	/**
	 * The body keys a declaration carries, in kind order.
	 *
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return list<string> The body keys present.
	 */
	public static function bodiesOf(array $definition): array {
		return array_values(array_filter(self::BODIES, static fn (string $body): bool => array_key_exists($body, $definition)));
	}//end bodiesOf()

	/**
	 * The declared entries of a configuration, keyed by action.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return array<string, array<string, mixed>> Action key => entry.
	 */
	public static function declarations(array $configuration): array {
		$declared = ($configuration[ObjectActionDefinition::ACTION_BLOCK] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		return array_filter(
			$declared,
			static fn (mixed $definition, int|string $key): bool => is_string($key) === true && $key !== '' && is_array($definition) === true,
			ARRAY_FILTER_USE_BOTH
		);
	}//end declarations()

	/**
	 * The label: `label`, else `name`, else the key; anything but a string or map is the key.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return string|array<string, string> The label.
	 */
	private static function labelOf(string $key, array $definition): string|array {
		$label = ($definition['label'] ?? ($definition['name'] ?? $key));
		if (is_string($label) === false && is_array($label) === false) {
			return $key;
		}

		return $label;
	}//end labelOf()

	/**
	 * The declared surfaces as strings, or the default surfaces.
	 *
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return list<string> The surfaces.
	 */
	private static function surfacesOf(array $definition): array {
		if (is_array($definition['surfaces'] ?? null) === false) {
			return ObjectActionDefinition::DEFAULT_SURFACES;
		}

		return array_values(array_map('strval', $definition['surfaces']));
	}//end surfacesOf()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
