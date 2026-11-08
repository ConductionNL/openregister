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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class owns the full
 *   pure resolution pipeline (candidate collection, tier ranking, tie-break,
 *   freshness decay, AND now per-object override short-circuiting) as one
 *   cohesive, side-effect-free algorithm per the spec; splitting the override
 *   short-circuit into a separate collaborator would scatter one resolution
 *   contract across two classes for a step that is intrinsically the last
 *   stage of the same pipeline (ADR-045 #E design.md D1/D2).
 */
class SurvivorshipResolver implements SurvivorshipResolverInterface {
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
	 * abort the whole resolution. Likewise a malformed override map (or
	 * malformed override entry) is skipped without throwing.
	 *
	 * @param string $entityType Entity type (passed through to the trust lookup; never hardcoded).
	 * @param array<int, array<string, mixed>> $sourceRecords Linked source records.
	 * @param array<string, mixed> $config The `x-openregister-survivorship` annotation.
	 * @param array<int, array<string, mixed>> $trustRows Candidate trust-configuration rows.
	 * @param TrustTierResolver $trustResolver Pure trust-tier lookup + decay engine.
	 * @param DateTimeImmutable $asOf Reference instant for effectiveFrom + decay.
	 * @param mixed $overrides Optional per-object attribute-override map
	 *                         (`attribute => {value, overriddenBy, rationale?}`
	 *                         or a bare `attribute => value` shorthand);
	 *                         untrusted input, validated defensively.
	 *
	 * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
	 *
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
	 */
	public function resolveGoldenRecord(
		string $entityType,
		array $sourceRecords,
		array $config,
		array $trustRows,
		TrustTierResolver $trustResolver,
		DateTimeImmutable $asOf,
		mixed $overrides = null,
	): array {
		$policy = new SurvivorshipTierPolicy(
			tierOrder: $this->tierOrder(config: $config),
			defaultTier: (string)($config['defaultTier'] ?? self::DEFAULT_TIER),
			discardTier: (string)($config['discardTier'] ?? self::DEFAULT_DISCARD_TIER),
			anchorField: (string)($config['freshnessAnchorField'] ?? '')
		);
		$trust  = new SurvivorshipTrustLookup(
			entityType: $entityType,
			trustRows: $trustRows,
			trustResolver: $trustResolver,
			asOf: $asOf
		);

		$candidates  = $this->collectCandidates(sourceRecords: $sourceRecords, policy: $policy, trust: $trust);
		$overrideMap = $this->normaliseOverrides(overrides: $overrides);
		$result      = $this->tierWinners(candidates: $candidates, tierOrder: $policy->tierOrder, trustResolver: $trustResolver);

		return $this->withOverrides(result: $result, overrideMap: $overrideMap);
	}//end resolveGoldenRecord()

	/**
	 * The golden record and provenance the tier selection alone gives.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $candidates    Attribute => candidate options.
	 * @param array<int, string>                              $tierOrder     Ordered tier names, weakest first.
	 * @param TrustTierResolver                               $trustResolver Trust lookup + decay engine (for tierRank).
	 *
	 * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function tierWinners(array $candidates, array $tierOrder, TrustTierResolver $trustResolver): array {
		$goldenRecord = [];
		$provenance   = [];
		foreach ($candidates as $attribute => $options) {
			$winner = $this->pickWinner(options: $options, tierOrder: $tierOrder, trustResolver: $trustResolver);
			if ($winner === null) {
				continue;
			}

			$goldenRecord[$attribute] = $winner['value'];
			$provenance[$attribute]   = [
				'value' => $winner['value'],
				'sourceSystem' => $winner['sourceSystem'],
				'trustTier' => $winner['trustTier'],
				'lastUpdated' => $winner['lastUpdated'],
			];
		}

		return [
			'goldenRecord' => $goldenRecord,
			'attributeProvenance' => $provenance,
		];
	}//end tierWinners()

	/**
	 * The result with every override applied over the tier-selected values.
	 *
	 * Overrides short-circuit tier selection unconditionally: an override can
	 * also populate an attribute no source supplies at all.
	 *
	 * @param array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>} $result      The tier selection.
	 * @param array<string, array{value: mixed, overriddenBy: string, rationale: string|null}>      $overrideMap The normalised overrides.
	 *
	 * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
	 *
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
	 */
	private function withOverrides(array $result, array $overrideMap): array {
		foreach ($overrideMap as $attribute => $override) {
			$result['goldenRecord'][$attribute]        = $override['value'];
			$result['attributeProvenance'][$attribute] = [
				'value' => $override['value'],
				'override' => true,
				'overriddenBy' => $override['overriddenBy'],
				'rationale' => $override['rationale'],
			];
		}

		return $result;
	}//end withOverrides()

	/**
	 * Normalise a raw override map into `attribute => {value, overriddenBy,
	 * rationale}` entries, silently dropping malformed entries. Accepts
	 * either the canonical `attribute => {value, overriddenBy?, rationale?}`
	 * shape or a bare `attribute => value` shorthand (`overriddenBy` then
	 * defaults to an empty string).
	 *
	 * @param mixed $overrides Raw override map (untrusted; may be non-array or malformed).
	 *
	 * @return array<string, array{value: mixed, overriddenBy: string, rationale: string|null}>
	 *
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
	 */
	private function normaliseOverrides(mixed $overrides): array {
		if (is_array($overrides) === false) {
			return [];
		}

		$clean = [];
		foreach ($overrides as $attribute => $entry) {
			if (is_string($attribute) === false || $attribute === '') {
				continue;
			}

			try {
				$normalised = $this->normaliseOverrideEntry(entry: $entry);
			} catch (Throwable) {
				// A malformed override entry must never abort resolution of the rest.
				continue;
			}

			if ($normalised !== null) {
				$clean[$attribute] = $normalised;
			}
		}//end foreach

		return $clean;
	}//end normaliseOverrides()

	/**
	 * Normalise a single override entry, accepting either the canonical
	 * `{value, overriddenBy?, rationale?}` shape or a bare scalar-value
	 * shorthand. Returns null when the entry is malformed or its value is
	 * not present (the caller then drops it from the override map).
	 *
	 * @param mixed $entry Raw override entry (untrusted; may be malformed).
	 *
	 * @return array{value: mixed, overriddenBy: string, rationale: string|null}|null
	 *
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
	 */
	private function normaliseOverrideEntry(mixed $entry): ?array {
		if (is_array($entry) === true) {
			if (array_key_exists('value', $entry) === false || $this->isPresent(value: $entry['value']) === false) {
				return null;
			}

			$rationale = null;
			if (($entry['rationale'] ?? null) !== null) {
				$rationale = (string)$entry['rationale'];
			}

			return [
				'value' => $entry['value'],
				'overriddenBy' => (string)($entry['overriddenBy'] ?? ''),
				'rationale' => $rationale,
			];
		}

		if ($this->isPresent(value: $entry) === true) {
			return [
				'value' => $entry,
				'overriddenBy' => '',
				'rationale' => null,
			];
		}

		return null;
	}//end normaliseOverrideEntry()

	/**
	 * Collect, per attribute, the competing candidate values across all
	 * non-withdrawn source records with a non-empty value for that attribute.
	 *
	 * @param array<int, mixed>       $sourceRecords Linked source records; entries are
	 *                                               untrusted external data and are
	 *                                               re-validated as arrays.
	 * @param SurvivorshipTierPolicy  $policy        The run's tiers.
	 * @param SurvivorshipTrustLookup $trust         The run's trust lookup.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Attribute => list of candidate options.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function collectCandidates(
		array $sourceRecords,
		SurvivorshipTierPolicy $policy,
		SurvivorshipTrustLookup $trust,
	): array {
		$candidates = [];
		foreach ($sourceRecords as $record) {
			try {
				$this->addRecordCandidates(record: $record, policy: $policy, trust: $trust, candidates: $candidates);
			} catch (Throwable) {
				// A malformed source record must never abort resolution of the
				// rest; what it contributed before failing stays collected.
				continue;
			}
		}

		return $candidates;
	}//end collectCandidates()

	/**
	 * Add one source record's competing values to the candidates.
	 *
	 * @param mixed                                           $record     One source record (untrusted).
	 * @param SurvivorshipTierPolicy                          $policy     The run's tiers.
	 * @param SurvivorshipTrustLookup                         $trust      The run's trust lookup.
	 * @param array<string, array<int, array<string, mixed>>> $candidates Attribute => candidate options, added to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function addRecordCandidates(
		mixed $record,
		SurvivorshipTierPolicy $policy,
		SurvivorshipTrustLookup $trust,
		array &$candidates,
	): void {
		if (is_array($record) === false || ($record['withdrawn'] ?? false) === true) {
			return;
		}

		$values = ($record['values'] ?? $record['mappedAttributes'] ?? null);
		if (is_array($values) === false) {
			return;
		}

		$sourceSystem = (string)($record['sourceSystem'] ?? '');
		$anchor       = $this->recordAnchor(record: $record, anchorField: $policy->anchorField);
		foreach ($values as $attribute => $value) {
			if ($this->isPresent(value: $value) === false) {
				continue;
			}

			$attribute = (string)$attribute;
			$tier      = $this->effectiveTier(attribute: $attribute, sourceSystem: $sourceSystem, anchor: $anchor, policy: $policy, trust: $trust);
			if ($tier === $policy->discardTier) {
				continue;
			}

			$candidates[$attribute][] = [
				'value' => $value,
				'sourceSystem' => $sourceSystem,
				'trustTier' => $tier,
				'lastUpdated' => ($anchor?->format(DATE_ATOM) ?? ''),
			];
		}
	}//end addRecordCandidates()

	/**
	 * The freshness anchor date of a record, null without an anchor field or a readable date.
	 *
	 * @param array<string, mixed> $record      The source record.
	 * @param string               $anchorField Field holding the freshness anchor date.
	 *
	 * @return DateTimeImmutable|null The anchor.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function recordAnchor(array $record, string $anchorField): ?DateTimeImmutable {
		$anchorValue = null;
		if ($anchorField !== '') {
			$anchorValue = ($record[$anchorField] ?? null);
		}

		return $this->parseDate(value: $anchorValue);
	}//end recordAnchor()

	/**
	 * The tier one source's value of one attribute competes at, after freshness decay.
	 *
	 * A tuple with no trust row takes the default tier and does not decay.
	 *
	 * @param string                  $attribute    The attribute.
	 * @param string                  $sourceSystem The source system.
	 * @param DateTimeImmutable|null  $anchor       The record's freshness anchor.
	 * @param SurvivorshipTierPolicy  $policy       The run's tiers.
	 * @param SurvivorshipTrustLookup $trust        The run's trust lookup.
	 *
	 * @return string The effective tier.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function effectiveTier(
		string $attribute,
		string $sourceSystem,
		?DateTimeImmutable $anchor,
		SurvivorshipTierPolicy $policy,
		SurvivorshipTrustLookup $trust,
	): string {
		$tier = $trust->trustResolver->resolveTier(
			entityType: $trust->entityType,
			attribute: $attribute,
			sourceSystem: $sourceSystem,
			trustRows: $trust->trustRows,
			asOf: $trust->asOf
		);

		$matched   = ($tier !== null);
		$decayDays = null;
		if ($matched === false) {
			$tier = $policy->defaultTier;
		}

		if ($matched === true) {
			$decayDays = $this->decayDaysFor(
				trustRows: $trust->trustRows,
				entityType: $trust->entityType,
				attribute: $attribute,
				sourceSystem: $sourceSystem
			);
		}

		return $trust->trustResolver->applyFreshnessDecay(
			tier: $tier,
			tierOrder: $policy->tierOrder,
			freshnessDecayDays: $decayDays,
			anchor: $anchor,
			asOf: $trust->asOf
		);
	}//end effectiveTier()

	/**
	 * Pick the winning candidate for one attribute: highest tier rank, then
	 * (on a tie) the most recently updated source, compared as a parsed date.
	 *
	 * @param array<int, array<string, mixed>> $options Candidate options for one attribute.
	 * @param array<int, string> $tierOrder Ordered tier names, weakest first.
	 * @param TrustTierResolver $trustResolver Trust lookup + decay engine (for tierRank).
	 *
	 * @return array<string, mixed>|null The winning candidate, or null when none are eligible.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function pickWinner(array $options, array $tierOrder, TrustTierResolver $trustResolver): ?array {
		$winner = null;
		$winnerRank = -1;
		$winnerDate = null;

		foreach ($options as $option) {
			$rank = $trustResolver->tierRank(tier: (string)$option['trustTier'], tierOrder: $tierOrder);
			if ($rank < 0) {
				continue;
			}

			$date = $this->parseDate(value: ($option['lastUpdated'] ?? null));

			if ($winner === null || $rank > $winnerRank) {
				$winner = $option;
				$winnerRank = $rank;
				$winnerDate = $date;
				continue;
			}

			if ($rank === $winnerRank && $this->isMoreRecent(candidate: $date, current: $winnerDate) === true) {
				$winner = $option;
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
	 * @param DateTimeImmutable|null $current Current winner's parsed anchor date.
	 *
	 * @return bool True when `$candidate` is strictly more recent than `$current`.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function isMoreRecent(?DateTimeImmutable $candidate, ?DateTimeImmutable $current): bool {
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
	 * @param array<int, mixed> $trustRows Candidate trust-configuration rows; entries are
	 *                                     untrusted external data and are re-validated as
	 *                                     arrays below.
	 * @param string $entityType Entity type to match.
	 * @param string $attribute Attribute to match.
	 * @param string $sourceSystem Source system to match.
	 *
	 * @return int|null The decay window in days, or null when undeclared/unmatched.
	 */
	private function decayDaysFor(array $trustRows, string $entityType, string $attribute, string $sourceSystem): ?int {
		foreach ($trustRows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			if ((string)($row['entityType'] ?? '') !== $entityType
				|| (string)($row['attribute'] ?? '') !== $attribute
				|| (string)($row['sourceSystem'] ?? '') !== $sourceSystem
			) {
				continue;
			}

			$days = ($row['freshnessDecayDays'] ?? null);
			if (is_numeric($days) === true) {
				return (int)$days;
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
	private function tierOrder(array $config): array {
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
	private function isPresent(mixed $value): bool {
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
	private function parseDate(mixed $value): ?DateTimeImmutable {
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
