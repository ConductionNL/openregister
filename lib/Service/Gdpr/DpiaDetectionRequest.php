<?php

/**
 * One DPIA pattern detection: the cases, the pack's detection block and the clock.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use DateTimeImmutable;

/**
 * The cases to group, the configuration that says how, and the instant the
 * rolling window ends.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */
final class DpiaDetectionRequest {
	/**
	 * Describe the detection.
	 *
	 * @param array<int, mixed>    $cases  Case payloads (each element is validated as an array at
	 *                                     runtime); each needs `receivedAt` + the group-by fields +
	 *                                     optionally `dpiaRequired` and a caller-supplied `@uuid`.
	 * @param array<string, mixed> $config The pack's `dpiaDetection` block (threshold, windowDays, groupBy).
	 * @param DateTimeImmutable    $now    The evaluation clock.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 */
	public function __construct(
		public readonly array $cases,
		public readonly array $config,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
