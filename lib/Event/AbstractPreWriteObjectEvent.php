<?php

/**
 * OpenRegister AbstractPreWriteObjectEvent
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * An event dispatched before one object is written, which a listener can veto.
 */
abstract class AbstractPreWriteObjectEvent extends Event implements StoppableEventInterface {
	use WriteVetoState;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object about to be written.
	 *
	 * @return void
	 */
	public function __construct(private readonly ObjectEntity $object) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the object about to be written.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
