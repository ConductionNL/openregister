<?php

/**
 * FavouritePruneListener: the cascade a foreign key cannot express.
 *
 * Objects live in per-schema tables, so there is no single table for a foreign
 * key on `object_uuid` to point at and the cascade is a listener instead. A
 * star on an object nobody can open is a row that will never be looked at
 * again.
 *
 * Views are no longer pruned here. "Recently opened" is read from the audit
 * trail (`read-history-on-audit-trail`), whose rows are a legal record with
 * their own retention and must not be deleted with the object. A purged
 * object simply no longer matches the object query, so it drops out of the
 * `_recent` lens without any cleanup.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\Interaction\FavouriteService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Removes every star on an object on purge.
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 */
final class FavouritePruneListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FavouriteService $favourites The favourite primitive, which owns its cleanup.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly FavouriteService $favourites,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event by clearing the object's stars.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectDeletedEvent) === false) {
			return;
		}

		try {
			$uuid = (string)$event->getObject()->getUuid();
			if ($uuid === '') {
				return;
			}

			$this->favourites->cleanupForObject(objectUuid: $uuid);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[FavouritePruneListener] prune skipped: %s', $e->getMessage())
			);
		}//end try

	}//end handle()
}//end class
