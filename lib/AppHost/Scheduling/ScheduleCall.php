<?php

/**
 * OpenRegister AppHost ScheduleCall
 *
 * What a schedule runs: an allow-listed action and its arguments.
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
 * What a schedule runs: an allow-listed action and its arguments.
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */
final class ScheduleCall {
	/**
	 * Constructor.
	 *
	 * @param string               $action    Allow-listed action type (e.g. `openconnector:synchronization`).
	 * @param array<string, mixed> $arguments Arguments passed to the resolved action.
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $arguments = [],
	) {
	}//end __construct()
}//end class
