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
use Throwable;

/**
 * Resolves the effective trust tier for an (entityType, attribute, sourceSystem)
 * tuple, honouring `effectiveFrom` versioning and freshness decay.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
 */
class TrustTierResolver
{
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
     * @param string                           $entityType   Entity type to match.
     * @param string                           $attribute    Attribute name to match.
     * @param string                           $sourceSystem Source system to match.
     * @param array<int, array<string, mixed>> $trustRows    Candidate trust-configuration rows.
     * @param DateTimeImmutable                $asOf         Reference instant for effectiveFrom resolution.
     *
     * @return string|null The effective tier, or null when no row applies.
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
     */
    public function resolveTier(
        string $entityType,
        string $attribute,
        string $sourceSystem,
        array $trustRows,
        DateTimeImmutable $asOf
    ): ?string {
        $best     = null;
        $bestFrom = null;

        foreach ($trustRows as $row) {
            $candidate = $this->eligibleCandidate(
                row: $row,
                entityType: $entityType,
                attribute: $attribute,
                sourceSystem: $sourceSystem,
                asOf: $asOf
            );
            if ($candidate === null) {
                continue;
            }

            [$tier, $rank] = $candidate;
            if ($best === null || $rank >= $bestFrom) {
                $best     = $tier;
                $bestFrom = $rank;
            }
        }

        return $best;
    }//end resolveTier()

    /**
     * Evaluate a single candidate row for the tuple: matches the tuple, is
     * effective as of `$asOf`, and declares a non-empty tier.
     *
     * @param mixed             $row          Candidate row (validated to be an array here).
     * @param string            $entityType   Entity type to match.
     * @param string            $attribute    Attribute name to match.
     * @param string            $sourceSystem Source system to match.
     * @param DateTimeImmutable $asOf         Reference instant for effectiveFrom resolution.
     *
     * @return array{0: string, 1: int}|null [tier, effectiveFrom-rank], or null when ineligible.
     */
    private function eligibleCandidate(
        mixed $row,
        string $entityType,
        string $attribute,
        string $sourceSystem,
        DateTimeImmutable $asOf
    ): ?array {
        if (is_array($row) === false) {
            return null;
        }

        if ($this->matchesTuple(row: $row, entityType: $entityType, attribute: $attribute, sourceSystem: $sourceSystem) === false) {
            return null;
        }

        $effectiveFrom = $this->parseDate(value: ($row['effectiveFrom'] ?? null));
        if ($effectiveFrom !== null && $effectiveFrom > $asOf) {
            // Future-dated row: not yet effective.
            return null;
        }

        $tier = (string) ($row['trustTier'] ?? '');
        if ($tier === '') {
            return null;
        }

        $rank = ($effectiveFrom?->getTimestamp() ?? PHP_INT_MIN);

        return [$tier, $rank];
    }//end eligibleCandidate()

    /**
     * Apply freshness decay: step the tier down exactly one level on
     * `tierOrder` when the elapsed time since `$anchor` exceeds
     * `$freshnessDecayDays`. Pure and null-safe — a null/non-positive decay
     * window, or a null anchor, leaves the tier unchanged.
     *
     * @param string                 $tier               Starting tier.
     * @param array<int, string>     $tierOrder          Ordered tier names, weakest first.
     * @param int|null               $freshnessDecayDays Decay window in days, or null for no decay.
     * @param DateTimeImmutable|null $anchor             The source's freshness anchor date, or null.
     * @param DateTimeImmutable      $asOf               Reference instant.
     *
     * @return string The (possibly stepped-down) tier.
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
     */
    public function applyFreshnessDecay(
        string $tier,
        array $tierOrder,
        ?int $freshnessDecayDays,
        ?DateTimeImmutable $anchor,
        DateTimeImmutable $asOf
    ): string {
        if ($freshnessDecayDays === null || $freshnessDecayDays <= 0 || $anchor === null) {
            return $tier;
        }

        $elapsedDays = (int) floor(($asOf->getTimestamp() - $anchor->getTimestamp()) / 86400);
        if ($elapsedDays <= $freshnessDecayDays) {
            return $tier;
        }

        return $this->stepDown(tier: $tier, tierOrder: $tierOrder);
    }//end applyFreshnessDecay()

    /**
     * Step a tier down exactly one level on the ordered tier list (weakest
     * first). A tier already at the weakest position, or not present on the
     * list, stays unchanged.
     *
     * @param string             $tier      Starting tier.
     * @param array<int, string> $tierOrder Ordered tier names, weakest first.
     *
     * @return string The stepped-down tier.
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
     */
    public function stepDown(string $tier, array $tierOrder): string
    {
        $index = array_search($tier, $tierOrder, true);
        if ($index === false || $index <= 0) {
            return $tier;
        }

        return (string) $tierOrder[((int) $index - 1)];
    }//end stepDown()

    /**
     * Rank of a tier within the ordered tier list (higher = stronger).
     * Unknown tiers rank lowest (-1) so they never win a comparison.
     *
     * @param string             $tier      Tier name.
     * @param array<int, string> $tierOrder Ordered tier names, weakest first.
     *
     * @return int Rank (0 = weakest declared tier; -1 = unknown).
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
     */
    public function tierRank(string $tier, array $tierOrder): int
    {
        $index = array_search($tier, $tierOrder, true);
        if ($index === false) {
            return -1;
        }

        return (int) $index;
    }//end tierRank()

    /**
     * Check whether a trust-configuration row matches the given tuple.
     *
     * @param array<string, mixed> $row          Candidate row.
     * @param string               $entityType   Entity type to match.
     * @param string               $attribute    Attribute to match.
     * @param string               $sourceSystem Source system to match.
     *
     * @return bool True when all three fields match.
     */
    private function matchesTuple(array $row, string $entityType, string $attribute, string $sourceSystem): bool
    {
        return (string) ($row['entityType'] ?? '') === $entityType
            && (string) ($row['attribute'] ?? '') === $attribute
            && (string) ($row['sourceSystem'] ?? '') === $sourceSystem;
    }//end matchesTuple()

    /**
     * Parse a date-ish value into a DateTimeImmutable, never throwing.
     *
     * @param mixed $value Candidate date value.
     *
     * @return DateTimeImmutable|null Parsed date, or null when absent/unparseable.
     */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }//end parseDate()
}//end class
