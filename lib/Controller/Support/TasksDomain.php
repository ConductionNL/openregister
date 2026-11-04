<?php

/**
 * OpenRegister tasks domain
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

use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;

/**
 * The domain services of the tasks API.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class TasksDomain {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectCalendarTasksInterface $taskService   Task service for VTODO operations
	 * @param ObjectService                $objectService Object service for object validation
	 * @param ?TaskInboxService            $inbox         The engine inbox the aggregate answers from;
	 * @param ?TaskTemporalProjection      $temporal      The one overdue derivation's clock
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly ObjectCalendarTasksInterface $taskService,
		public readonly ObjectService $objectService,
		public readonly ?TaskInboxService $inbox = null,
		public readonly ?TaskTemporalProjection $temporal = null,
	) {
	}//end __construct()
}//end class
