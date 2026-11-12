<?php

/**
 * OpenRegister HandlesObjectDeletion
 *
 * The shared shape of a best-effort ObjectDeletedEvent listener: ignore every
 * other event, skip an object without a uuid, and never let a failure travel
 * back into the deletion.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use Throwable;

/**
 * Dispatches a deleted object with a uuid to the using listener's own work.
 */
trait HandlesObjectDeletion {
	/**
	 * Hand a deleted object with a uuid to onObjectDeleted(); swallow any failure.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectDeletedEvent) === false) {
			return;
		}

		try {
			$object = $event->getObject();
			$uuid = (string)($object->getUuid() ?? '');
			if ($uuid !== '') {
				$this->onObjectDeleted(object: $object, uuid: $uuid);
			}
		} catch (Throwable $e) {
			$this->onDeletionFailure(error: $e);
		}
	}//end handle()

	/**
	 * The listener's work for one deleted object.
	 *
	 * @param ObjectEntity $object The deleted object.
	 * @param string       $uuid   Its uuid, never empty.
	 *
	 * @return void
	 */
	abstract private function onObjectDeleted(ObjectEntity $object, string $uuid): void;

	/**
	 * Record a failure of that work; it never reaches the deletion.
	 *
	 * @param Throwable $error The failure.
	 *
	 * @return void
	 */
	abstract private function onDeletionFailure(Throwable $error): void;
}//end trait
