<?php

/**
 * OpenRegister ActivityEventListener.
 *
 * Listens to OpenRegister entity events and publishes corresponding activity events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Notifications\Service\ActivityService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Event listener that bridges OpenRegister entity events to Nextcloud Activity.
 *
 * @implements IEventListener<Event>
 */
class ActivityEventListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ActivityService $activityService The activity publishing service.
	 */
	public function __construct(
		private ActivityService $activityService,
	) {
	}//end __construct()

	/**
	 * The single-entity events, in the order they are checked, each with the
	 * getter for its entity and the ActivityService method that publishes it.
	 *
	 * @var array<class-string<Event>, array{0: string, 1: string}>
	 */
	private const PUBLISHERS = [
		ObjectCreatedEvent::class => ['getObject', 'publishObjectCreated'],
		ObjectDeletedEvent::class => ['getObject', 'publishObjectDeleted'],
		RegisterCreatedEvent::class => ['getRegister', 'publishRegisterCreated'],
		RegisterUpdatedEvent::class => ['getNewRegister', 'publishRegisterUpdated'],
		RegisterDeletedEvent::class => ['getRegister', 'publishRegisterDeleted'],
		SchemaCreatedEvent::class => ['getSchema', 'publishSchemaCreated'],
		SchemaUpdatedEvent::class => ['getNewSchema', 'publishSchemaUpdated'],
		SchemaDeletedEvent::class => ['getSchema', 'publishSchemaDeleted'],
	];

	/**
	 * Handle an incoming event and delegate to the appropriate ActivityService method.
	 *
	 * An object update is the one event that publishes two entities, the new
	 * and the old object; every other event publishes the one entity it carries.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent) {
			$this->activityService->publishObjectUpdated(
				$event->getNewObject(),
				$event->getOldObject()
			);
			return;
		}

		foreach (self::PUBLISHERS as $eventClass => [$getter, $publish]) {
			if ($event instanceof $eventClass) {
				$this->activityService->{$publish}($event->{$getter}());
				return;
			}
		}
	}//end handle()
}//end class
