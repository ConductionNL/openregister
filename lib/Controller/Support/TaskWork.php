<?php

/**
 * OpenRegister task domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskInboxServiceInterface;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;

/**
 * The domain services of the task API.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskWork {
	/**
	 * Bundle the services.
	 *
	 * @param TaskManagerInterface     $tasks         The authorized lifecycle.
	 * @param TaskInboxServiceInterface         $inbox         The inbox queries.
	 * @param TaskAuthorizationService $authorization Read-visibility decisions.
	 * @param TaskTemporalProjection   $temporal      The one overdue derivation.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function __construct(
		public readonly TaskManagerInterface $tasks,
		public readonly TaskInboxServiceInterface $inbox,
		public readonly TaskAuthorizationService $authorization,
		public readonly TaskTemporalProjection $temporal,
	) {
	}//end __construct()
}//end class
