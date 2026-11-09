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
	 * The authorization blocks, settled whole.
	 *
	 * @var AuthorizationBlocks
	 */
	private readonly AuthorizationBlocks $blocks;

	/**
	 * Constructor.
	 *
	 * @param DescriptorParts                   $parts      The flattener.
	 * @param DivergenceComparator              $comparator The four states.
	 * @param ShippedAuthorizationDecision|null $decision   An administrator's decision to take shipped
	 *                                                      authorization, while one is in effect; none by default.
	 */
	public function __construct(
		private readonly DescriptorParts $parts,
		private readonly DivergenceComparator $comparator,
		private readonly ?ShippedAuthorizationDecision $decision = null,
	) {
		$this->blocks = new AuthorizationBlocks(parts: $parts);
	}//end __construct()

	/**
	 * Who decided to take shipped authorization, while a decision is in effect.
	 *
	 * @return string|null The source of the decision, or null.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function decidedVia(): ?string {
		return $this->decision?->via();
	}//end decidedVia()

	/**
	 * Note, on the decision in effect, the blocks it replaced over a local change.
	 *
	 * @param string             $slug  The schema slug.
	 * @param array<int, string> $roots The replaced block roots.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function noteReplaced(string $slug, array $roots): void {
		$this->decision?->noteReplaced(slug: $slug, roots: $roots);
	}//end noteReplaced()

	/**
	 * What to write, what was applied, what was preserved and what conflicts.
	 *
	 * 🔴 AN AUTHORIZATION BLOCK SETTLES AS ONE PART. The schema-level
	 * `authorization` and each property's `authorization` are access policies,
	 * and a policy is only meaningful whole. Merged per action key, the portaliq
	 * 0.97.0 upgrade took `read: [portaliq-medewerkers]` from the app and kept
	 * `export: [authenticated]` from the instance: a policy NEITHER side wrote,
	 * under which an account that may not read a record may still export it.
	 * So every key under one block takes the same side: the app's whole block
	 * when only the app moved it, the instance's whole block when only the
	 * instance did, and one conflict on the block's root path when both did or
	 * when they moved different keys. A decision names that root path.
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
	 *     baseline: array<string, mixed>,
	 *     replacedLocal: array<int, string>
	 * } The result. `replacedLocal` names the authorization blocks a decision
	 *   took from upstream over a value the instance had changed.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function merge(array $baseline, array $live, array $incoming, array $decisions = []): array {
		$states = $this->comparator->states(baseline: $baseline, live: $live, incoming: $incoming);

		$sources = [
			'baseline' => $this->parts->flatten(descriptor: $baseline),
			'live' => $this->parts->flatten(descriptor: $live),
			'incoming' => $this->parts->flatten(descriptor: $incoming),
		];

		$states    = $this->withMissingTakenFromUpstream(states: $states, sources: $sources);
		$decisions = $this->decisionsInEffect(decisions: $decisions, incoming: $incoming);

		$blocks = $this->blocks->settle(states: $states, decisions: $decisions);
		$fold   = new DescriptorFold(sources: $sources, decisions: $decisions, blocks: $blocks, authorization: $this->blocks);
		foreach ($states as $path => $state) {
			$fold->fold(path: $path, state: $state);
		}

		$outcome = $this->blocks->outcome(blocks: $blocks, sources: $sources);

		return [
			'merged' => $this->parts->unflatten(parts: $fold->mergedParts),
			'applied' => array_merge($fold->applied, $outcome['applied']),
			'preserved' => $fold->preserved,
			'conflicts' => array_merge($fold->conflicts, $outcome['conflicts']),
			'baseline' => $this->parts->unflatten(parts: $fold->nextBaseline),
			'replacedLocal' => $outcome['replacedLocal'],
		];
	}//end merge()

	/**
	 * The divergence states, with every path of a shipped property the
	 * instance does not hold at all taken from upstream.
	 *
	 * A shipped property the instance does not hold AT ALL never reached
	 * it (an earlier import whose write did not land it); it was not
	 * deleted here. Read as a local deletion it was kept absent, and
	 * reported as a conflict wherever the app also changed it, on every
	 * upgrade (learniq D11). It is taken from upstream.
	 *
	 * @param array<string|int, string>               $states  Path to divergence state.
	 * @param array<string, array<string|int, mixed>> $sources The flattened definitions.
	 *
	 * @return array<string|int, string> The states.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-shipped-property-missing-from-the-instance-is-restored-on-upgrade
	 */
	private function withMissingTakenFromUpstream(array $states, array $sources): array {
		$missing = $this->missingProperties(sources: $sources);
		foreach (array_keys($states) as $path) {
			if (in_array($this->propertyOf(path: (string)$path), $missing, true) === true) {
				$states[$path] = DivergenceComparator::UPSTREAM;
			}
		}

		return $states;
	}//end withMissingTakenFromUpstream()

	/**
	 * The paths decided to be taken from upstream, for this merge.
	 *
	 * An administrator's re-import is the explicit decision REQ-LCA-003
	 * waits for, for every authorization block the shipped definition
	 * carries. A block only the instance holds is not decided: the app
	 * shipped nothing to take, so the local policy stays (and is
	 * reported) instead of the schema turning default-open.
	 *
	 * @param array<int, string>   $decisions Paths the caller decided to take from upstream.
	 * @param array<string, mixed> $incoming  The definition the app now ships.
	 *
	 * @return array<int, string> The decisions in effect.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	private function decisionsInEffect(array $decisions, array $incoming): array {
		if ($this->decidedVia() === null) {
			return $decisions;
		}

		return array_values(array_unique(array_merge($decisions, $this->blocks->roots($incoming))));
	}//end decisionsInEffect()

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
}//end class
