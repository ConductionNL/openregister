<?php

/**
 * SchemaDiffService — structural diff and change classification for schemas.
 *
 * Pure, dependency-free service that compares two schema definitions
 * (the `properties` map, the `required` list and per-property constraint
 * keywords) and produces a {@see SchemaChangeSet}: a typed change list, a
 * `compatible` / `breaking` classification and the resulting semantic
 * version bump.
 *
 * Classification is purely structural — no heuristics. A rename is only
 * recognised when the caller declares it (via the optional rename map);
 * otherwise a rename reads structurally as remove + add, both breaking,
 * which is the safe default. Because the service touches no database or
 * framework, every classification rule is unit-testable in isolation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * Structural schema diff + classification.
 */
class SchemaDiffService {

	/**
	 * Constraint keywords whose tightening is breaking and whose
	 * relaxation is compatible. The boolean is the "direction" hint:
	 * true  = a larger numeric value tightens (e.g. minLength, minItems),
	 * false = a smaller numeric value tightens (e.g. maxLength, maxItems).
	 *
	 * @var array<string, bool>
	 */
	private const NUMERIC_BOUND_TIGHTENS_UP = [
		'minLength' => true,
		'minItems' => true,
		'minimum' => true,
		'minProperties' => true,
		'maxLength' => false,
		'maxItems' => false,
		'maximum' => false,
		'maxProperties' => false,
	];

	/**
	 * Diff two schema definitions and classify the change set.
	 *
	 * Each definition is the schema's serialised shape, at minimum a
	 * `properties` map (property name => definition array) and an
	 * optional `required` list of property names.
	 *
	 * @param array<string, mixed> $old Previous definition.
	 * @param array<string, mixed> $new New definition.
	 * @param array<string, string> $renames Optional declared renames
	 *                                       (old name => new name). When
	 *                                       a property is declared renamed
	 *                                       it is classified as a single
	 *                                       `renamed` (breaking) change
	 *                                       rather than remove + add.
	 *
	 * @return SchemaChangeSet The typed, classified change set.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function diff(array $old, array $new, array $renames = []): SchemaChangeSet {
		$oldProps = $this->properties(definition: $old);
		$newProps = $this->properties(definition: $new);
		$oldReq = $this->requiredSet(definition: $old);
		$newReq = $this->requiredSet(definition: $new);

		$changes = [];
		$hasBreaking = false;
		$hasAddedProp = false;
		$hasOtherChange = false;

		// Map renamed-away source names so they are not double-counted as removals.
		$renamedFrom = array_keys($renames);
		$renamedTo = array_values($renames);

		// Declared renames first.
		foreach ($renames as $from => $to) {
			if (array_key_exists($from, $oldProps) === false || array_key_exists($to, $newProps) === false) {
				// The declared rename does not match the definitions; treat as
				// ordinary add/remove handled below.
				continue;
			}

			$changes[] = [
				'property' => $from,
				'kind' => 'renamed',
				'old' => $from,
				'new' => $to,
			];
			$hasBreaking = true;
		}

		// Removed properties (not accounted for by a rename).
		foreach ($oldProps as $name => $def) {
			if (array_key_exists($name, $newProps) === true) {
				continue;
			}

			if (in_array($name, $renamedFrom, true) === true) {
				continue;
			}

			$changes[] = [
				'property' => $name,
				'kind' => 'removed',
				'old' => $def,
			];
			$hasBreaking = true;
		}

		// Added properties (not the target of a rename).
		foreach ($newProps as $name => $def) {
			if (array_key_exists($name, $oldProps) === true) {
				continue;
			}

			if (in_array($name, $renamedTo, true) === true) {
				continue;
			}

			$isRequired = in_array($name, $newReq, true);
			$hasDefault = array_key_exists('default', (array)$def);
			$changes[] = [
				'property' => $name,
				'kind' => 'added',
				'new' => $def,
			];

			if ($isRequired === true && $hasDefault === false) {
				// New required property with no default invalidates every
				// existing object that lacks it.
				$changes[] = [
					'property' => $name,
					'kind' => 'added_required_no_default',
					'new' => $def,
				];
				$hasBreaking = true;
			} else {
				$hasAddedProp = true;
			}
		}//end foreach

		// Changed properties (present in both).
		foreach ($newProps as $name => $newDef) {
			if (array_key_exists($name, $oldProps) === false) {
				continue;
			}

			$propChanges = $this->diffProperty(name: $name, oldDef: (array)$oldProps[$name], newDef: (array)$newDef);
			foreach ($propChanges as $pc) {
				$changes[] = $pc;
				if ($this->kindIsBreaking(kind: $pc['kind']) === true) {
					$hasBreaking = true;
				} else {
					$hasOtherChange = true;
				}
			}
		}

		// Required-set changes for properties that exist in both definitions.
		foreach ($newReq as $name) {
			if (in_array($name, $oldReq, true) === true) {
				continue;
			}

			// Newly required. Already handled above if it is also a new property.
			if (array_key_exists($name, $oldProps) === false) {
				continue;
			}

			$hasDefault = array_key_exists('default', (array)($newProps[$name] ?? []));
			if ($hasDefault === true) {
				$changes[] = [
					'property' => $name,
					'kind' => 'required_added_with_default',
				];
				$hasOtherChange = true;
			} else {
				$changes[] = [
					'property' => $name,
					'kind' => 'required_added',
				];
				$hasBreaking = true;
			}
		}//end foreach

		foreach ($oldReq as $name) {
			if (in_array($name, $newReq, true) === true) {
				continue;
			}

			if (array_key_exists($name, $newProps) === false) {
				// Property removed entirely; already counted as removed.
				continue;
			}

			// Relaxing a required constraint is compatible.
			$changes[] = [
				'property' => $name,
				'kind' => 'required_removed',
			];
			$hasOtherChange = true;
		}

		return $this->classify(changes: $changes, hasBreaking: $hasBreaking, hasAddedProp: $hasAddedProp, hasOtherChange: $hasOtherChange);
	}//end diff()

	/**
	 * Compute the next version string given the current one and a change set.
	 *
	 * Follows semantic versioning: breaking → major, added property →
	 * minor, any other compatible change → patch, no change → unchanged.
	 *
	 * @param string|null $currentVersion Current version (defaults to 0.0.0).
	 * @param SchemaChangeSet $changeSet The classified change set.
	 *
	 * @return string The next version string.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function nextVersion(?string $currentVersion, SchemaChangeSet $changeSet): string {
		$parts = $this->parseVersion(version: $currentVersion);

		switch ($changeSet->getBump()) {
			case 'major':
				$parts[0]++;
				$parts[1] = 0;
				$parts[2] = 0;
				break;
			case 'minor':
				$parts[1]++;
				$parts[2] = 0;
				break;
			case 'patch':
				$parts[2]++;
				break;
			case 'none':
			default:
				// No bump.
				break;
		}

		return implode('.', $parts);
	}//end nextVersion()

	/**
	 * Diff a single property definition.
	 *
	 * @param string $name Property name.
	 * @param array<string, mixed> $oldDef Old property definition.
	 * @param array<string, mixed> $newDef New property definition.
	 *
	 * @return array<int, array<string, mixed>> Typed changes for this property.
	 */
	private function diffProperty(string $name, array $oldDef, array $newDef): array {
		$changes = [];

		$oldType = $oldDef['type'] ?? null;
		$newType = $newDef['type'] ?? null;
		if ($this->normaliseType(type: $oldType) !== $this->normaliseType(type: $newType)) {
			$changes[] = [
				'property' => $name,
				'kind' => 'type_changed',
				'old' => $oldType,
				'new' => $newType,
			];
		}

		// Constraint changes.
		foreach (self::NUMERIC_BOUND_TIGHTENS_UP as $keyword => $tightensUp) {
			$hadOld = array_key_exists($keyword, $oldDef);
			$hasNew = array_key_exists($keyword, $newDef);

			if ($hadOld === false && $hasNew === false) {
				continue;
			}

			if ($hadOld === false && $hasNew === true) {
				// Newly introduced bound = tightening.
				$changes[] = [
					'property' => $name,
					'kind' => 'constraint_tightened',
					'old' => null,
					'new' => [$keyword => $newDef[$keyword]],
				];
				continue;
			}

			if ($hadOld === true && $hasNew === false) {
				// Removed bound = relaxation.
				$changes[] = [
					'property' => $name,
					'kind' => 'constraint_relaxed',
					'old' => [$keyword => $oldDef[$keyword]],
					'new' => null,
				];
				continue;
			}

			$oldVal = $oldDef[$keyword];
			$newVal = $newDef[$keyword];
			if ($oldVal === $newVal) {
				continue;
			}

			$tighter = $this->boundTightened(oldVal: (float)$oldVal, newVal: (float)$newVal, tightensUp: $tightensUp);
			if ($tighter === true) {
				$kind = 'constraint_tightened';
			} else {
				$kind = 'constraint_relaxed';
			}

			$changes[] = [
				'property' => $name,
				'kind' => $kind,
				'old' => [$keyword => $oldVal],
				'new' => [$keyword => $newVal],
			];
		}//end foreach

		// Enum narrowing/widening.
		$enumChange = $this->diffEnum(name: $name, oldDef: $oldDef, newDef: $newDef);
		if ($enumChange !== null) {
			$changes[] = $enumChange;
		}

		// Pattern / format introduction = tightening.
		foreach (['pattern', 'format'] as $keyword) {
			$oldHas = array_key_exists($keyword, $oldDef);
			$newHas = array_key_exists($keyword, $newDef);
			if ($oldHas === false && $newHas === true) {
				$changes[] = [
					'property' => $name,
					'kind' => 'constraint_tightened',
					'old' => null,
					'new' => [$keyword => $newDef[$keyword]],
				];
			} elseif ($oldHas === true && $newHas === false) {
				$changes[] = [
					'property' => $name,
					'kind' => 'constraint_relaxed',
					'old' => [$keyword => $oldDef[$keyword]],
					'new' => null,
				];
			} elseif ($oldHas === true && $newHas === true && $oldDef[$keyword] !== $newDef[$keyword]) {
				// A changed pattern/format is conservatively breaking.
				$changes[] = [
					'property' => $name,
					'kind' => 'constraint_tightened',
					'old' => [$keyword => $oldDef[$keyword]],
					'new' => [$keyword => $newDef[$keyword]],
				];
			}//end if
		}//end foreach

		return $changes;
	}//end diffProperty()

	/**
	 * Diff an enum constraint.
	 *
	 * @param string $name Property name.
	 * @param array<string, mixed> $oldDef Old definition.
	 * @param array<string, mixed> $newDef New definition.
	 *
	 * @return array<string, mixed>|null The change, or null if no enum change.
	 */
	private function diffEnum(string $name, array $oldDef, array $newDef): ?array {
		$oldEnum = $oldDef['enum'] ?? null;
		$newEnum = $newDef['enum'] ?? null;

		if ($oldEnum === null && $newEnum === null) {
			return null;
		}

		if (is_array($oldEnum) === false && is_array($newEnum) === true) {
			// Newly constrained to a fixed set = tightening.
			return [
				'property' => $name,
				'kind' => 'constraint_tightened',
				'old' => null,
				'new' => ['enum' => $newEnum],
			];
		}

		if (is_array($oldEnum) === true && is_array($newEnum) === false) {
			// Enum dropped = relaxation.
			return [
				'property' => $name,
				'kind' => 'constraint_relaxed',
				'old' => ['enum' => $oldEnum],
				'new' => null,
			];
		}

		// Both arrays — compare membership.
		$removed = array_diff($oldEnum, $newEnum);
		$added = array_diff($newEnum, $oldEnum);

		if (count($removed) === 0 && count($added) === 0) {
			return null;
		}

		if (count($removed) > 0) {
			// Removing allowed values can invalidate existing data.
			return [
				'property' => $name,
				'kind' => 'constraint_tightened',
				'old' => ['enum' => $oldEnum],
				'new' => ['enum' => $newEnum],
			];
		}

		// Only widened.
		return [
			'property' => $name,
			'kind' => 'constraint_relaxed',
			'old' => ['enum' => $oldEnum],
			'new' => ['enum' => $newEnum],
		];

	}//end diffEnum()

	/**
	 * Whether a numeric bound moved in the tightening direction.
	 *
	 * @param float $oldVal Old value.
	 * @param float $newVal New value.
	 * @param bool $tightensUp True when increasing the value tightens.
	 *
	 * @return bool True when the bound tightened.
	 */
	private function boundTightened(float $oldVal, float $newVal, bool $tightensUp): bool {
		if ($tightensUp === true) {
			return $newVal > $oldVal;
		}

		return $newVal < $oldVal;
	}//end boundTightened()

	/**
	 * Classify the accumulated changes into a SchemaChangeSet.
	 *
	 * @param array<int, array<string, mixed>> $changes The change list.
	 * @param bool $hasBreaking Any breaking change present.
	 * @param bool $hasAddedProp Any added optional property.
	 * @param bool $hasOtherChange Any other compatible change.
	 *
	 * @return SchemaChangeSet The classified change set.
	 */
	private function classify(array $changes, bool $hasBreaking, bool $hasAddedProp, bool $hasOtherChange): SchemaChangeSet {
		if (count($changes) === 0) {
			return new SchemaChangeSet([], SchemaChangeSet::CLASS_NONE, 'none');
		}

		if ($hasBreaking === true) {
			return new SchemaChangeSet($changes, SchemaChangeSet::CLASS_BREAKING, 'major');
		}

		if ($hasAddedProp === true) {
			return new SchemaChangeSet($changes, SchemaChangeSet::CLASS_COMPATIBLE, 'minor');
		}

		// Other compatible change (relaxed constraint, dropped requirement).
		return new SchemaChangeSet($changes, SchemaChangeSet::CLASS_COMPATIBLE, 'patch');
	}//end classify()

	/**
	 * Whether a change kind is breaking.
	 *
	 * @param string $kind The change kind.
	 *
	 * @return bool True when breaking.
	 */
	private function kindIsBreaking(string $kind): bool {
		return in_array(
			$kind,
			[
				'removed',
				'renamed',
				'type_changed',
				'constraint_tightened',
				'required_added',
				'added_required_no_default',
			],
			true
		);

	}//end kindIsBreaking()

	/**
	 * Extract the properties map from a definition.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<string, mixed> The properties map.
	 */
	private function properties(array $definition): array {
		$props = ($definition['properties'] ?? []);
		if (is_array($props) === false) {
			return [];
		}

		return $props;
	}//end properties()

	/**
	 * Extract the required-property name set from a definition.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<int, string> The required property names.
	 */
	private function requiredSet(array $definition): array {
		$required = ($definition['required'] ?? []);
		if (is_array($required) === false) {
			return [];
		}

		return array_values(array_filter($required, 'is_string'));
	}//end requiredSet()

	/**
	 * Normalise a JSON-schema type for comparison.
	 *
	 * @param mixed $type The type value (string or array).
	 *
	 * @return string The normalised type signature.
	 */
	private function normaliseType($type): string {
		if (is_array($type) === true) {
			$copy = $type;
			sort($copy);
			return implode('|', array_map('strval', $copy));
		}

		if ($type === null) {
			return '';
		}

		return (string)$type;
	}//end normaliseType()

	/**
	 * Parse a semantic version string into a [major, minor, patch] int triple.
	 *
	 * @param string|null $version The version string.
	 *
	 * @return array{0: int, 1: int, 2: int} The parsed triple.
	 */
	private function parseVersion(?string $version): array {
		if ($version === null || trim($version) === '') {
			return [0, 0, 0];
		}

		$parts = explode('.', trim($version));

		return [
			(int)($parts[0] ?? 0),
			(int)($parts[1] ?? 0),
			(int)($parts[2] ?? 0),
		];

	}//end parseVersion()
}//end class
