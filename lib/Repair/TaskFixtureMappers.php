<?php

/**
 * The task mappers the task fixture seeder writes through
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
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

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;

/**
 * The three task tables one seeded fixture lands in.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */
final class TaskFixtureMappers {
	/**
	 * Bundle the mappers.
	 *
	 * @param TaskMapper          $tasks      The task rows.
	 * @param TaskCandidateMapper $candidates The candidate index rows.
	 * @param TaskAuditMapper     $audits     The audit rows.
	 */
	public function __construct(
		public readonly TaskMapper $tasks,
		public readonly TaskCandidateMapper $candidates,
		public readonly TaskAuditMapper $audits,
	) {
	}//end __construct()
}//end class
