<?php

/**
 * OpenRegister AbstractSourceEvent
 *
 * What the events about one source entity carry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Kernel\Contracts\SourceInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;

/**
 * An event about a source: the entity it happened to.
 */
abstract class AbstractSourceEvent extends Event {
	/**
	 * Constructor for AbstractSourceEvent.
	 *
	 * @param SourceInterface&Entity $source The entity the event is about.
	 *
	 * @return void
	 */
	public function __construct(private readonly SourceInterface $source) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the entity the event is about.
	 *
	 * @return SourceInterface&Entity The entity.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getSource(): SourceInterface {
		return $this->source;
	}//end getSource()
}//end class
