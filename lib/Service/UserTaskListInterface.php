<?php

/**
 * UserTaskListInterface: the listing of the acting user's own CalDAV tasks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Exception;

/**
 * Lists the VTODO tasks of every calendar of the logged-in user.
 */
interface UserTaskListInterface {

	/**
	 * Get all tasks of the current user across their calendars, sorted by due date.
	 *
	 * @param string|null $status Optional status filter (e.g. 'needs-action', 'completed')
	 * @param int         $limit  Maximum number of tasks to return
	 * @param int         $offset Number of tasks to skip
	 *
	 * @return array{results: array, total: int} Task results with total count
	 *
	 * @throws Exception If no user is logged in
	 */
	public function getAllUserTasks(?string $status=null, int $limit=50, int $offset=0): array;
}//end interface
