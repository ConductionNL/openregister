<?php

/**
 * OpenRegister portal task sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
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

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;

/**
 * Where a portal reads and completes the tasks of its subject.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
 */
final class PortalTaskSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param TaskServiceInterface   $tasks    The authorized lifecycle.
	 * @param TaskMapper             $mapper   The subject-scoped finders.
	 * @param TaskInboxService       $inbox    Row shaping and case context.
	 * @param TaskTemporalProjection $temporal The clock for the row projection.
	 */
	public function __construct(
		public readonly TaskServiceInterface $tasks,
		public readonly TaskMapper $mapper,
		public readonly TaskInboxService $inbox,
		public readonly TaskTemporalProjection $temporal,
	) {
	}//end __construct()
}//end class
