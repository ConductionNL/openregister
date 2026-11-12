<?php

/**
 * TaskAuditMapperInterface: what callers outside the Db component use of TaskAuditMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The methods of TaskAuditMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to TaskAuditMapper. Guarded by F05InterfaceBindingsTest.
 */
interface TaskAuditMapperInterface {

	/**
	 * Append an entry, stamping `created`.
	 *
	 * @param Entity $entity The entry to append.
	 *
	 * @return TaskAudit The appended entry.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function insert(Entity $entity): TaskAudit;

	/**
	 * The audit trail of one task, oldest first.
	 *
	 * @param int $taskId The task's row id.
	 *
	 * @return array<int, TaskAudit> The entries.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function findForTask(int $taskId): array;
}//end interface
