<?php

/**
 * TemporalCalculationSweepServiceInterface: what callers outside the component use of TemporalCalculationSweepService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
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

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Sweep the time-dependent calculations whose value may have moved.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface TemporalCalculationSweepServiceInterface {

	/**
	 * Run one sweep over every schema with `now`-dependent materialised
	 * calculations.
	 *
	 * @return array{schemasScanned: int, temporalSchemas: int, objectsEvaluated: int, objectsRewritten: int, errors: int} Sweep summary.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Untouched case crosses the reminder tier)
	 */
	public function runSweep(): array;
}//end interface
