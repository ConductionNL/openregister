<?php

/**
 * TaskInboxServiceInterface: the task inbox page and the single-task row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
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

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;

/**
 * What the task controllers, the task notification listener and job need of TaskInboxService.
 * Bound to TaskInboxService in ContractBindings, so it resolves to the same shared instance.
 */
interface TaskInboxServiceInterface {

	/**
	 * One inbox page: rows with subject context and temporal projection,
	 * plus the total the same predicates count.
	 *
	 * @param TaskInboxCriteria $criteria Scope, filters, sort and identity.
	 * @param int               $limit    Page size (clamped to 1..500).
	 * @param int               $offset   Page offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 */
	public function inbox(TaskInboxCriteria $criteria, int $limit = 25, int $offset = 0): array;

	/**
	 * One task as an API row, with its subject context resolved.
	 *
	 * @param TaskInterface $task The task.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function enrich(TaskInterface $task): array;
}//end interface
