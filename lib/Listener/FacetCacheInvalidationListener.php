<?php

/**
 * OpenRegister FacetCacheInvalidationListener
 *
 * Subscribes to ObjectCreatedEvent / ObjectUpdatedEvent / ObjectDeletedEvent /
 * ObjectTransitionedEvent and bumps the FacetCacheVersion counter for the
 * written object's scope, so the next facet read for that register and schema
 * computes against fresh rows instead of serving an entry from the hour-long
 * facet response cache (openregister#3560).
 *
 * The counter is bumped, never the cache cleared: the cached responses of every
 * other scope keep hitting.
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
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Object\FacetCacheVersion;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that makes an object write visible to the next facet read.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 */
class FacetCacheInvalidationListener implements IEventListener {
	/**
	 * Wire the counter bumped on every object write.
	 *
	 * @param FacetCacheVersion $versions Per-scope facet freshness counter.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	public function __construct(
		private readonly FacetCacheVersion $versions,
	) {
	}//end __construct()

	/**
	 * Bump the facet freshness counter for the written object's scope.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	public function handle(Event $event): void {
		$object = $this->extractObject(event: $event);
		if ($object === null) {
			return;
		}

		$this->versions->bump(
			register: $object->getRegister(),
			schema: $object->getSchema()
		);
	}//end handle()

	/**
	 * Resolve the underlying object for any of the supported event types.
	 *
	 * A delete event carries the object under getObject(); an update event
	 * carries the post-write state under getNewObject(). Both are read, because
	 * a facet is derived from whichever rows now exist.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return ObjectEntity|null Object instance, or null when not resolvable.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	private function extractObject(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectTransitionedEvent) {
			return $event->getObject();
		}

		if (method_exists($event, 'getObject') === true) {
			$obj = $event->getObject();
			if ($obj instanceof ObjectEntity) {
				return $obj;
			}
		}

		if (method_exists($event, 'getNewObject') === true) {
			$obj = $event->getNewObject();
			if ($obj instanceof ObjectEntity) {
				return $obj;
			}
		}

		return null;
	}//end extractObject()
}//end class
