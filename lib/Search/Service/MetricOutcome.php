<?php

/**
 * OpenRegister metric outcome
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

/**
 * How the measured operation ended: its status, duration and error.
 *
 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy
 */
final class MetricOutcome {
	/**
	 * The outcome.
	 *
	 * @param string      $status       Status (success, failure)
	 * @param int|null    $durationMs   Duration in milliseconds
	 * @param string|null $errorMessage Error message (if failed)
	 *
	 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy
	 */
	public function __construct(
		public readonly string $status = 'success',
		public readonly ?int $durationMs = null,
		public readonly ?string $errorMessage = null,
	) {
	}//end __construct()
}//end class
