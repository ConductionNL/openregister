<?php

/**
 * AvgComplianceServiceInterface: what callers outside the component use of AvgComplianceService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The AVG compliance checks the DSAR endpoints run.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AvgComplianceServiceInterface {

	/**
	 * Run every check in sequence and return the aggregate envelope.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#automated-pii-detection (aggregates every compliance check
	 *       into a single dashboard envelope with generated timestamp, per-check issues, and totals)
	 */
	public function runAllChecks(): array;
}//end interface
