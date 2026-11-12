<?php

/**
 * OpenRegister WrittenObjectOfEvent
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
use OCP\EventDispatcher\Event;

/**
 * Finds the object an object event is about, whatever kind of write it reports.
 */
trait WrittenObjectOfEvent {
	/**
	 * Resolve the underlying object for any of the supported event types.
	 *
	 * Created, deleted and transitioned events carry the object under
	 * getObject(); an update event carries the post-write state under
	 * getNewObject(). Anything that is not an ObjectEntity counts as none.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return ObjectEntity|null Object instance, or null when not resolvable.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	private function extractObject(Event $event): ?ObjectEntity {
		$object = $this->objectOf(event: $event);
		if ($object instanceof ObjectEntity === false) {
			$object = $this->newObjectOf(event: $event);
		}

		if ($object instanceof ObjectEntity) {
			return $object;
		}

		return null;
	}//end extractObject()

	/**
	 * What the event's getObject() answers, or null when it has none.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return mixed
	 */
	private function objectOf(Event $event): mixed {
		if (method_exists($event, 'getObject') === true) {
			return $event->getObject();
		}

		return null;
	}//end objectOf()

	/**
	 * What the event's getNewObject() answers, or null when it has none.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return mixed
	 */
	private function newObjectOf(Event $event): mixed {
		if (method_exists($event, 'getNewObject') === true) {
			return $event->getNewObject();
		}

		return null;
	}//end newObjectOf()
}//end trait
