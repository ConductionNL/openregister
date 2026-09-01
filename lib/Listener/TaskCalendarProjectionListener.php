<?php

/**
 * Renders the calendar projection after a task transition committed.
 *
 * Thin by design: the failure isolation lives in TaskProjectionService, so
 * the listener's only job is to hand the committed event over.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Task transitions become calendar entries.
 *
 * @template-implements IEventListener<TaskTransitionedEvent>
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */
class TaskCalendarProjectionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param TaskProjectionService $projections The failure-isolated fan-out.
	 */
	public function __construct(
		private readonly TaskProjectionService $projections,
	) {

	}//end __construct()

	/**
	 * Handle a committed transition.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
	 */
	public function handle(Event $event): void {
		if (($event instanceof TaskTransitionedEvent) === false) {
			return;
		}

		$this->projections->afterTransition(event: $event);
	}//end handle()
}//end class
