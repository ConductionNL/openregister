<?php

/**
 * The four task tables one lifecycle verb writes in one transaction.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\TaskAuditMapperInterface;
use OCA\OpenRegister\Db\TaskCandidateMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapperInterface;

/**
 * The task record, its candidate index, its typed relations and its
 * append-only audit: what a lifecycle verb persists, together.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskTables {
	/**
	 * Bundle the tables.
	 *
	 * @param TaskMapper $tasks The task table.
	 * @param TaskCandidateMapperInterface $candidates The candidate index rows.
	 * @param TaskRelationMapperInterface $relations The typed relation rows.
	 * @param TaskAuditMapperInterface $audits The append-only audit.
	 */
	public function __construct(
		public readonly TaskMapper $tasks,
		public readonly TaskCandidateMapperInterface $candidates,
		public readonly TaskRelationMapperInterface $relations,
		public readonly TaskAuditMapperInterface $audits,
	) {
	}//end __construct()
}//end class
