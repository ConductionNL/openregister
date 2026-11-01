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
		$run = new SchemaDiffRun(old: $old, new: $new);
		$run->declareRenames(renames: $renames);
		$this->collectRemoved(run: $run);
		$this->collectAdded(run: $run);
		$this->collectChanged(run: $run);
		$this->collectNewlyRequired(run: $run);
		$this->collectNoLongerRequired(run: $run);

		return $run->changeSet();
	}//end diff()

	/**
	 * Record properties of the old definition that the new one lacks, except declared renames.
	 *
	 * @param SchemaDiffRun $run The diff state.
	 *
	 * @return void
	 */
	private function collectRemoved(SchemaDiffRun $run): void {
		foreach ($run->oldProps as $name => $def) {
			if (array_key_exists($name, $run->newProps) === true || $run->isRenamedFrom(name: $name) === true) {
				continue;
			}

			$run->breaking(change: ['property' => $name, 'kind' => 'removed', 'old' => $def]);
		}
	}//end collectRemoved()

	/**
	 * Record properties new in the new definition, except declared rename targets.
	 *
	 * A new required property with no default invalidates every existing
	 * object that lacks it, so it is recorded a second time as breaking.
	 *
	 * @param SchemaDiffRun $run The diff state.
	 *
	 * @return void
	 */
	private function collectAdded(SchemaDiffRun $run): void {
		foreach ($run->newProps as $name => $def) {
			if (array_key_exists($name, $run->oldProps) === true || $run->isRenamedTo(name: $name) === true) {
				continue;
			}

			$added = ['property' => $name, 'kind' => 'added', 'new' => $def];
			if ($run->requiredWithoutDefault(name: $name) === true) {
				$run->compatible(change: $added);
				$run->breaking(change: ['property' => $name, 'kind' => 'added_required_no_default', 'new' => $def]);
				continue;
			}

			$run->addition(change: $added);
		}
	}//end collectAdded()

	/**
	 * Record the definition changes of properties present in both definitions.
	 *
	 * @param SchemaDiffRun $run The diff state.
	 *
	 * @return void
	 */
	private function collectChanged(SchemaDiffRun $run): void {
		foreach ($run->newProps as $name => $newDef) {
			if (array_key_exists($name, $run->oldProps) === false) {
				continue;
			}

			$propChanges = $this->diffProperty(name: $name, oldDef: (array)$run->oldProps[$name], newDef: (array)$newDef);
			foreach ($propChanges as $change) {
				if ($this->kindIsBreaking(kind: $change['kind']) === true) {
					$run->breaking(change: $change);
					continue;
				}

				$run->compatible(change: $change);
			}
		}
	}//end collectChanged()

	/**
	 * Record existing properties that became required.
	 *
	 * Newly required with a default is compatible; without one it is breaking.
	 * A property that is new as well is handled by collectAdded().
	 *
	 * @param SchemaDiffRun $run The diff state.
	 *
	 * @return void
	 */
	private function collectNewlyRequired(SchemaDiffRun $run): void {
		foreach ($run->newReq as $name) {
			if (in_array($name, $run->oldReq, true) === true || array_key_exists($name, $run->oldProps) === false) {
				continue;
			}

			if (array_key_exists('default', (array)($run->newProps[$name] ?? [])) === true) {
				$run->compatible(change: ['property' => $name, 'kind' => 'required_added_with_default']);
				continue;
			}

			$run->breaking(change: ['property' => $name, 'kind' => 'required_added']);
		}
	}//end collectNewlyRequired()

	/**
	 * Record properties that are no longer required; relaxing is compatible.
	 *
	 * A property removed entirely is already recorded as removed.
	 *
	 * @param SchemaDiffRun $run The diff state.
	 *
	 * @return void
	 */
	private function collectNoLongerRequired(SchemaDiffRun $run): void {
		foreach ($run->oldReq as $name) {
			if (in_array($name, $run->newReq, true) === true || array_key_exists($name, $run->newProps) === false) {
				continue;
			}

			$run->compatible(change: ['property' => $name, 'kind' => 'required_removed']);
		}
	}//end collectNoLongerRequired()

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

			// Only the $hadOld && !$hasNew combination is left: the equal case and
			// the !$hadOld && $hasNew case both continue above, so $hadOld is
			// already known true here.
			if ($hasNew === false) {
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
