<?php

/**
 * OpenRegister Gdpr DpiaPatternDetectionService
 *
 * Pure GDPR art-35 DPIA pattern detection over DSAR cases (generalising
 * pipelinq's retired `DpiaDetectionService`, closing the second
 * `consume-or-dsar` audit gap): cases received inside a rolling window are
 * grouped by configurable characteristics (default: request `type` plus
 * normalised scope) and every group whose size reaches the configured
 * threshold is reported for flagging. Dependency-light and unit-testable in
 * the {@see DataSubjectDeadline} style — the service holds NO storage or
 * notification concerns; the daily
 * {@see \OCA\OpenRegister\BackgroundJob\DsarDpiaDetectionJob} feeds it cases
 * + pack config and performs the audited writes.
 *
 * Fail-safe: an empty/absent configuration yields no groups — never a false
 * DPIA flag.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use DateTimeImmutable;

/**
 * Group + threshold DSAR cases for DPIA pattern detection.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 *   (Requirement: DPIA pattern detection over DSAR cases)
 */
class DpiaPatternDetectionService implements DpiaPatternDetectorInterface {

	/**
	 * Default threshold, matching pipelinq's `DpiaDetectionService::DEFAULT_THRESHOLD`.
	 *
	 * @var int
	 */
	public const DEFAULT_THRESHOLD = 10;

	/**
	 * Default rolling window, matching pipelinq's `DpiaDetectionService::WINDOW_DAYS`.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_DAYS = 30;

	/**
	 * Default grouping characteristics (request type + normalised scope).
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_GROUP_BY = ['type', 'scope'];

	/**
	 * Detect the case groups that require a DPIA flag.
	 *
	 * Groups every case whose `receivedAt` lies inside the rolling window by
	 * the configured characteristics (values normalised: trim, lowercase,
	 * collapse whitespace — deliberately dumb v1 parity with pipelinq's raw
	 * equality) and returns each group whose member count reaches the
	 * threshold. Already-flagged cases COUNT toward their group (detection
	 * reflects real volume) but are listed separately so the caller never
	 * re-writes them (idempotency).
	 *
	 * @param array<int, mixed> $cases Case payloads (each element is validated as an array at
	 *                                 runtime); each needs `receivedAt` + the group-by fields +
	 *                                 optionally `dpiaRequired` and a caller-supplied `@uuid`.
	 * @param array<string, mixed> $config The pack's `dpiaDetection` block (threshold, windowDays, groupBy).
	 * @param DateTimeImmutable $now The evaluation clock.
	 *
	 * @return array<int, array{key: string, count: int, caseUuids: array<int, string>, unflaggedUuids: array<int, string>}> Triggering groups.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 *   (Scenario: Threshold crossing flags the group)
	 */
	public function detect(array $cases, array $config, DateTimeImmutable $now): array {
		$threshold = $this->positiveInt(config: $config, key: 'threshold');
		$windowDays = $this->positiveInt(config: $config, key: 'windowDays');

		// Fail-safe: an unusable configuration produces no groups — never a
		// false DPIA flag (no-pack / no-block callers pass an empty config).
		if ($threshold === 0 || $windowDays === 0) {
			return [];
		}

		$groups = $this->groupCases(
			cases: $cases,
			groupBy: $this->groupByOf(config: $config),
			windowStart: $now->modify(sprintf('-%d days', $windowDays)),
			now: $now
		);

		return array_values(array_filter($groups, static fn (array $group): bool => $group['count'] >= $threshold));
	}//end detect()

	/**
	 * Build a group key from the configured characteristics, each value
	 * normalised (trim / lowercase / collapse whitespace).
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param array<int, mixed> $groupBy The grouping field names.
	 *
	 * @return string The composite group key (`field=value|field=value`).
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 *   (Requirement: DPIA pattern detection over DSAR cases)
	 */
	public function groupKey(array $case, array $groupBy): string {
		$parts = [];
		foreach ($groupBy as $field) {
			$field = (string)$field;
			$value = ($case[$field] ?? '');
			$parts[] = $field . '=' . $this->normalise(value: $value);
		}

		return implode('|', $parts);
	}//end groupKey()

	/**
	 * Normalise a grouping value: trim, lowercase, collapse whitespace.
	 * Non-scalar values normalise to '' (they group together, never crash).
	 *
	 * @param mixed $value The raw field value.
	 *
	 * @return string The normalised value.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 *   (Requirement: DPIA pattern detection over DSAR cases)
	 */
	public function normalise(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		$normalised = mb_strtolower(trim((string)$value));

		return (string)preg_replace('/\s+/', ' ', $normalised);
	}//end normalise()

	/**
	 * A configured integer, or 0 when absent or below 1.
	 *
	 * @param array<string, mixed> $config The pack's `dpiaDetection` block.
	 * @param string               $key    The setting.
	 *
	 * @return int
	 */
	private function positiveInt(array $config, string $key): int {
		return max((int)($config[$key] ?? 0), 0);

	}//end positiveInt()

	/**
	 * Group every in-window case by its key, in first-seen order.
	 *
	 * @param array<int, mixed>  $cases       Case payloads.
	 * @param array<int, mixed>  $groupBy     The grouping field names.
	 * @param DateTimeImmutable  $windowStart Start of the rolling window.
	 * @param DateTimeImmutable  $now         The evaluation clock.
	 *
	 * @return array<string, array{key: string, count: int, caseUuids: array<int, string>, unflaggedUuids: array<int, string>}>
	 */
	private function groupCases(array $cases, array $groupBy, DateTimeImmutable $windowStart, DateTimeImmutable $now): array {
		$groups = [];
		foreach ($cases as $case) {
			if ($this->inWindow(entry: $case, windowStart: $windowStart, now: $now) === true) {
				$this->tally(groups: $groups, entry: $case, key: $this->groupKey(case: $case, groupBy: $groupBy));
			}
		}

		return $groups;

	}//end groupCases()

	/**
	 * The configured grouping fields, or the defaults when unset / empty / not a list.
	 *
	 * @param array<string, mixed> $config The pack's `dpiaDetection` block.
	 *
	 * @return array<int, mixed>
	 */
	private function groupByOf(array $config): array {
		$groupBy = ($config['groupBy'] ?? null);
		if (is_array($groupBy) === false || $groupBy === []) {
			return self::DEFAULT_GROUP_BY;
		}

		return $groupBy;

	}//end groupByOf()

	/**
	 * Whether a case was received inside the window (both ends inclusive).
	 *
	 * A non-array case or an unusable `receivedAt` is outside.
	 *
	 * @param mixed             $entry       One element of the case list.
	 * @param DateTimeImmutable $windowStart Start of the rolling window.
	 * @param DateTimeImmutable $now         The evaluation clock.
	 *
	 * @return bool
	 */
	private function inWindow(mixed $entry, DateTimeImmutable $windowStart, DateTimeImmutable $now): bool {
		if (is_array($entry) === false) {
			return false;
		}

		$receivedAt = $this->parseDate(value: ($entry['receivedAt'] ?? null));

		return $receivedAt !== null && $receivedAt >= $windowStart && $receivedAt <= $now;

	}//end inWindow()

	/**
	 * Count a case into its group, listing its uuid (and as unflagged unless flagged).
	 *
	 * Every case counts; only a case with an `@uuid` / `id` is listed.
	 *
	 * @param array<string, array<string, mixed>> $groups The groups so far (mutated by reference).
	 * @param array<string, mixed>                $entry  The case payload.
	 * @param string                              $key    Its group key.
	 *
	 * @return void
	 */
	private function tally(array &$groups, array $entry, string $key): void {
		$groups[$key] ??= ['key' => $key, 'count' => 0, 'caseUuids' => [], 'unflaggedUuids' => []];
		$groups[$key]['count']++;

		$uuid = (string)($entry['@uuid'] ?? ($entry['id'] ?? ''));
		if ($uuid === '') {
			return;
		}

		$groups[$key]['caseUuids'][] = $uuid;
		if (($entry['dpiaRequired'] ?? false) !== true) {
			$groups[$key]['unflaggedUuids'][] = $uuid;
		}

	}//end tally()

	/**
	 * Parse a date-time value defensively, null on anything unusable.
	 *
	 * @param mixed $value The raw `receivedAt` value.
	 *
	 * @return DateTimeImmutable|null The parsed instant, or null.
	 */
	private function parseDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}

	}//end parseDate()
}//end class
