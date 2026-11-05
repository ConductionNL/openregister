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
		foreach (['getObject', 'getNewObject'] as $getter) {
			if (method_exists($event, $getter) === true && $event->$getter() instanceof ObjectEntity) {
				return $event->$getter();
			}
		}

		return null;
	}//end extractObject()
}//end trait
