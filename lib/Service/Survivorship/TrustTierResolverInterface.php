<?php

/**
 * TrustTierResolverInterface: what callers outside lib/Service use of TrustTierResolver.
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
 * The part of TrustTierResolver that code outside lib/Service uses.
 */
interface TrustTierResolverInterface {

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
	public function resolveTier(TrustTuple $tuple, array $trustRows, DateTimeImmutable $asOf): ?string;

	/**
	 * Apply freshness decay: step the tier down exactly one level on
	 * `tierOrder` when the elapsed time since `$anchor` exceeds
	 * `$freshnessDecayDays`. Pure and null-safe — a null/non-positive decay
	 * window, or a null anchor, leaves the tier unchanged.
	 *
	 * @param string $tier Starting tier.
	 * @param array<int, string> $tierOrder Ordered tier names, weakest first.
	 * @param SourceFreshness $freshness Decay window, anchor date and reference instant.
	 *
	 * @return string The (possibly stepped-down) tier.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
	 */
	public function applyFreshnessDecay(string $tier, array $tierOrder, SourceFreshness $freshness): string;

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
	public function stepDown(string $tier, array $tierOrder): string;

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
	public function tierRank(string $tier, array $tierOrder): int;
}//end interface
