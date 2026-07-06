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
class DpiaPatternDetectionService
{

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
     * @param array<int, array<string, mixed>> $cases  Case payloads; each needs `receivedAt` + the
     *                                                 group-by fields + optionally `dpiaRequired`
     *                                                 and a caller-supplied `@uuid`.
     * @param array<string, mixed>             $config The pack's `dpiaDetection` block (threshold, windowDays, groupBy).
     * @param DateTimeImmutable                $now    The evaluation clock.
     *
     * @return array<int, array{key: string, count: int, caseUuids: array<int, string>, unflaggedUuids: array<int, string>}> Triggering groups.
     *
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
     *   (Scenario: Threshold crossing flags the group)
     */
    public function detect(array $cases, array $config, DateTimeImmutable $now): array
    {
        $threshold  = (int) ($config['threshold'] ?? 0);
        $windowDays = (int) ($config['windowDays'] ?? 0);
        $groupBy    = ($config['groupBy'] ?? null);

        // Fail-safe: an unusable configuration produces no groups — never a
        // false DPIA flag (no-pack / no-block callers pass an empty config).
        if ($threshold < 1 || $windowDays < 1) {
            return [];
        }

        if (is_array($groupBy) === false || $groupBy === []) {
            $groupBy = self::DEFAULT_GROUP_BY;
        }

        $windowStart = $now->modify(sprintf('-%d days', $windowDays));

        $groups = [];
        foreach ($cases as $case) {
            if (is_array($case) === false) {
                continue;
            }

            $receivedAt = $this->parseDate(value: ($case['receivedAt'] ?? null));
            if ($receivedAt === null || $receivedAt < $windowStart || $receivedAt > $now) {
                continue;
            }

            $key = $this->groupKey(case: $case, groupBy: $groupBy);

            if (isset($groups[$key]) === false) {
                $groups[$key] = [
                    'key'            => $key,
                    'count'          => 0,
                    'caseUuids'      => [],
                    'unflaggedUuids' => [],
                ];
            }

            $groups[$key]['count']++;

            $uuid = (string) ($case['@uuid'] ?? ($case['id'] ?? ''));
            if ($uuid === '') {
                continue;
            }

            $groups[$key]['caseUuids'][] = $uuid;
            if (($case['dpiaRequired'] ?? false) !== true) {
                $groups[$key]['unflaggedUuids'][] = $uuid;
            }
        }//end foreach

        $triggering = [];
        foreach ($groups as $group) {
            if ($group['count'] >= $threshold) {
                $triggering[] = $group;
            }
        }

        return $triggering;

    }//end detect()

    /**
     * Build a group key from the configured characteristics, each value
     * normalised (trim / lowercase / collapse whitespace).
     *
     * @param array<string, mixed> $case    The case payload.
     * @param array<int, mixed>    $groupBy The grouping field names.
     *
     * @return string The composite group key (`field=value|field=value`).
     *
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
     *   (Requirement: DPIA pattern detection over DSAR cases)
     */
    public function groupKey(array $case, array $groupBy): string
    {
        $parts = [];
        foreach ($groupBy as $field) {
            $field   = (string) $field;
            $value   = ($case[$field] ?? '');
            $parts[] = $field.'='.$this->normalise(value: $value);
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
    public function normalise(mixed $value): string
    {
        if (is_scalar($value) === false) {
            return '';
        }

        $normalised = mb_strtolower(trim((string) $value));

        return (string) preg_replace('/\s+/', ' ', $normalised);

    }//end normalise()

    /**
     * Parse a date-time value defensively, null on anything unusable.
     *
     * @param mixed $value The raw `receivedAt` value.
     *
     * @return DateTimeImmutable|null The parsed instant, or null.
     */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
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
