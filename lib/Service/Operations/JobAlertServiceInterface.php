<?php

/**
 * Administers a job's failure-alert threshold, as the operations console changes it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * Administers a job's failure-alert threshold, as the operations console changes it.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see JobAlertService} in ContractBindings.
 */
interface JobAlertServiceInterface {

	/**
	 * Administer the threshold and the period.
	 *
	 * @param int|null $threshold     How many failures breach it.
	 * @param int|null $periodMinutes Over how many minutes.
	 *
	 * @return array{threshold: int, periodMinutes: int} The settings now in force.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function administer(?int $threshold, ?int $periodMinutes): array;
}//end interface
