<?php

/**
 * TaskBlockerMapperInterface: which tasks wait on which, as callers outside the Db component ask it.
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

/**
 * The task-blocker reads: which blockers are still open, and which open
 * tasks wait on one.
 *
 * Callers type against this interface and resolve it from the container; the
 * Db registrar binds it to TaskBlockerMapper. Guarded by M18InterfaceBindingsTest.
 *
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */
interface TaskBlockerMapperInterface {

	/**
	 * Which of these blocker uuids name a task that is still open.
	 *
	 * @param array<int, string> $uuids The blocker uuids of a page.
	 *
	 * @return array<int, string> The uuids among them whose task is not terminal.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function openBlockerUuids(array $uuids): array;

	/**
	 * The open tasks that wait on this one.
	 *
	 * @param string $blockerUuid The blocker's uuid.
	 *
	 * @return array<int, Task> The open dependants.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function findOpenBlockedBy(string $blockerUuid): array;
}//end interface
