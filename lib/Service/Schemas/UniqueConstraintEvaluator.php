<?php

/**
 * OpenRegister UniqueConstraintEvaluator
 *
 * A schema declares uniqueness over a NAMED combination of properties, and
 * says what a breach does: refuse the save, or let it through and record it
 * (design.md D-7).
 *
 * Both actions are real, and that is the whole reason the action is declared
 * rather than assumed. A gemeente wants one bezwaar per besluit per indiener
 * REFUSED, because a second one is a mistake. It wants two contacts with the
 * same e-mail REPORTED, because a second one is usually a duplicate and
 * occasionally a household, and blocking the intake over it costs more than
 * the duplicate does.
 *
 * The existing `configuration.unique` key keeps working exactly as it did: one
 * combination, refuse on breach, no name. It is read here as an unnamed
 * `refuse` constraint so there is one evaluator rather than two that drift.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Reads and evaluates a schema's uniqueness constraints.
 */
class UniqueConstraintEvaluator {

	/**
	 * The schema configuration key holding the named constraints.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'uniqueConstraints';

	/**
	 * The pre-existing single-combination key, read as an unnamed refusal.
	 *
	 * @var string
	 */
	public const LEGACY_CONFIG_KEY = 'unique';

	/**
	 * Refuse the save on a breach.
	 *
	 * @var string
	 */
	public const ACTION_REFUSE = 'refuse';

	/**
	 * Let the save through and record the breach.
	 *
	 * @var string
	 */
	public const ACTION_REPORT = 'report';

	/**
	 * The constraints a schema configuration declares.
	 *
	 * Each is `{name, properties, action}`. A constraint naming no property,
	 * or an action that is neither refuse nor report, is dropped rather than
	 * guessed at: a malformed constraint that quietly became a `report` would
	 * be a refusal someone believes they configured and did not get.
	 *
	 * The pre-existing `unique` key is read only when `$includeLegacy` asks
	 * for it. The write path that already enforces it keeps doing so alone, so
	 * adding this evaluator does not give one mistake two different messages.
	 *
	 * @param array<string,mixed>|null $configuration The schema's configuration block.
	 * @param boolean $includeLegacy Whether to also read the pre-existing `unique` key.
	 *
	 * @return array<int,array{name:string,properties:array<int,string>,action:string}>
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function constraints(?array $configuration, bool $includeLegacy = false): array {
		if ($configuration === null) {
			return [];
		}

		$constraints = [];

		if ($includeLegacy === true) {
			$legacyProperties = $this->propertyList(value: ($configuration[self::LEGACY_CONFIG_KEY] ?? null));
			if ($legacyProperties !== []) {
				$constraints[] = [
					'name' => implode('+', $legacyProperties),
					'properties' => $legacyProperties,
					'action' => self::ACTION_REFUSE,
				];
			}
		}

		$declared = ($configuration[self::CONFIG_KEY] ?? null);
		if (is_array($declared) === false) {
			return $constraints;
		}

		foreach ($declared as $key => $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$properties = $this->propertyList(value: ($entry['properties'] ?? null));
			if ($properties === []) {
				continue;
			}

			$action = strtolower(trim((string)($entry['action'] ?? self::ACTION_REFUSE)));
			if (in_array($action, [self::ACTION_REFUSE, self::ACTION_REPORT], true) === false) {
				continue;
			}

			$name = trim((string)($entry['name'] ?? ''));
			if ($name === '' && is_string($key) === true) {
				$name = $key;
			} elseif ($name === '') {
				$name = implode('+', $properties);
			}

			$constraints[] = [
				'name' => $name,
				'properties' => $properties,
				'action' => $action,
			];
		}//end foreach

		return $constraints;
	}//end constraints()

	/**
	 * The filters that find the objects breaching one constraint.
	 *
	 * A constraint over a property the object does not carry cannot be
	 * breached: the combination is not complete, so there is nothing to
	 * compare. Returning null says exactly that, rather than returning a
	 * filter on null which would match every object that also omits it.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 * @param array<string,mixed> $object The submitted object data.
	 *
	 * @return array<string,mixed>|null The filters, or null when the constraint does not apply.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function filtersFor(array $constraint, array $object): ?array {
		$filters = [];
		foreach ($constraint['properties'] as $property) {
			if (array_key_exists($property, $object) === false) {
				return null;
			}

			$value = $object[$property];
			if ($value === null || $value === '') {
				return null;
			}

			$filters[$property] = $value;
		}

		if ($filters === []) {
			return null;
		}

		return $filters;
	}//end filtersFor()

	/**
	 * The breach, in words, naming the combination and the conflicting object.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 * @param array<string,mixed> $object The submitted object data.
	 * @param string|null $conflictingId The uuid of an object already holding the combination.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function describeBreach(array $constraint, array $object, ?string $conflictingId): string {
		$pairs = [];
		foreach ($constraint['properties'] as $property) {
			$pairs[] = $property . '=' . $this->readable(value: ($object[$property] ?? null));
		}

		$message = sprintf(
			'The combination %s (%s) is already in use.',
			implode(' and ', $constraint['properties']),
			implode(', ', $pairs)
		);

		if ($conflictingId !== null && $conflictingId !== '') {
			$message .= ' It is held by object ' . $conflictingId . '.';
		}

		return $message;
	}//end describeBreach()

	/**
	 * Normalise a declared property list.
	 *
	 * @param mixed $value A property name, or a list of them.
	 *
	 * @return array<int,string> The property names.
	 */
	private function propertyList(mixed $value): array {
		if (is_string($value) === true) {
			$value = trim($value);

			if ($value === '') {
				return [];
			}

			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$properties = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$properties[] = trim($entry);
			}
		}

		return $properties;
	}//end propertyList()

	/**
	 * A value rendered for a message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The rendering.
	 */
	private function readable(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		if ($value === null) {
			return 'null';
		}

		return (string)json_encode($value);
	}//end readable()
}//end class
