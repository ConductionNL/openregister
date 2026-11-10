<?php

/**
 * DpiaPatternDetectorInterface: what callers outside the Service component ask of DpiaPatternDetectionService.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

/**
 * Detecting the case groups that require a DPIA flag, as the detection job does. Only the method that caller calls.
 */
interface DpiaPatternDetectorInterface {

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
	 * @param DpiaDetectionRequest $request The case payloads, the pack's `dpiaDetection` block
	 *                                      (threshold, windowDays, groupBy) and the evaluation clock.
	 *
	 * @return array<int, array{key: string, count: int, caseUuids: array<int, string>, unflaggedUuids: array<int, string>}> Triggering groups.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 *   (Scenario: Threshold crossing flags the group)
	 */
	public function detect(DpiaDetectionRequest $request): array;
}//end interface
