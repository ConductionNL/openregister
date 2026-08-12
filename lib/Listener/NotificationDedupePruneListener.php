<?php

/**
 * Notification-dedupe pruning listener.
 *
 * Removes per-object dedup state when the underlying object is purged from
 * the register, so a re-created object with the same UUID would re-arm any
 * matching scheduled-notification rule from a clean slate. Best-effort: a
 * failure to prune never blocks the deletion path.
 *
 * Part of notification-engine-scheduled-conditions Phase 3.4.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Prunes dedup-state rows on object purge.
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 */
final class NotificationDedupePruneListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param NotificationDedupeStateMapper $mapper Per-object dedup state mapper.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly NotificationDedupeStateMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event by deleting every dedup row tied to the object UUID.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
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

			$this->mapper->deleteByObject(objectUuid: $uuid);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[NotificationDedupePruneListener] prune skipped: %s', $e->getMessage())
			);
		}
	}//end handle()
}//end class
