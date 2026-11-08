<?php

/**
 * OpenRegister approval task stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair\Support
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

namespace OCA\OpenRegister\Repair\Support;

use OCA\OpenRegister\Db\TaskAuditMapperInterface;
use OCA\OpenRegister\Db\TaskCandidateMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequenceMapperInterface;

/**
 * The task, candidate, audit and sequence stores an approval chain is migrated into.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */
final readonly class ApprovalTaskStores {
	/**
	 * Bundle the services.
	 *
	 * @param TaskMapper                   $tasks      Task store.
	 * @param TaskCandidateMapperInterface $candidates Candidate store.
	 * @param TaskAuditMapperInterface     $audits     Task audit store.
	 * @param TaskSequenceMapperInterface  $sequences  Sequence store.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function __construct(
		public TaskMapper $tasks,
		public TaskCandidateMapperInterface $candidates,
		public TaskAuditMapperInterface $audits,
		public TaskSequenceMapperInterface $sequences,
	) {
	}//end __construct()
}//end class
