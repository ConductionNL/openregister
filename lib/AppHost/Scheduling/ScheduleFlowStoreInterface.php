<?php

/**
 * ScheduleFlowStoreInterface: what callers outside the AppHost component use of ScheduleFlowStore.
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
 * Looks up the flows compiled from application schedules.
 */
interface ScheduleFlowStoreInterface {

	/**
	 * Whether any compiled flow carries the name of an application with this slug.
	 *
	 * For reporting only: it says nothing about which organisation published.
	 *
	 * @param string $slug The application slug.
	 *
	 * @return bool True when one does.
	 */
	public function hasFlowsNamedFor(string $slug): bool;
}//end interface
