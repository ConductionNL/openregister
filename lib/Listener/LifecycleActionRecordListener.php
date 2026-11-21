<?php

/**
 * OpenRegister LifecycleActionRecordListener
 *
 * Writes the held lifecycle action records of an object once its save has
 * completed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRecorder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * On ObjectUpdatedEvent the transition's save has resolved, so the action
 * records held for that object are written as they stand (D-3).
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
 */
class LifecycleActionRecordListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LifecycleActionRecorder $recorder Holds and writes the records.
	 *
	 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LifecycleActionRecorder $recorder,
	) {
	}//end __construct()

	/**
	 * Write the records held for the saved object.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$uuid = (string)($event->getNewObject()->getUuid() ?? '');
		if ($uuid !== '') {
			$this->recorder->flush(uuid: $uuid);
		}
	}//end handle()
}//end class
