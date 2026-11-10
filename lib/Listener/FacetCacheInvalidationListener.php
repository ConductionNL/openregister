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

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Object\FacetCacheVersionInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that makes an object write visible to the next facet read.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 */
class FacetCacheInvalidationListener implements IEventListener {
	use WrittenObjectOfEvent;

	/**
	 * Wire the counter bumped on every object write.
	 *
	 * @param FacetCacheVersionInterface $versions Per-scope facet freshness counter.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	public function __construct(
		private readonly FacetCacheVersionInterface $versions,
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
		if ($object !== null) {
			$this->versions->bump(register: $object->getRegister(), schema: $object->getSchema());
		}
	}//end handle()
}//end class
