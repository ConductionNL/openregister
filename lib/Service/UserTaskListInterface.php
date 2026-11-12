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
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Lists the VTODO tasks of every calendar of the logged-in user.
 */
interface UserTaskListInterface {

	/**
	 * One page of the current user's tasks across their calendars, sorted by due date.
	 *
	 * @param string|null $status Optional status filter (e.g. 'needs-action', 'completed')
	 * @param ListQuery   $query  The page: limit and offset
	 *
	 * @return array{results: array, total: int} Task results with total count
	 *
	 * @throws Exception If no user is logged in
	 */
	public function listUserTasks(?string $status, ListQuery $query): array;
}//end interface
