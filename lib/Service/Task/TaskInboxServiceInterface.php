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
	 * @param TaskInboxCriteria  $criteria Scope, filters, sort and identity.
	 * @param TaskInboxPage|null $page     The page window; the first 25 when null.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 */
	public function inbox(TaskInboxCriteria $criteria, ?TaskInboxPage $page = null): array;

	/**
	 * One task as an API row, with its subject context resolved.
	 *
	 * @param TaskInterface $task The task.
	 * @param string|null   $caller The reader's uid; when given the row carries `can` (record-tasks-tab D-4).
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/record-tasks-tab/specs/flow-tasks/spec.md
	 */
	public function enrich(TaskInterface $task, ?string $caller = null): array;
}//end interface
