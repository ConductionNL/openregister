<?php

/**
 * The OpenRegister object a Nextcloud Flow event is about.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category WorkflowEngine
 * @package  OCA\OpenRegister\WorkflowEngine
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/visual-flow-builder/specs/integration-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;

/**
 * Reads the object off an object create, update or delete event; any other event carries none.
 *
 * @spec openspec/changes/visual-flow-builder/specs/integration-flow/spec.md
 */
final class LifecycleEventObject {
	/**
	 * Resolve the OpenRegister object from a lifecycle event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return ObjectEntity|null The object, or null for any other event.
	 *
	 * @spec openspec/changes/visual-flow-builder/specs/integration-flow/spec.md
	 */
	public function of(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatedEvent
			|| $event instanceof ObjectUpdatedEvent
			|| $event instanceof ObjectDeletedEvent
		) {
			return $event->getObject();
		}

		return null;
	}//end of()
}//end class
