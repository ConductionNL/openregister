<?php

/**
 * OpenRegister ValidatesPreWriteObject
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;

/**
 * Hands the object a create or an update is about to write to the listener's own validate().
 *
 * The event type stays a union rather than Event, because setErrors() is
 * declared on each write event separately instead of on a shared parent.
 */
trait ValidatesPreWriteObject {
	/**
	 * Validate the object of a create, or the new object of an update; ignore any other event.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->validate(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->validate(event: $event, object: $event->getNewObject());
		}

	}//end handle()

	/**
	 * The object's data, or the empty list when it holds none.
	 *
	 * @param ObjectEntity $object The object being written.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function writtenData(ObjectEntity $object): array {
		$data = $object->getObject();
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end writtenData()

	/**
	 * Validate one write, and refuse it on the event when it may not go ahead.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The write event.
	 * @param ObjectEntity                            $object The object the write carries.
	 *
	 * @return void
	 */
	abstract protected function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void;
}//end trait
