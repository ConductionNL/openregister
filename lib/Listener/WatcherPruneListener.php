<?php

/**
 * Watcher pruning listener.
 *
 * Removes every subscription on an object when the object is purged. Without
 * it a deleted object would leave its audience behind, and a re-created object
 * that reused the uuid would silently inherit somebody else's followers — who
 * would then be notified about a case they never chose to follow.
 *
 * Best-effort: a failure to prune never blocks the deletion path.
 *
 * The removal goes through WatcherService rather than straight to the mapper,
 * so "what happens to a subscription when its object goes" has ONE definition
 * and the listener cannot drift from it.
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
 * @spec openspec/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\Support\HandlesObjectDeletion;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Prunes watcher rows on object purge.
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 */
final class WatcherPruneListener implements IEventListener {
	use HandlesObjectDeletion;

	/**
	 * Constructor.
	 *
	 * @param WatcherServiceInterface $watchers The subscription primitive, which owns the cleanup.
	 * @param LoggerInterface         $logger   PSR logger.
	 */
	public function __construct(
		private readonly WatcherServiceInterface $watchers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Delete every watcher row tied to the object uuid.
	 *
	 * @param ObjectEntity $object The deleted object.
	 * @param string       $uuid   Its uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
	 */
	private function onObjectDeleted(ObjectEntity $object, string $uuid): void {
		$this->watchers->cleanupForObject(objectUuid: $uuid);
	}//end onObjectDeleted()

	/**
	 * Log the skipped prune; the deletion itself stands.
	 *
	 * @param \Throwable $error The failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
	 */
	private function onDeletionFailure(\Throwable $error): void {
		$this->logger->debug(
			sprintf('[WatcherPruneListener] prune skipped: %s', $error->getMessage())
		);
	}//end onDeletionFailure()
}//end class
