<?php

/**
 * OpenRegister AppHost ScheduleCadence
 *
 * When a schedule fires: every so many seconds, or on a cron expression.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\AppHost\Scheduling
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

namespace OCA\OpenRegister\AppHost\Scheduling;

/**
 * When a schedule fires: every so many seconds, or on a cron expression.
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */
final class ScheduleCadence {
	/**
	 * Constructor.
	 *
	 * @param int|null    $intervalSeconds Positive interval in seconds, or null for cron schedules.
	 * @param string|null $cron            Cron expression, or null for interval schedules.
	 */
	public function __construct(
		public readonly ?int $intervalSeconds,
		public readonly ?string $cron,
	) {
	}//end __construct()
}//end class
