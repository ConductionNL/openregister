<?php

/**
 * OpenRegister SurvivorshipResolver
 *
 * Pure, entity-type-agnostic golden-record resolver. Given the linked source
 * records for a master object, the schema's `x-openregister-survivorship`
 * config, and a `TrustTierResolver`, computes a `goldenRecord` (winning value
 * per attribute) and an `attributeProvenance` map (which source won, at what
 * tier, and when). No I/O, no DB access, never fatal: a malformed source
 * record is skipped rather than throwing. Generalises pipelinq's
 * `MasterEntityService::resolveGoldenRecord()` / `pickWinner()`, dropping the
 * hardcoded entity-type/attribute names and fixing the tie-break to compare
 * the freshness anchor as a parsed date rather than lexically.
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
 * Computes a golden record + per-attribute provenance from linked source
 * records, driven entirely by the `x-openregister-survivorship` config.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
class SurvivorshipResolver
{
    /**
     * Default ordered tier list (weakest first) when the config omits one.
     *
     * @var array<int, string>
     */
    private const DEFAULT_TIER_ORDER = ['discard', 'bronze', 'silver', 'gold'];

    /**
     * Default tier for an uncontested source with no matching trust row.
     *
     * @var string
     */
    private const DEFAULT_TIER = 'bronze';

    /**
     * Default tier that is always excluded from the golden record.
     *
     * @var string
     */
    private const DEFAULT_DISCARD_TIER = 'discard';

    /**
     * Resolve the golden record + attribute provenance for a set of linked
     * source records.
     *
     * Never throws: a malformed source record (non-array, or one that raises
     * while being processed) is simply skipped so one bad record cannot
     * abort the whole resolution.
     *
     * @param string                           $entityType    Entity type (passed through to the trust lookup; never hardcoded).
     * @param array<int, array<string, mixed>> $sourceRecords Linked source records.
     * @param array<string, mixed>             $config        The `x-openregister-survivorship` annotation.
     * @param array<int, array<string, mixed>> $trustRows     Candidate trust-configuration rows.
     * @param TrustTierResolver                $trustResolver Pure trust-tier lookup + decay engine.
     * @param DateTimeImmutable                $asOf          Reference instant for effectiveFrom + decay.
     *
     * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
     */
    public function resolveGoldenRecord(
        string $entityType,
        array $sourceRecords,
        array $config,
        array $trustRows,
        TrustTierResolver $trustResolver,
        DateTimeImmutable $asOf
    ): array {
        $tierOrder   = $this->tierOrder(config: $config);
        $defaultTier = (string) ($config['defaultTier'] ?? self::DEFAULT_TIER);
        $discardTier = (string) ($config['discardTier'] ?? self::DEFAULT_DISCARD_TIER);
        $anchorField = (string) ($config['freshnessAnchorField'] ?? '');

        $candidates = $this->collectCandidates(
            entityType: $entityType,
            sourceRecords: $sourceRecords,
            tierOrder: $tierOrder,
            defaultTier: $defaultTier,
            discardTier: $discardTier,
            anchorField: $anchorField,
            trustRows: $trustRows,
            trustResolver: $trustResolver,
            asOf: $asOf
        );

        $goldenRecord = [];
        $provenance   = [];
        foreach ($candidates as $attribute => $options) {
            $winner = $this->pickWinner(options: $options, tierOrder: $tierOrder, trustResolver: $trustResolver);
            if ($winner === null) {
                continue;
            }

            $goldenRecord[$attribute] = $winner['value'];
            $provenance[$attribute]   = [
                'value'        => $winner['value'],
                'sourceSystem' => $winner['sourceSystem'],
                'trustTier'    => $winner['trustTier'],
                'lastUpdated'  => $winner['lastUpdated'],
            ];
        }

        return [
            'goldenRecord'        => $goldenRecord,
            'attributeProvenance' => $provenance,
        ];
    }//end resolveGoldenRecord()

    /**
     * Collect, per attribute, the competing candidate values across all
     * non-withdrawn source records with a non-empty value for that attribute.
     *
     * @param string                           $entityType    Entity type.
     * @param array<int, mixed>                $sourceRecords Linked source records; entries are
     *                                                        untrusted external data and are
     *                                                        re-validated as arrays below.
     * @param array<int, string>               $tierOrder     Ordered tier names, weakest first.
     * @param string                           $defaultTier   Fallback tier for an unmatched tuple.
     * @param string                           $discardTier   Tier excluded from competing.
     * @param string                           $anchorField   Field holding the freshness anchor date.
     * @param array<int, array<string, mixed>> $trustRows     Candidate trust-configuration rows.
     * @param TrustTierResolver                $trustResolver Trust lookup + decay engine.
     * @param DateTimeImmutable                $asOf          Reference instant.
     *
     * @return array<string, array<int, array<string, mixed>>> Attribute => list of candidate options.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential per-record/per-attribute guards
     *   (withdrawn, malformed, empty value, discard tier) each independently skip a candidate;
     *   splitting them further would scatter one linear collection pass across helpers.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale — flat sequential guards.
     */
    private function collectCandidates(
        string $entityType,
        array $sourceRecords,
        array $tierOrder,
        string $defaultTier,
        string $discardTier,
        string $anchorField,
        array $trustRows,
        TrustTierResolver $trustResolver,
        DateTimeImmutable $asOf
    ): array {
        $candidates = [];

        foreach ($sourceRecords as $record) {
            try {
                if (is_array($record) === false) {
                    continue;
                }

                if (($record['withdrawn'] ?? false) === true) {
                    continue;
                }

                $values = ($record['values'] ?? $record['mappedAttributes'] ?? null);
                if (is_array($values) === false) {
                    continue;
                }

                $sourceSystem = (string) ($record['sourceSystem'] ?? '');

                $anchorValue = null;
                if ($anchorField !== '') {
                    $anchorValue = ($record[$anchorField] ?? null);
                }

                $anchor = $this->parseDate(value: $anchorValue);

                foreach ($values as $attribute => $value) {
                    if ($this->isPresent(value: $value) === false) {
                        continue;
                    }

                    $attribute = (string) $attribute;

                    $tier = $trustResolver->resolveTier(
                        entityType: $entityType,
                        attribute: $attribute,
                        sourceSystem: $sourceSystem,
                        trustRows: $trustRows,
                        asOf: $asOf
                    );

                    $matched = ($tier !== null);
                    if ($matched === false) {
                        $tier = $defaultTier;
                    }

                    $decayDays = null;
                    if ($matched === true) {
                        $decayDays = $this->decayDaysFor(
                            trustRows: $trustRows,
                            entityType: $entityType,
                            attribute: $attribute,
                            sourceSystem: $sourceSystem
                        );
                    }

                    $effectiveTier = $trustResolver->applyFreshnessDecay(
                        tier: $tier,
                        tierOrder: $tierOrder,
                        freshnessDecayDays: $decayDays,
                        anchor: $anchor,
                        asOf: $asOf
                    );

                    if ($effectiveTier === $discardTier) {
                        continue;
                    }

                    $candidates[$attribute][] = [
                        'value'        => $value,
                        'sourceSystem' => $sourceSystem,
                        'trustTier'    => $effectiveTier,
                        'lastUpdated'  => ($anchor?->format(DATE_ATOM) ?? ''),
                    ];
                }//end foreach
            } catch (Throwable) {
                // A malformed source record must never abort resolution of the rest.
                continue;
            }//end try
        }//end foreach

        return $candidates;
    }//end collectCandidates()

    /**
     * Pick the winning candidate for one attribute: highest tier rank, then
     * (on a tie) the most recently updated source, compared as a parsed date.
     *
     * @param array<int, array<string, mixed>> $options       Candidate options for one attribute.
     * @param array<int, string>               $tierOrder     Ordered tier names, weakest first.
     * @param TrustTierResolver                $trustResolver Trust lookup + decay engine (for tierRank).
     *
     * @return array<string, mixed>|null The winning candidate, or null when none are eligible.
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
     */
    private function pickWinner(array $options, array $tierOrder, TrustTierResolver $trustResolver): ?array
    {
        $winner     = null;
        $winnerRank = -1;
        $winnerDate = null;

        foreach ($options as $option) {
            $rank = $trustResolver->tierRank(tier: (string) $option['trustTier'], tierOrder: $tierOrder);
            if ($rank < 0) {
                continue;
            }

            $date = $this->parseDate(value: ($option['lastUpdated'] ?? null));

            if ($winner === null || $rank > $winnerRank) {
                $winner     = $option;
                $winnerRank = $rank;
                $winnerDate = $date;
                continue;
            }

            if ($rank === $winnerRank && $this->isMoreRecent(candidate: $date, current: $winnerDate) === true) {
                $winner     = $option;
                $winnerDate = $date;
            }
        }//end foreach

        return $winner;
    }//end pickWinner()

    /**
     * Compare two (possibly absent) parsed dates chronologically. An absent
     * date never counts as more recent — this is the date-correct
     * replacement for pipelinq's lexical `(string) $a > (string) $b` compare.
     *
     * @param DateTimeImmutable|null $candidate Candidate's parsed anchor date.
     * @param DateTimeImmutable|null $current   Current winner's parsed anchor date.
     *
     * @return bool True when `$candidate` is strictly more recent than `$current`.
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
     */
    private function isMoreRecent(?DateTimeImmutable $candidate, ?DateTimeImmutable $current): bool
    {
        if ($candidate === null) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        return $candidate->getTimestamp() > $current->getTimestamp();
    }//end isMoreRecent()

    /**
     * Look up the `freshnessDecayDays` declared on the matching trust row(s)
     * for a tuple. Mirrors the match used by `TrustTierResolver::resolveTier()`
     * without duplicating its effectiveFrom-ranking logic — this reads the
     * decay window off the same row set the tier itself was resolved from.
     *
     * @param array<int, mixed> $trustRows    Candidate trust-configuration rows; entries are
     *                                        untrusted external data and are re-validated as
     *                                        arrays below.
     * @param string            $entityType   Entity type to match.
     * @param string            $attribute    Attribute to match.
     * @param string            $sourceSystem Source system to match.
     *
     * @return int|null The decay window in days, or null when undeclared/unmatched.
     */
    private function decayDaysFor(array $trustRows, string $entityType, string $attribute, string $sourceSystem): ?int
    {
        foreach ($trustRows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            if ((string) ($row['entityType'] ?? '') !== $entityType
                || (string) ($row['attribute'] ?? '') !== $attribute
                || (string) ($row['sourceSystem'] ?? '') !== $sourceSystem
            ) {
                continue;
            }

            $days = ($row['freshnessDecayDays'] ?? null);
            if (is_numeric($days) === true) {
                return (int) $days;
            }
        }

        return null;
    }//end decayDaysFor()

    /**
     * Resolve the config's `tierOrder`, falling back to the default when
     * absent or malformed.
     *
     * @param array<string, mixed> $config The `x-openregister-survivorship` annotation.
     *
     * @return array<int, string> Ordered tier names, weakest first.
     */
    private function tierOrder(array $config): array
    {
        $declared = ($config['tierOrder'] ?? null);
        if (is_array($declared) === false || count($declared) === 0) {
            return self::DEFAULT_TIER_ORDER;
        }

        $clean = [];
        foreach ($declared as $tier) {
            if (is_string($tier) === true && $tier !== '') {
                $clean[] = $tier;
            }
        }

        if (count($clean) === 0) {
            return self::DEFAULT_TIER_ORDER;
        }

        return $clean;
    }//end tierOrder()

    /**
     * Determine whether a value counts as present (non-null, non-empty-string).
     *
     * @param mixed $value Value to test.
     *
     * @return bool True when the value is meaningfully populated.
     */
    private function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) === true) {
            return trim($value) !== '';
        }

        return true;
    }//end isPresent()

    /**
     * Parse a date-ish value into a DateTimeImmutable, never throwing.
     * Unparseable/absent values return null (sorted as oldest by callers).
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
