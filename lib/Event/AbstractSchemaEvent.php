<?php

/**
 * OpenRegister AbstractSchemaEvent
 *
 * What the events about one schema entity carry.
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

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * An event about a schema: the entity it happened to.
 */
abstract class AbstractSchemaEvent extends Event {
	/**
	 * Constructor for AbstractSchemaEvent.
	 *
	 * @param Schema $schema The entity the event is about.
	 *
	 * @return void
	 */
	public function __construct(private readonly Schema $schema) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the entity the event is about.
	 *
	 * @return Schema The entity.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getSchema(): Schema {
		return $this->schema;
	}//end getSchema()
}//end class
