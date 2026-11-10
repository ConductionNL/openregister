<?php

/**
 * ScheduleRefusalReportInterface: the schedule releases refused during this request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Scheduling
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

namespace OCA\OpenRegister\AppHost\Scheduling;

/**
 * What the schedule refusal middleware needs of ScheduleRefusals.
 * Bound to ScheduleRefusals in AppHostRegistrar, so it resolves to the same shared instance.
 */
interface ScheduleRefusalReportInterface {

	/**
	 * The refusals recorded so far.
	 *
	 * @return array<int, string> The reasons, in order.
	 */
	public function all(): array;
}//end interface
