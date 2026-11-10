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
use Generator;
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
	 * @param array<int, array<string, mixed>> $sourceRecords Linked source records.
	 * @param array<string, mixed> $config The `x-openregister-survivorship` annotation.
	 * @param SurvivorshipTrustLookup $trust Entity type (never hardcoded), trust rows, trust engine
	 *                                       and reference instant for effectiveFrom + decay.
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
		array $sourceRecords,
		array $config,
		SurvivorshipTrustLookup $trust,
		mixed $overrides = null,
	): array {
		return $this->resolve(request: new GoldenRecordRequest(sourceRecords: $sourceRecords, config: $config, trust: $trust, overrides: $overrides));
	}//end resolveGoldenRecord()

	/**
	 * Resolve the golden record for one request; what {@see self::resolveGoldenRecord()} describes.
	 *
	 * @param GoldenRecordRequest $request The source records, annotation, trust lookup and overrides.
	 *
	 * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
	 *
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
	 */
	public function resolve(GoldenRecordRequest $request): array {
		$config = $request->config;
		$policy = new SurvivorshipTierPolicy(
			tierOrder: $this->tierOrder(config: $config),
			defaultTier: (string)($config['defaultTier'] ?? self::DEFAULT_TIER),
			discardTier: (string)($config['discardTier'] ?? self::DEFAULT_DISCARD_TIER),
			anchorField: (string)($config['freshnessAnchorField'] ?? '')
		);

		$run = new SurvivorshipRun(policy: $policy, trust: $request->trust);

		$candidates  = $this->collectCandidates(sourceRecords: $request->sourceRecords, run: $run);
		$overrideMap = $this->normaliseOverrides(overrides: $request->overrides);
		$result      = $this->tierWinners(candidates: $candidates, run: $run);

		return $this->withOverrides(result: $result, overrideMap: $overrideMap);
	}//end resolve()

	/**
	 * The golden record and provenance the tier selection alone gives.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $candidates Attribute => candidate options.
	 * @param SurvivorshipRun                                 $run        The run's tiers and trust lookup.
	 *
	 * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function tierWinners(array $candidates, SurvivorshipRun $run): array {
		$goldenRecord = [];
		$provenance   = [];

		foreach ($candidates as $attribute => $options) {
			$winner = $this->pickWinner(options: $options, run: $run);
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
			$normalised = $this->overrideFor(attribute: $attribute, entry: $entry);
			if ($normalised !== null) {
				$clean[$attribute] = $normalised;
			}
		}

		return $clean;
	}//end normaliseOverrides()

	/**
	 * One raw override entry, normalised, or null when its key or entry is
	 * malformed and it is silently dropped.
	 *
	 * @param mixed $attribute Raw override key (untrusted).
	 * @param mixed $entry     Raw override entry (untrusted).
	 *
	 * @return array{value: mixed, overriddenBy: string, rationale: string|null}|null
	 *
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
	 */
	private function overrideFor(mixed $attribute, mixed $entry): ?array {
		if (is_string($attribute) === false || $attribute === '') {
			return null;
		}

		try {
			return $this->normaliseOverrideEntry(entry: $entry);
		} catch (Throwable) {
			// A malformed override entry must never abort resolution of the rest.
			return null;
		}
	}//end overrideFor()

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
			return $this->normaliseCanonicalOverride(entry: $entry);
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
	 * Normalise a canonical `{value, overriddenBy?, rationale?}` override
	 * entry; null when its value is not present.
	 *
	 * @param array<mixed> $entry Raw override entry (untrusted; may be malformed).
	 *
	 * @return array{value: mixed, overriddenBy: string, rationale: string|null}|null
	 *
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
	 */
	private function normaliseCanonicalOverride(array $entry): ?array {
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
	}//end normaliseCanonicalOverride()

	/**
	 * Collect, per attribute, the competing candidate values across all
	 * non-withdrawn source records with a non-empty value for that attribute.
	 *
	 * @param array<int, mixed> $sourceRecords Linked source records; entries are
	 *                                         untrusted external data and are
	 *                                         re-validated as arrays.
	 * @param SurvivorshipRun   $run           The run's tiers and trust lookup.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Attribute => list of candidate options.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function collectCandidates(array $sourceRecords, SurvivorshipRun $run): array {
		$candidates = [];
		foreach ($sourceRecords as $record) {
			try {
				foreach ($this->recordCandidates(record: $record, run: $run) as $attribute => $candidate) {
					$candidates[$attribute][] = $candidate;
				}
			} catch (Throwable) {
				// A malformed source record must never abort resolution of the
				// rest; what it contributed before failing stays collected.
				continue;
			}
		}

		return $candidates;
	}//end collectCandidates()

	/**
	 * One source record's competing values, yielded one at a time keyed by
	 * attribute, so what a record yielded before failing stays collected.
	 *
	 * @param mixed           $record One source record (untrusted).
	 * @param SurvivorshipRun $run    The run's tiers and trust lookup.
	 *
	 * @return Generator<string, array<string, mixed>> Attribute => candidate option.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function recordCandidates(mixed $record, SurvivorshipRun $run): Generator {
		$values = $this->competingValues(record: $record);
		if ($values === null) {
			return;
		}

		$source = new SurvivorshipSource(
			sourceSystem: (string)($record['sourceSystem'] ?? ''),
			anchor: $this->recordAnchor(record: $record, anchorField: $run->policy->anchorField)
		);

		foreach ($values as $attribute => $value) {
			if ($this->isPresent(value: $value) === false) {
				continue;
			}

			$attribute = (string)$attribute;
			$tier      = $this->effectiveTier(claim: new AttributeClaim(attribute: $attribute, source: $source), run: $run);
			if ($tier === $run->policy->discardTier) {
				continue;
			}

			yield $attribute => [
				'value' => $value,
				'sourceSystem' => $source->sourceSystem,
				'trustTier' => $tier,
				'lastUpdated' => ($source->anchor?->format(DATE_ATOM) ?? ''),
			];
		}
	}//end recordCandidates()

	/**
	 * The values a source record competes with, null for a non-array or
	 * withdrawn record or one without a values map.
	 *
	 * @param mixed $record One source record (untrusted).
	 *
	 * @return array<mixed>|null The record's `values` (or `mappedAttributes`).
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function competingValues(mixed $record): ?array {
		if (is_array($record) === false || ($record['withdrawn'] ?? false) === true) {
			return null;
		}

		$values = ($record['values'] ?? $record['mappedAttributes'] ?? null);
		if (is_array($values) === false) {
			return null;
		}

		return $values;
	}//end competingValues()

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

		return SurvivorshipDate::parse(value: $anchorValue);
	}//end recordAnchor()

	/**
	 * The tier one source's value of one attribute competes at, after freshness decay.
	 *
	 * @param AttributeClaim  $claim The attribute and the source supplying it.
	 * @param SurvivorshipRun $run   The run's tiers and trust lookup.
	 *
	 * @return string The effective tier.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function effectiveTier(AttributeClaim $claim, SurvivorshipRun $run): string {
		$trust    = $run->trust;
		$source   = $claim->source;
		$tuple    = new TrustTuple(entityType: $trust->entityType, attribute: $claim->attribute, sourceSystem: $source->sourceSystem);
		$baseline = $this->baselineTier(tuple: $tuple, run: $run);

		return $trust->trustResolver->applyFreshnessDecay(
			position: new TierPosition(tier: $baseline['tier'], tierOrder: $run->policy->tierOrder),
			freshness: new SourceFreshness(decayDays: $baseline['decayDays'], anchor: $source->anchor, asOf: $trust->asOf)
		);
	}//end effectiveTier()

	/**
	 * The tier one tuple competes at before freshness decay, and its decay window.
	 *
	 * A tuple with no trust row takes the default tier and does not decay.
	 *
	 * @param TrustTuple      $tuple The (entityType, attribute, sourceSystem) tuple.
	 * @param SurvivorshipRun $run   The run's tiers and trust lookup.
	 *
	 * @return array{tier: string, decayDays: int|null}
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function baselineTier(TrustTuple $tuple, SurvivorshipRun $run): array {
		$trust = $run->trust;
		$tier  = $trust->trustResolver->resolveTier(tuple: $tuple, trustRows: $trust->trustRows, asOf: $trust->asOf);

		if ($tier === null) {
			return ['tier' => $run->policy->defaultTier, 'decayDays' => null];
		}

		return ['tier' => $tier, 'decayDays' => $this->decayDaysFor(trustRows: $trust->trustRows, tuple: $tuple)];
	}//end baselineTier()

	/**
	 * Pick the winning candidate for one attribute: highest tier rank, then
	 * (on a tie) the most recently updated source, compared as a parsed date.
	 *
	 * @param array<int, array<string, mixed>> $options Candidate options for one attribute.
	 * @param SurvivorshipRun $run The run's tiers and trust engine (for tierRank).
	 *
	 * @return array<string, mixed>|null The winning candidate, or null when none are eligible.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function pickWinner(array $options, SurvivorshipRun $run): ?array {
		$trustResolver = $run->trust->trustResolver;
		$tierOrder     = $run->policy->tierOrder;
		$winner   = null;
		$standing = new CandidateStanding(rank: -1, date: null);

		foreach ($options as $option) {
			$rank = $trustResolver->tierRank(tier: (string)$option['trustTier'], tierOrder: $tierOrder);
			if ($rank < 0) {
				continue;
			}

			$candidate = new CandidateStanding(rank: $rank, date: SurvivorshipDate::parse(value: ($option['lastUpdated'] ?? null)));
			if ($this->displaces(candidate: $candidate, winner: $standing) === true) {
				$winner   = $option;
				$standing = $candidate;
			}
		}

		return $winner;
	}//end pickWinner()

	/**
	 * Whether an eligible candidate displaces the current winner: a higher
	 * tier rank, or (on a tie) a strictly more recent date. With no winner
	 * yet the winner rank is -1, which every eligible rank (>= 0) beats.
	 *
	 * @param CandidateStanding $candidate The candidate's tier rank (>= 0) and parsed date.
	 * @param CandidateStanding $winner    The current winner's standing, rank -1 without a winner.
	 *
	 * @return bool True when the candidate becomes the winner.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	private function displaces(CandidateStanding $candidate, CandidateStanding $winner): bool {
		return $candidate->rank > $winner->rank
			|| ($candidate->rank === $winner->rank && $this->isMoreRecent(candidate: $candidate->date, current: $winner->date) === true);
	}//end displaces()

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
	 * @param TrustTuple $tuple The (entityType, attribute, sourceSystem) tuple to match.
	 *
	 * @return int|null The decay window in days, or null when undeclared/unmatched.
	 */
	private function decayDaysFor(array $trustRows, TrustTuple $tuple): ?int {
		foreach ($trustRows as $row) {
			if ($this->rowMatchesTuple(row: $row, tuple: $tuple) === false) {
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
	 * Whether a trust row is an array matching the tuple on entity type,
	 * attribute and source system.
	 *
	 * @param mixed      $row   Candidate trust row (untrusted).
	 * @param TrustTuple $tuple The tuple to match.
	 *
	 * @return bool True when the row matches.
	 */
	private function rowMatchesTuple(mixed $row, TrustTuple $tuple): bool {
		if (is_array($row) === false) {
			return false;
		}

		return (string)($row['entityType'] ?? '') === $tuple->entityType
			&& (string)($row['attribute'] ?? '') === $tuple->attribute
			&& (string)($row['sourceSystem'] ?? '') === $tuple->sourceSystem;
	}//end rowMatchesTuple()

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

		$clean = $this->namedTiers(declared: $declared);
		if (count($clean) === 0) {
			return self::DEFAULT_TIER_ORDER;
		}

		return $clean;
	}//end tierOrder()

	/**
	 * The non-empty string entries of a declared tier list, in order.
	 *
	 * @param array<mixed> $declared The declared `tierOrder`.
	 *
	 * @return array<int, string> The usable tier names.
	 */
	private function namedTiers(array $declared): array {
		$clean = [];
		foreach ($declared as $tier) {
			if (is_string($tier) === true && $tier !== '') {
				$clean[] = $tier;
			}
		}

		return $clean;
	}//end namedTiers()

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
}//end class
