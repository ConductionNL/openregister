<?php

/**
 * TaskCandidateMapperInterface: what callers outside the Db component use of TaskCandidateMapper.
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
 * The methods of TaskCandidateMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to TaskCandidateMapper. Guarded by F05InterfaceBindingsTest.
 */
interface TaskCandidateMapperInterface {

	/**
	 * Replace one task's candidate rows with a fresh set.
	 *
	 * The CALLER holds the transaction that also writes the task's JSON record.
	 *
	 * @param int                                            $taskId     The task's row id.
	 * @param array<int, array{kind: string, ref: string}> $candidates The new rows.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function replaceForTask(int $taskId, array $candidates): void;
}//end interface
