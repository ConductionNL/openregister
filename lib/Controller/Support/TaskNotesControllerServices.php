<?php

/**
 * OpenRegister task-notes controller services
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

use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCP\IUserSession;

/**
 * The services of TaskNotesController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskNotesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TaskManagerInterface     $tasks         Resolves a task by uuid.
	 * @param TaskAuthorizationService $authorization Read-visibility decisions.
	 * @param NoteServiceInterface     $notes         The same note storage the object leaf uses.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		public readonly TaskManagerInterface $tasks,
		public readonly TaskAuthorizationService $authorization,
		public readonly NoteServiceInterface $notes,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
