<?php

/**
 * OpenRegister AbstractTaskEvent
 *
 * What the events about one task carry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCP\EventDispatcher\Event;

/**
 * An event about a task: the task as persisted.
 *
 * The base carries the Kernel task contract, so it names no area above
 * Kernel. Each concrete event keeps its own `getTask(): Task`, so what its
 * listeners read does not change.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
 */
abstract class AbstractTaskEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param TaskInterface $task The task as persisted.
	 */
	public function __construct(private readonly TaskInterface $task) {
		parent::__construct();
	}//end __construct()

	/**
	 * The task the event is about.
	 *
	 * @return TaskInterface The task as persisted.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
	 */
	public function getTask(): TaskInterface {
		return $this->task;
	}//end getTask()
}//end class
