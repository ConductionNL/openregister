<?php

/**
 * OpenRegister ConfigurationDeletedEvent
 *
 * This file contains the event class dispatched when a configuration is deleted
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Configuration;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a configuration is deleted.
 */
class ConfigurationDeletedEvent extends Event {

	/**
	 * The deleted configuration.
	 *
	 * @var Configuration The configuration that was deleted.
	 */
	private Configuration $configuration;

	/**
	 * Constructor for ConfigurationDeletedEvent.
	 *
	 * @param Configuration $configuration The configuration that was deleted.
	 *
	 * @return void
	 */
	public function __construct(Configuration $configuration) {
		parent::__construct();
		$this->configuration = $configuration;
	}//end __construct()

	/**
	 * Get the deleted configuration.
	 *
	 * @return Configuration The configuration that was deleted.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getConfiguration(): Configuration {
		return $this->configuration;
	}//end getConfiguration()
}//end class
