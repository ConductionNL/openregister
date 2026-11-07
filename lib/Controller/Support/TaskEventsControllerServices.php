<?php

/**
 * OpenRegister task events controller services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Service\CalendarLinkServiceInterface;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;

/**
 * The services of the task events API.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskEventsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TaskManagerInterface          $tasks         Resolves a task by uuid.
	 * @param TaskAuthorizationService      $authorization Read-visibility decisions.
	 * @param CalendarLinkServiceInterface  $links         The link-table layer, uuid-anchored.
	 * @param ObjectCalendarEventsInterface $events        The VEVENT layer, for the legacy
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function __construct(
		public readonly TaskManagerInterface $tasks,
		public readonly TaskAuthorizationService $authorization,
		public readonly CalendarLinkServiceInterface $links,
		public readonly ObjectCalendarEventsInterface $events,
	) {
	}//end __construct()
}//end class
