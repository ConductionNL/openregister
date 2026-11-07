<?php

/**
 * OpenRegister Schema Import — ThreeWayMerge.
 *
 * Pure three-way merge of schema property definitions for update-from-source.
 * Given the imported baseline (as the importer originally produced it), the
 * schema's current properties (possibly locally edited), and a freshly-imported
 * set from the new source, it classifies each property and produces a merged
 * result plus a conflict list — without ever silently overwriting a local
 * customisation. No Nextcloud dependency; fully unit-testable.
 *
 * Classification (per design.md merge table):
 *   - unchanged locally, changed in source   → apply source
 *   - added locally (not in baseline)         → keep local
 *   - modified locally, unchanged in source   → keep local
 *   - modified locally AND changed in source  → conflict (needs confirmation)
 *   - removed in source                       → report as removal
 *   - added in source                         → add
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * Pure three-way merge for update-from-source.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
class ThreeWayMerge {
	/**
	 * Compute the classified diff + merged result for an update-from-source.
	 *
	 * @param array<string, array<string,mixed>> $baseline Imported baseline property definitions.
	 * @param array<string, array<string,mixed>> $current  Current schema property definitions.
	 * @param array<string, array<string,mixed>> $incoming Freshly-imported property definitions.
	 * @param array<int, string>                 $resolved Conflicting property names the caller confirmed to apply.
	 *
	 * @return array<string, mixed> {
	 *                              added: string[], removed: string[], changed: string[], keptLocal: string[],
	 *                              conflicts: string[], merged: array<string, array<string,mixed>>, applied: bool
	 *                              }
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function compute(array $baseline, array $current, array $incoming, array $resolved = []): array {
		$sides = ['baseline' => $baseline, 'current' => $current, 'incoming' => $incoming, 'resolved' => array_fill_keys($resolved, true)];
		$result = ['added' => [], 'removed' => [], 'changed' => [], 'keptLocal' => [], 'conflicts' => [], 'merged' => $current];

		foreach (array_unique(array_merge(array_keys($baseline), array_keys($current), array_keys($incoming))) as $name) {
			$result = $this->place(result: $result, sides: $sides, name: $name);
		}

		$result['applied'] = ($result['conflicts'] === []);
		return $result;
	}//end compute()

	/**
	 * Record one property name in its bucket; added and changed names take
	 * the incoming definition into the merged map.
	 *
	 * @param array<string, array<int|string, mixed>> $result The result built so far.
	 * @param array<string, array<int|string, mixed>> $sides  The baseline, current and incoming maps plus the resolved set.
	 * @param int|string                              $name   The property name.
	 *
	 * @return array<string, array<int|string, mixed>> The result with the name placed.
	 */
	private function place(array $result, array $sides, int|string $name): array {
		$bucket = $this->classify(sides: $sides, name: $name);
		if ($bucket === null) {
			return $result;
		}

		$result[$bucket][] = $name;
		if ($bucket === 'added' || $bucket === 'changed') {
			$result['merged'][$name] = $sides['incoming'][$name];
		}

		return $result;
	}//end place()

	/**
	 * Classify one property name into its merge-table bucket.
	 *
	 * Carried by the source → see classifyIncoming(). Otherwise: dropped by
	 * source but still carried → removed (breaking, so reported, not
	 * auto-applied); added locally and never in the baseline → keptLocal; a
	 * name only the baseline knows lands nowhere.
	 *
	 * @param array<string, array<int|string, mixed>> $sides The baseline, current and incoming maps plus the resolved set.
	 * @param int|string                              $name  The property name.
	 *
	 * @return string|null The bucket, or null when the name lands nowhere.
	 */
	private function classify(array $sides, int|string $name): ?string {
		return match (true) {
			array_key_exists($name, $sides['incoming']) => $this->classifyIncoming(sides: $sides, name: $name),
			array_key_exists($name, $sides['current']) === false => null,
			array_key_exists($name, $sides['baseline']) => 'removed',
			default => 'keptLocal',
		};
	}//end classify()

	/**
	 * Classify a name the source carries.
	 *
	 * Absent locally → added. Without a baseline both sides count as
	 * changed, so it is a conflict; otherwise compare against the baseline.
	 *
	 * @param array<string, array<int|string, mixed>> $sides The baseline, current and incoming maps plus the resolved set.
	 * @param int|string                              $name  The property name.
	 *
	 * @return string The bucket.
	 */
	private function classifyIncoming(array $sides, int|string $name): string {
		if (array_key_exists($name, $sides['current']) === false) {
			return 'added';
		}

		if (array_key_exists($name, $sides['baseline']) === false) {
			return $this->resolveConflict(sides: $sides, name: $name);
		}

		return $this->compareWithBaseline(sides: $sides, name: $name);
	}//end classifyIncoming()

	/**
	 * Classify a name present in all three maps.
	 *
	 * Source unchanged → keep local (covers local mods + identical); local
	 * unchanged → apply source; both changed → conflict.
	 *
	 * @param array<string, array<int|string, mixed>> $sides The baseline, current and incoming maps plus the resolved set.
	 * @param int|string                              $name  The property name.
	 *
	 * @return string The bucket.
	 */
	private function compareWithBaseline(array $sides, int|string $name): string {
		$base = $sides['baseline'][$name];
		if ($this->differs(a: $base, b: $sides['incoming'][$name]) === false) {
			return 'keptLocal';
		}

		if ($this->differs(a: $base, b: $sides['current'][$name]) === false) {
			return 'changed';
		}

		return $this->resolveConflict(sides: $sides, name: $name);
	}//end compareWithBaseline()

	/**
	 * Both sides changed: apply only when the caller explicitly resolved it.
	 *
	 * @param array<string, array<int|string, mixed>> $sides The baseline, current and incoming maps plus the resolved set.
	 * @param int|string                              $name  The property name.
	 *
	 * @return string The bucket.
	 */
	private function resolveConflict(array $sides, int|string $name): string {
		if (isset($sides['resolved'][$name]) === true) {
			return 'changed';
		}

		return 'conflicts';
	}//end resolveConflict()

	/**
	 * Whether two property definitions differ (order-insensitive deep compare).
	 *
	 * @param mixed $a The first definition.
	 * @param mixed $b The second definition.
	 *
	 * @return bool True when they differ.
	 */
	private function differs(mixed $a, mixed $b): bool {
		return $this->canonical(value: $a) !== $this->canonical(value: $b);
	}//end differs()

	/**
	 * Canonicalise a value for a stable, order-insensitive comparison.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The canonicalised value.
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === true) {
			$copy = [];
			foreach ($value as $key => $item) {
				$copy[$key] = $this->canonical(value: $item);
			}

			ksort($copy);
			return $copy;
		}

		return $value;
	}//end canonical()
}//end class
