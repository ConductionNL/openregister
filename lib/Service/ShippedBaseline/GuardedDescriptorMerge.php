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

		$sources = [
			'baseline' => $this->parts->flatten(descriptor: $baseline),
			'live' => $this->parts->flatten(descriptor: $live),
			'incoming' => $this->parts->flatten(descriptor: $incoming),
		];

		$acc = [
			'mergedParts' => [],
			'nextBaseline' => [],
			'applied' => [],
			'preserved' => [],
			'conflicts' => [],
		];

		$missing = $this->missingProperties(sources: $sources);

		foreach ($states as $path => $state) {
			$decided = in_array($path, $decisions, true);

			// A shipped property the instance does not hold AT ALL never reached
			// it (an earlier import whose write did not land it); it was not
			// deleted here. Read as a local deletion it was kept absent, and
			// reported as a conflict wherever the app also changed it, on every
			// upgrade (learniq D11). It is taken from upstream.
			if (in_array($this->propertyOf(path: (string)$path), $missing, true) === true) {
				$state = DivergenceComparator::UPSTREAM;
			}

			// A decided conflict is taken from upstream, and is the only way a
			// conflicting part moves. The decision is the caller's; recording
			// it is the caller's too, which is why it arrives as a path list
			// and not as a flag on this class.
			if ($state === DivergenceComparator::BOTH && $decided === true) {
				$state = DivergenceComparator::UPSTREAM;
				$acc['applied'][] = $path;
			}

			$this->foldPath(
				acc: $acc,
				path: $path,
				state: (string)$state,
				decided: $decided,
				sources: $sources
			);
		}//end foreach

		return [
			'merged' => $this->parts->unflatten(parts: $acc['mergedParts']),
			'applied' => $acc['applied'],
			'preserved' => $acc['preserved'],
			'conflicts' => $acc['conflicts'],
			'baseline' => $this->parts->unflatten(parts: $acc['nextBaseline']),
		];
	}//end merge()

	/**
	 * The shipped properties the instance holds no part of.
	 *
	 * Only a property missing as a whole counts: a property the instance has
	 * with one part removed is a local change and stays one.
	 *
	 * @param array<string, array<string, mixed>> $sources The flattened baseline, live and incoming definitions.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-shipped-property-missing-from-the-instance-is-restored-on-upgrade
	 */
	private function missingProperties(array $sources): array {
		$live = [];
		foreach (array_keys($sources['live']) as $path) {
			$live[$this->propertyOf(path: (string)$path)] = true;
		}

		$missing = [];
		foreach (array_keys($sources['incoming']) as $path) {
			$property = $this->propertyOf(path: (string)$path);
			if ($property !== null && isset($live[$property]) === false) {
				$missing[$property] = true;
			}
		}

		return array_keys($missing);
	}//end missingProperties()

	/**
	 * The property a flattened path belongs to, or null for a path outside `properties`.
	 *
	 * @param string $path The flattened path, such as `properties.personalNumber.x-notes`.
	 *
	 * @return string|null The property name.
	 */
	private function propertyOf(string $path): ?string {
		$segments = explode(DescriptorParts::SEPARATOR, $path, 3);
		if ($segments[0] !== 'properties' || isset($segments[1]) === false) {
			return null;
		}

		return $segments[1];
	}//end propertyOf()

	/**
	 * Fold ONE path's divergence state into the running result.
	 *
	 * Split out of `merge()` purely so each state's rule is readable on its
	 * own. The dispatch order and every branch inside it are unchanged, which
	 * matters because these five rules decide what an upgrade overwrites.
	 *
	 * @param array<string, mixed> $acc     The running result, mutated in place.
	 * @param string|integer       $path    The flattened descriptor path.
	 * @param string               $state   The divergence state for this path.
	 * @param boolean              $decided Whether an administrator took this path from upstream.
	 * @param array<string, array<string|int, mixed>> $sources The flattened baseline, live and incoming parts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldPath(array &$acc, string|int $path, string $state, bool $decided, array $sources): void {
		switch ($state) {
			case DivergenceComparator::UPSTREAM:
				$this->foldUpstream(acc: $acc, path: $path, decided: $decided, sources: $sources);
			break;

			case DivergenceComparator::LOCAL:
				$this->foldLocal(acc: $acc, path: $path, sources: $sources);
			break;

			case DivergenceComparator::BOTH:
				$this->foldConflict(acc: $acc, path: $path, sources: $sources);
			break;

			default:
				// CONVERGED and UNCHANGED write the same thing: the live value
				// stands, and the baseline follows what the app now ships.
				// They were two identical branches before this split.
				$this->foldSettled(acc: $acc, path: $path, sources: $sources);
			break;
		}//end switch
	}//end foldPath()

	/**
	 * Fold a path only the app changed.
	 *
	 * @param array<string, mixed> $acc     The running result, mutated in place.
	 * @param string|integer       $path    The flattened descriptor path.
	 * @param boolean              $decided Whether an administrator took this path from upstream.
	 * @param array<string, array<string|int, mixed>> $sources The flattened parts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldUpstream(array &$acc, string|int $path, bool $decided, array $sources): void {
		if (array_key_exists($path, $sources['incoming']) === true) {
			$acc['mergedParts'][$path] = $sources['incoming'][$path];
			$acc['nextBaseline'][$path] = $sources['incoming'][$path];
			if ($decided === false) {
				$acc['applied'][] = $path;
			}

			return;
		}

		// Absent from the incoming and unchanged locally: the app removed it,
		// and nobody locally disagreed. It is dropped from both the merged
		// definition and the new baseline.
		if ($decided === false) {
			$acc['applied'][] = $path;
		}
	}//end foldUpstream()

	/**
	 * Fold a path only the instance changed.
	 *
	 * @param array<string, mixed> $acc     The running result, mutated in place.
	 * @param string|integer       $path    The flattened descriptor path.
	 * @param array<string, array<string|int, mixed>> $sources The flattened parts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldLocal(array &$acc, string|int $path, array $sources): void {
		if (array_key_exists($path, $sources['live']) === true) {
			$acc['mergedParts'][$path] = $sources['live'][$path];
		}

		// Recorded as preserved whether the local change ADDED the part or
		// REMOVED it. A part the instance deleted is a local decision like any
		// other, and leaving it out of the list would report the upgrade as
		// having preserved less than it did.
		$acc['preserved'][] = $path;

		// The baseline keeps what the app shipped, so two upgrades later the
		// report still names the version this part diverged from (D-5).
		if (array_key_exists($path, $sources['baseline']) === true) {
			$acc['nextBaseline'][$path] = $sources['baseline'][$path];
		}
	}//end foldLocal()

	/**
	 * Fold a path both sides changed, differently.
	 *
	 * @param array<string, mixed> $acc     The running result, mutated in place.
	 * @param string|integer       $path    The flattened descriptor path.
	 * @param array<string, array<string|int, mixed>> $sources The flattened parts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldConflict(array &$acc, string|int $path, array $sources): void {
		$hasIncoming = array_key_exists($path, $sources['incoming']);
		$hasLive = array_key_exists($path, $sources['live']);

		if ($hasLive === true) {
			$acc['mergedParts'][$path] = $sources['live'][$path];
		}

		if (array_key_exists($path, $sources['baseline']) === true) {
			$acc['nextBaseline'][$path] = $sources['baseline'][$path];
		}

		$acc['conflicts'][] = [
			'path' => $path,
			'shipped' => ($sources['incoming'][$path] ?? null),
			'live' => ($sources['live'][$path] ?? null),
			'shippedPresent' => $hasIncoming,
			'livePresent' => $hasLive,
		];
	}//end foldConflict()

	/**
	 * Fold a path neither side disputes.
	 *
	 * @param array<string, mixed> $acc     The running result, mutated in place.
	 * @param string|integer       $path    The flattened descriptor path.
	 * @param array<string, array<string|int, mixed>> $sources The flattened parts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function foldSettled(array &$acc, string|int $path, array $sources): void {
		if (array_key_exists($path, $sources['live']) === true) {
			$acc['mergedParts'][$path] = $sources['live'][$path];
		}

		if (array_key_exists($path, $sources['incoming']) === true) {
			$acc['nextBaseline'][$path] = $sources['incoming'][$path];
		}
	}//end foldSettled()
}//end class
