<?php

/**
 * The upgrade that refuses to choose silently (REQ-LCA-003).
 *
 * ADR-005 rule 2 says a repair step must be safe to run on every upgrade:
 * match by slug, create-or-update, never duplicate. Read together with rule 3,
 * "descriptor is the source of truth", that means a local edit is overwritten
 * on the next `occ upgrade` and nothing records that it existed. The mirror
 * failure is the one municipalities actually live with: nobody dares update,
 * and the instance sits two releases behind.
 *
 * 🔴 AN UNATTENDED UPGRADE IS THE NORMAL CASE (D-3). `occ upgrade` runs with
 * nobody watching, so the default on a conflict is to KEEP THE LOCAL VALUE and
 * report it. Never apply, because that is the silent discard this change
 * exists to end. Never abort, because an upgrade that fails halfway through an
 * app's registers is worse than one that leaves four properties behind and
 * says so in a report somebody reads on Monday. This class therefore has no
 * throw in it.
 *
 * 🔴 AND A LOCAL ADDITION IS NOT AN UPSTREAM REMOVAL. The municipality's extra
 * property is absent from both the baseline and the incoming descriptor, which
 * looks exactly like a part the app has deleted unless you ask the baseline
 * which of the two it was. Getting that backwards deletes the field on every
 * upgrade, quietly, which is the row's opening sentence.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * Merges an incoming shipped descriptor over a diverged live one, per part.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
class GuardedDescriptorMerge {

	/**
	 * Constructor.
	 *
	 * @param DescriptorParts      $parts      The flattener.
	 * @param DivergenceComparator $comparator The four states.
	 */
	public function __construct(
		private readonly DescriptorParts $parts,
		private readonly DivergenceComparator $comparator,
	) {
	}//end __construct()

	/**
	 * What to write, what was applied, what was preserved and what conflicts.
	 *
	 * @param array<string, mixed> $baseline  The definition the app shipped.
	 * @param array<string, mixed> $live      The definition the instance runs.
	 * @param array<string, mixed> $incoming  The definition the app now ships.
	 * @param array<int, string>   $decisions Paths an administrator has decided to take from upstream.
	 *
	 * @return array{
	 *     merged: array<string, mixed>,
	 *     applied: array<int, string>,
	 *     preserved: array<int, string>,
	 *     conflicts: array<int, array{path: string, shipped: mixed, live: mixed}>,
	 *     baseline: array<string, mixed>
	 * } The result.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function merge(array $baseline, array $live, array $incoming, array $decisions = []): array {
		$states = $this->comparator->states(baseline: $baseline, live: $live, incoming: $incoming);

		$b = $this->parts->flatten(descriptor: $baseline);
		$l = $this->parts->flatten(descriptor: $live);
		$i = $this->parts->flatten(descriptor: $incoming);

		$mergedParts = [];
		$nextBaseline = [];
		$applied = [];
		$preserved = [];
		$conflicts = [];

		foreach ($states as $path => $state) {
			$hasIncoming = array_key_exists($path, $i);
			$hasLive = array_key_exists($path, $l);
			$hasBaseline = array_key_exists($path, $b);

			$decided = in_array($path, $decisions, true);

			// A decided conflict is taken from upstream, and is the only way a
			// conflicting part moves. The decision is the caller's; recording
			// it is the caller's too, which is why it arrives as a path list
			// and not as a flag on this class.
			if ($state === DivergenceComparator::BOTH && $decided === true) {
				$state = DivergenceComparator::UPSTREAM;
				$applied[] = $path;
			}

			switch ($state) {
				case DivergenceComparator::UPSTREAM:
					if ($hasIncoming === true) {
						$mergedParts[$path] = $i[$path];
						$nextBaseline[$path] = $i[$path];
						if ($decided === false) {
							$applied[] = $path;
						}
					}

					// Absent from the incoming and unchanged locally: the app
					// removed it, and nobody locally disagreed. It is dropped
					// from both the merged definition and the new baseline.
					if ($hasIncoming === false && $decided === false) {
						$applied[] = $path;
					}
				break;

				case DivergenceComparator::LOCAL:
					if ($hasLive === true) {
						$mergedParts[$path] = $l[$path];
					}

					// Recorded as preserved whether the local change ADDED the
					// part or REMOVED it. A part the instance deleted is a
					// local decision like any other, and leaving it out of the
					// list would report the upgrade as having preserved less
					// than it did.
					$preserved[] = $path;

					// The baseline keeps what the app shipped, so two upgrades
					// later the report still names the version this part
					// diverged from (D-5).
					if ($hasBaseline === true) {
						$nextBaseline[$path] = $b[$path];
					}
				break;

				case DivergenceComparator::BOTH:
					if ($hasLive === true) {
						$mergedParts[$path] = $l[$path];
					}

					if ($hasBaseline === true) {
						$nextBaseline[$path] = $b[$path];
					}

					$shippedValue = null;
					if ($hasIncoming === true) {
						$shippedValue = $i[$path];
					}

					$liveValue = null;
					if ($hasLive === true) {
						$liveValue = $l[$path];
					}

					$conflicts[] = [
						'path' => $path,
						'shipped' => $shippedValue,
						'live' => $liveValue,
						'shippedPresent' => $hasIncoming,
						'livePresent' => $hasLive,
					];
				break;

				case DivergenceComparator::CONVERGED:
					// Both sides moved to the same value: nothing to write and
					// nothing to decide, but the baseline follows, because the
					// app now ships what the instance already runs.
					if ($hasLive === true) {
						$mergedParts[$path] = $l[$path];
					}

					if ($hasIncoming === true) {
						$nextBaseline[$path] = $i[$path];
					}
				break;

				default:
					// Unchanged: live, baseline and incoming all agree.
					if ($hasLive === true) {
						$mergedParts[$path] = $l[$path];
					}

					if ($hasIncoming === true) {
						$nextBaseline[$path] = $i[$path];
					}
				break;
			}//end switch
		}//end foreach

		return [
			'merged' => $this->parts->unflatten(parts: $mergedParts),
			'applied' => $applied,
			'preserved' => $preserved,
			'conflicts' => $conflicts,
			'baseline' => $this->parts->unflatten(parts: $nextBaseline),
		];
	}//end merge()
}//end class
