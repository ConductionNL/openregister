<?php

/**
 * FlowScheduleServiceInterface: what the schedule worker asks of FlowScheduleService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use DateTimeInterface;

/**
 * The scheduled-flow trigger, as the background schedule worker uses it. Only the method that caller calls.
 */
interface FlowScheduleServiceInterface {

	/**
	 * Queue a run for every scheduled flow that is due at $now.
	 *
	 * @param DateTimeInterface $now The moment to evaluate against.
	 *
	 * @return array<int, string> The uuids of the flows that fired.
	 */
	public function fireDueFlows(DateTimeInterface $now): array;
}//end interface
