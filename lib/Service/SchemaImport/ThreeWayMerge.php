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
	 * @param array<string, array<string,mixed>> $current Current schema property definitions.
	 * @param array<string, array<string,mixed>> $incoming Freshly-imported property definitions.
	 * @param array<int, string> $resolved Conflicting property names the caller confirmed to apply.
	 *
	 * @return array<string, mixed> {
	 *                              added: string[], removed: string[], changed: string[], keptLocal: string[],
	 *                              conflicts: string[], merged: array<string, array<string,mixed>>, applied: bool
	 *                              }
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The merge table is inherently branchy; each branch is one table row.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      The merge table is inherently branchy; each branch is one table row.
	 */
	public function compute(array $baseline, array $current, array $incoming, array $resolved = []): array {
		$added = [];
		$removed = [];
		$changed = [];
		$keptLocal = [];
		$conflicts = [];
		$merged = $current;

		$resolvedSet = array_fill_keys($resolved, true);

		$allNames = array_unique(
			array_merge(array_keys($baseline), array_keys($current), array_keys($incoming))
		);

		foreach ($allNames as $name) {
			$inBaseline = array_key_exists($name, $baseline);
			$inCurrent = array_key_exists($name, $current);
			$inIncoming = array_key_exists($name, $incoming);

			// Added locally: present in current but never in baseline, and not
			// (re)introduced by source — keep untouched.
			if ($inCurrent === true && $inBaseline === false && $inIncoming === false) {
				$keptLocal[] = $name;
				continue;
			}

			// Source adds a new property absent locally → add it.
			if ($inIncoming === true && $inCurrent === false) {
				$merged[$name] = $incoming[$name];
				$added[] = $name;
				continue;
			}

			// Source removed a property that we still carry.
			if ($inIncoming === false && $inBaseline === true && $inCurrent === true) {
				$removed[] = $name;
				// Removal is breaking; reported, not auto-applied here.
				continue;
			}

			// Present in both current and incoming → compare against baseline.
			if ($inCurrent === true && $inIncoming === true) {
				$localModified = ($inBaseline === false || $this->differs(a: $baseline[$name] ?? null, b: $current[$name]));
				$sourceChanged = ($inBaseline === false || $this->differs(a: $baseline[$name] ?? null, b: $incoming[$name]));

				if ($sourceChanged === false) {
					// Source unchanged → keep local (covers local mods + identical).
					$keptLocal[] = $name;
					continue;
				}

				if ($localModified === false) {
					// Local matches baseline, source changed → apply source.
					$merged[$name] = $incoming[$name];
					$changed[] = $name;
					continue;
				}

				// Both changed → conflict; apply only if explicitly resolved.
				if (isset($resolvedSet[$name]) === true) {
					$merged[$name] = $incoming[$name];
					$changed[] = $name;
				} else {
					$conflicts[] = $name;
				}
			}//end if
		}//end foreach

		return [
			'added' => $added,
			'removed' => $removed,
			'changed' => $changed,
			'keptLocal' => $keptLocal,
			'conflicts' => $conflicts,
			'merged' => $merged,
			'applied' => ($conflicts === []),
		];
	}//end compute()

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
