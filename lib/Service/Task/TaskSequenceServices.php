<?php

/**
 * The stores and collaborators the task sequence service works through.
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
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The dependency bundle of {@see TaskSequenceService}.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
final class TaskSequenceServices {
	/**
	 * Bundle the services.
	 *
	 * @param TaskSequenceMapper $sequences The sequence rows.
	 * @param TaskMapper $taskReader Reads a sequence's positions. Read-only
	 *                               there; every write goes through the verbs.
	 * @param TaskServiceInterface $tasks The authorized task lifecycle.
	 * @param IEventDispatcher $dispatcher Announces sequence completion.
	 */
	public function __construct(
		public readonly TaskSequenceMapper $sequences,
		public readonly TaskMapper $taskReader,
		public readonly TaskServiceInterface $tasks,
		public readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()
}//end class
