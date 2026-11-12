<?php

/**
 * OpenRegister task controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * The service bundles of the task API.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TaskWork       $work       The work bundle.
	 * @param TaskCompletion $completion Completes a task with a form payload,
	 * @param TaskPlatform   $platform   The platform bundle.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function __construct(
		public readonly TaskWork $work,
		public readonly TaskCompletion $completion,
		public readonly TaskPlatform $platform,
	) {
	}//end __construct()
}//end class
