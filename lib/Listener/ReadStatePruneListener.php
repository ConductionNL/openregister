<?php

/**
 * A deleted object leaves no read state behind.
 *
 * Without this, a re-created uuid inherits somebody else's idea of what they
 * have already seen, and the new object silently reads as read for a person who
 * has never laid eyes on it.
 *
 * Best-effort: a failure to prune never blocks the deletion path.
 *
 * The removal goes through ReadStateService rather than straight to the mapper,
 * so "what happens to a read state when its object goes" has ONE definition.
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
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\Support\HandlesObjectDeletion;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingServiceInterface;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Prunes read-state rows and archives orphaned notices on object purge.
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 */
final class ReadStatePruneListener implements IEventListener {
	use HandlesObjectDeletion;

	/**
	 * Constructor.
	 *
	 * @param ReadStateService $readState The read-state primitive, which owns the cleanup.
	 * @param NotificationClearingServiceInterface $clearing Archives notices whose subject is gone.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ReadStateService $readState,
		private readonly NotificationClearingServiceInterface $clearing,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Remove every read state tied to the object uuid and archive its orphaned notices.
	 *
	 * @param ObjectEntity $object The deleted object.
	 * @param string       $uuid   Its uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	private function onObjectDeleted(ObjectEntity $object, string $uuid): void {
		$this->readState->cleanupForObject(objectUuid: $uuid);

		// An unread notice about an object nobody can open any more can
		// never be cleared by doing the work, so it is archived rather than
		// left sitting unread for ever.
		$this->clearing->archiveOrphaned(objectUuid: $uuid);
	}//end onObjectDeleted()

	/**
	 * Log the skipped prune; the deletion itself stands.
	 *
	 * @param \Throwable $error The failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	private function onDeletionFailure(\Throwable $error): void {
		$this->logger->debug(
			sprintf('[ReadStatePruneListener] prune skipped: %s', $error->getMessage())
		);
	}//end onDeletionFailure()
}//end class
