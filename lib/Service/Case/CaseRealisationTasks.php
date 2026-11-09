<?php

/**
 * How a plan item is realised as a task: the task lifecycle and the task table its outcome is read from.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;

/**
 * How a plan item is realised as a task: the task lifecycle and the task table its outcome is read from.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 */
final class CaseRealisationTasks {
	/**
	 * Bundle the services.
	 *
	 * @param TaskServiceInterface      $tasks     The task lifecycle (its trusted creation path).
	 * @param TaskMapper                $taskRows  The task table, for the terminal-outcome read.
	 */
	public function __construct(
		public readonly TaskServiceInterface $tasks,
		public readonly TaskMapper $taskRows,
	) {
	}//end __construct()
}//end class
