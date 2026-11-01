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

			$propChanges = $this->diffProperty(
				pair: new PropertyDefinitionPair(name: $name, old: (array)$run->oldProps[$name], new: (array)$newDef)
			);
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
	 * Changes come in a fixed order: type, numeric bounds, enum, pattern, format.
	 *
	 * @param PropertyDefinitionPair $pair The property's old and new definition.
	 *
	 * @return array<int, array<string, mixed>> Typed changes for this property.
	 */
	private function diffProperty(PropertyDefinitionPair $pair): array {
		$changes = [$this->diffType(pair: $pair)];
		foreach (array_keys(self::NUMERIC_BOUND_TIGHTENS_UP) as $keyword) {
			$changes[] = $this->diffNumericBound(pair: $pair, keyword: $keyword);
		}

		$changes[] = $this->diffEnum(pair: $pair);
		foreach (['pattern', 'format'] as $keyword) {
			$changes[] = $this->diffPatternOrFormat(pair: $pair, keyword: $keyword);
		}

		return array_values(array_filter($changes, static fn (?array $change): bool => $change !== null));
	}//end diffProperty()

	/**
	 * Diff the type of a property; a list type compares regardless of order.
	 *
	 * @param PropertyDefinitionPair $pair The property's old and new definition.
	 *
	 * @return array<string, mixed>|null The type_changed change, or null when the type is the same.
	 */
	private function diffType(PropertyDefinitionPair $pair): ?array {
		$oldType = $pair->old['type'] ?? null;
		$newType = $pair->new['type'] ?? null;
		if ($this->normaliseType(type: $oldType) === $this->normaliseType(type: $newType)) {
			return null;
		}

		return [
			'property' => $pair->name,
			'kind' => 'type_changed',
			'old' => $oldType,
			'new' => $newType,
		];
	}//end diffType()

	/**
	 * Diff a numeric bound keyword such as minLength or maximum.
	 *
	 * @param PropertyDefinitionPair $pair    The property's old and new definition.
	 * @param string                 $keyword A key of NUMERIC_BOUND_TIGHTENS_UP.
	 *
	 * @return array<string, mixed>|null The change, or null when the bound is unchanged.
	 */
	private function diffNumericBound(PropertyDefinitionPair $pair, string $keyword): ?array {
		if ($pair->hasBoth(keyword: $keyword) === false) {
			return $this->diffPresence(pair: $pair, keyword: $keyword);
		}

		$old = [$keyword => $pair->old[$keyword]];
		$new = [$keyword => $pair->new[$keyword]];
		if ($old === $new) {
			return null;
		}

		if ($this->boundTightened(pair: $pair, keyword: $keyword) === true) {
			return $pair->tightened(old: $old, new: $new);
		}

		return $pair->relaxed(old: $old, new: $new);
	}//end diffNumericBound()

	/**
	 * Diff a pattern or format keyword; any changed value is conservatively breaking.
	 *
	 * @param PropertyDefinitionPair $pair    The property's old and new definition.
	 * @param string                 $keyword Either 'pattern' or 'format'.
	 *
	 * @return array<string, mixed>|null The change, or null when the keyword is unchanged.
	 */
	private function diffPatternOrFormat(PropertyDefinitionPair $pair, string $keyword): ?array {
		if ($pair->hasBoth(keyword: $keyword) === false) {
			return $this->diffPresence(pair: $pair, keyword: $keyword);
		}

		if ($pair->old[$keyword] === $pair->new[$keyword]) {
			return null;
		}

		return $pair->tightened(old: [$keyword => $pair->old[$keyword]], new: [$keyword => $pair->new[$keyword]]);
	}//end diffPatternOrFormat()

	/**
	 * Diff a keyword that at most one side declares: introducing it tightens, dropping it relaxes.
	 *
	 * @param PropertyDefinitionPair $pair    The property's old and new definition.
	 * @param string                 $keyword The constraint keyword, not declared on both sides.
	 *
	 * @return array<string, mixed>|null The change, or null when neither side declares it.
	 */
	private function diffPresence(PropertyDefinitionPair $pair, string $keyword): ?array {
		if ($pair->hasNew(keyword: $keyword) === true) {
			return $pair->tightened(old: null, new: [$keyword => $pair->new[$keyword]]);
		}

		if ($pair->hasOld(keyword: $keyword) === true) {
			return $pair->relaxed(old: [$keyword => $pair->old[$keyword]], new: null);
		}

		return null;
	}//end diffPresence()

	/**
	 * Diff an enum constraint.
	 *
	 * @param PropertyDefinitionPair $pair The property's old and new definition.
	 *
	 * @return array<string, mixed>|null The change, or null if no enum change.
	 */
	private function diffEnum(PropertyDefinitionPair $pair): ?array {
		$oldEnum = $pair->old['enum'] ?? null;
		$newEnum = $pair->new['enum'] ?? null;

		if ($oldEnum === null && $newEnum === null) {
			return null;
		}

		if (is_array($oldEnum) === false && is_array($newEnum) === true) {
			// Newly constrained to a fixed set = tightening.
			return $pair->tightened(old: null, new: ['enum' => $newEnum]);
		}

		if (is_array($oldEnum) === true && is_array($newEnum) === false) {
			// Enum dropped = relaxation.
			return $pair->relaxed(old: ['enum' => $oldEnum], new: null);
		}

		// Both arrays — compare membership.
		$removed = array_diff($oldEnum, $newEnum);
		$added = array_diff($newEnum, $oldEnum);

		if (count($removed) === 0 && count($added) === 0) {
			return null;
		}

		if (count($removed) > 0) {
			// Removing allowed values can invalidate existing data.
			return $pair->tightened(old: ['enum' => $oldEnum], new: ['enum' => $newEnum]);
		}

		// Only widened.
		return $pair->relaxed(old: ['enum' => $oldEnum], new: ['enum' => $newEnum]);

	}//end diffEnum()

	/**
	 * Whether a numeric bound declared on both sides moved in the tightening direction.
	 *
	 * @param PropertyDefinitionPair $pair    The property's old and new definition.
	 * @param string                 $keyword A key of NUMERIC_BOUND_TIGHTENS_UP.
	 *
	 * @return bool True when the bound tightened.
	 */
	private function boundTightened(PropertyDefinitionPair $pair, string $keyword): bool {
		$oldVal = (float)$pair->old[$keyword];
		$newVal = (float)$pair->new[$keyword];
		if (self::NUMERIC_BOUND_TIGHTENS_UP[$keyword] === true) {
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
