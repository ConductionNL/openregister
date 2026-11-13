<?php

/**
 * OpenRegister TrustTierResolver
 *
 * Pure lookup + freshness-decay engine over the `trustConfiguration` register
 * rows. Given a `(entityType, attribute, sourceSystem)` tuple, resolves the
 * effective trust tier as of a given date — honouring `effectiveFrom`
 * versioning — and applies a discrete one-tier step-down when a candidate's
 * freshness anchor has aged past the row's `freshnessDecayDays`. No I/O, no
 * DB access, never fatal: callers supply the candidate trust rows already
 * loaded (via ObjectService, RBAC + tenant scoped) and this class only does
 * the pure lookup/decay arithmetic over them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
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

namespace OCA\OpenRegister\Service\Survivorship;

use DateTimeImmutable;

/**
 * Resolves the effective trust tier for an (entityType, attribute, sourceSystem)
 * tuple, honouring `effectiveFrom` versioning and freshness decay.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
 */
class TrustTierResolver implements TrustTierResolverInterface {
	/**
	 * Resolve the effective trust tier for a tuple as of a given date.
	 *
	 * Among the supplied candidate rows matching the tuple, the most recent
	 * row whose `effectiveFrom` is on or before `$asOf` wins. Rows with an
	 * `effectiveFrom` strictly after `$asOf` are ignored (future-dated). A
	 * row without `effectiveFrom` is treated as always-effective (earliest
	 * possible). When no row matches, returns null so the caller falls back
	 * to the annotation's `defaultTier`.
	 *
	 * @param TrustTuple $tuple The (entityType, attribute, sourceSystem) tuple to match.
	 * @param array<int, array<string, mixed>> $trustRows Candidate trust-configuration rows.
	 * @param DateTimeImmutable $asOf Reference instant for effectiveFrom resolution.
	 *
	 * @return string|null The effective tier, or null when no row applies.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
	 */
	public function resolveTier(TrustTuple $tuple, array $trustRows, DateTimeImmutable $asOf): ?string {
		return $this->bestTier(run: new TierResolutionRun(tuple: $tuple, asOf: $asOf), trustRows: $trustRows);
	}//end resolveTier()

	/**
	 * The tier of the most recent eligible row of one resolution, or null when none applies.
	 *
	 * @param TierResolutionRun                $run       The tuple to match and the instant it must be effective at.
	 * @param array<int, array<string, mixed>> $trustRows Candidate trust-configuration rows.
	 *
	 * @return string|null The effective tier, or null when no row applies.
	 */
	private function bestTier(TierResolutionRun $run, array $trustRows): ?string {
		$best = null;
		$bestFrom = null;

		foreach ($trustRows as $row) {
			$candidate = $this->eligibleCandidate(row: $row, run: $run);
			if ($candidate === null) {
				continue;
			}

			[$tier, $rank] = $candidate;
			if ($this->supersedes(rank: $rank, bestFrom: $bestFrom) === true) {
				$best = $tier;
				$bestFrom = $rank;
			}
		}

		return $best;
	}//end bestTier()

	/**
	 * Whether an eligible row supersedes the best one so far: there is none
	 * yet, or its effectiveFrom rank is the same or later (a later row in the
	 * list wins a tie).
	 *
	 * @param int      $rank     The row's effectiveFrom rank.
	 * @param int|null $bestFrom The best row's rank, null when none yet.
	 *
	 * @return bool True when the row becomes the best.
	 */
	private function supersedes(int $rank, ?int $bestFrom): bool {
		return $bestFrom === null || $rank >= $bestFrom;
	}//end supersedes()

	/**
	 * Evaluate a single candidate row for the tuple: matches the tuple, is
	 * effective as of `$asOf`, and declares a non-empty tier.
	 *
	 * @param mixed             $row Candidate row (validated to be an array here).
	 * @param TierResolutionRun $run The tuple to match and the reference instant for effectiveFrom resolution.
	 *
	 * @return array{0: string, 1: int}|null [tier, effectiveFrom-rank], or null when ineligible.
	 */
	private function eligibleCandidate(mixed $row, TierResolutionRun $run): ?array {
		if ($this->isMatchingRow(row: $row, tuple: $run->tuple) === false) {
			return null;
		}

		$rank = $this->effectiveRank(row: $row, asOf: $run->asOf);
		if ($rank === null) {
			return null;
		}

		$tier = (string)($row['trustTier'] ?? '');
		if ($tier === '') {
			return null;
		}

		return [$tier, $rank];
	}//end eligibleCandidate()

	/**
	 * Whether a candidate row is an array matching the tuple.
	 *
	 * @param mixed      $row   Candidate row (untrusted).
	 * @param TrustTuple $tuple The tuple to match.
	 *
	 * @return bool True when the row is an array matching all three fields.
	 */
	private function isMatchingRow(mixed $row, TrustTuple $tuple): bool {
		return is_array($row) === true && $this->matchesTuple(row: $row, tuple: $tuple) === true;
	}//end isMatchingRow()

	/**
	 * The effectiveFrom rank of a row: its timestamp, PHP_INT_MIN for a row
	 * without a readable effectiveFrom (always effective), or null for a
	 * future-dated row that is not yet effective as of `$asOf`.
	 *
	 * @param array<string, mixed> $row  Candidate row.
	 * @param DateTimeImmutable    $asOf Reference instant for effectiveFrom resolution.
	 *
	 * @return int|null The rank, or null when not yet effective.
	 */
	private function effectiveRank(array $row, DateTimeImmutable $asOf): ?int {
		$effectiveFrom = SurvivorshipDate::parse(value: ($row['effectiveFrom'] ?? null));
		if ($effectiveFrom !== null && $effectiveFrom > $asOf) {
			// Future-dated row: not yet effective.
			return null;
		}

		return ($effectiveFrom?->getTimestamp() ?? PHP_INT_MIN);
	}//end effectiveRank()

	/**
	 * Apply freshness decay: step the tier down exactly one level on
	 * `tierOrder` when the elapsed time since `$anchor` exceeds
	 * `$freshnessDecayDays`. Pure and null-safe — a null/non-positive decay
	 * window, or a null anchor, leaves the tier unchanged.
	 *
	 * @param TierPosition    $position  Starting tier and the ordered tier names, weakest first.
	 * @param SourceFreshness $freshness Decay window, anchor date and reference instant.
	 *
	 * @return string The (possibly stepped-down) tier.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
	 */
	public function applyFreshnessDecay(TierPosition $position, SourceFreshness $freshness): string {
		if ($this->hasDecayed(freshness: $freshness) === false) {
			return $position->tier;
		}

		return $this->stepDown(tier: $position->tier, tierOrder: $position->tierOrder);
	}//end applyFreshnessDecay()

	/**
	 * Whether the tier decays: a positive decay window and an anchor are
	 * present and more whole days than the window have elapsed since the
	 * anchor. A non-positive window never decays.
	 *
	 * @param SourceFreshness $freshness Decay window, anchor date and reference instant.
	 *
	 * @return bool True when the tier steps down.
	 */
	private function hasDecayed(SourceFreshness $freshness): bool {
		if ($freshness->decayDays === null || $freshness->decayDays <= 0 || $freshness->anchor === null) {
			return false;
		}

		return $this->elapsedDays(anchor: $freshness->anchor, asOf: $freshness->asOf) > $freshness->decayDays;
	}//end hasDecayed()

	/**
	 * The whole days elapsed between the anchor and the reference instant.
	 *
	 * @param DateTimeImmutable $anchor The source's freshness anchor date.
	 * @param DateTimeImmutable $asOf   Reference instant.
	 *
	 * @return int The elapsed whole days.
	 */
	private function elapsedDays(DateTimeImmutable $anchor, DateTimeImmutable $asOf): int {
		return (int)floor(($asOf->getTimestamp() - $anchor->getTimestamp()) / 86400);
	}//end elapsedDays()

	/**
	 * Step a tier down exactly one level on the ordered tier list (weakest
	 * first). A tier already at the weakest position, or not present on the
	 * list, stays unchanged.
	 *
	 * @param string $tier Starting tier.
	 * @param array<int, string> $tierOrder Ordered tier names, weakest first.
	 *
	 * @return string The stepped-down tier.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
	 */
	public function stepDown(string $tier, array $tierOrder): string {
		$index = array_search($tier, $tierOrder, true);
		if ($index === false || $index <= 0) {
			return $tier;
		}

		return (string)$tierOrder[((int)$index - 1)];
	}//end stepDown()

	/**
	 * Rank of a tier within the ordered tier list (higher = stronger).
	 * Unknown tiers rank lowest (-1) so they never win a comparison.
	 *
	 * @param string $tier Tier name.
	 * @param array<int, string> $tierOrder Ordered tier names, weakest first.
	 *
	 * @return int Rank (0 = weakest declared tier; -1 = unknown).
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
	 */
	public function tierRank(string $tier, array $tierOrder): int {
		$index = array_search($tier, $tierOrder, true);
		if ($index === false) {
			return -1;
		}

		return (int)$index;
	}//end tierRank()

	/**
	 * Check whether a trust-configuration row matches the given tuple.
	 *
	 * @param array<string, mixed> $row   Candidate row.
	 * @param TrustTuple           $tuple The tuple to match.
	 *
	 * @return bool True when all three fields match.
	 */
	private function matchesTuple(array $row, TrustTuple $tuple): bool {
		return (string)($row['entityType'] ?? '') === $tuple->entityType
			&& (string)($row['attribute'] ?? '') === $tuple->attribute
			&& (string)($row['sourceSystem'] ?? '') === $tuple->sourceSystem;
	}//end matchesTuple()
}//end class
