<?php

/**
 * DpiaDetectionRun — one DPIA pattern detection over a list of DSAR cases.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use DateTimeImmutable;

/**
 * The usable threshold, grouping fields and window of one detection, and its groups so far.
 *
 * Built by DpiaPatternDetectionService::detect() and never handed out of it.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */
final class DpiaDetectionRun {
	/**
	 * The groups counted so far, keyed by group key, in first-seen order.
	 *
	 * @var array<string, array{key: string, count: int, caseUuids: array<int, string>, unflaggedUuids: array<int, string>}>
	 */
	public array $groups = [];

	/**
	 * Open the detection.
	 *
	 * @param int               $threshold   Members a group needs to trigger (at least 1).
	 * @param array<int, mixed> $groupBy     The grouping field names.
	 * @param DateTimeImmutable $windowStart Start of the rolling window (inclusive).
	 * @param DateTimeImmutable $now         The evaluation clock, end of the window (inclusive).
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 */
	public function __construct(
		public readonly int $threshold,
		public readonly array $groupBy,
		public readonly DateTimeImmutable $windowStart,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
