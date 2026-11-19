<?php

/**
 * OpenRegister ConfigurationUpdatedEvent
 *
 * This file contains the event class dispatched when a configuration is updated
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

use OCA\OpenRegister\Kernel\Contracts\ConfigurationInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a configuration is updated.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
 */
class ConfigurationUpdatedEvent extends Event {

	/**
	 * The updated configuration state.
	 *
	 * @var ConfigurationInterface&Entity The configuration after update.
	 */
	private ConfigurationInterface $newConfiguration;

	/**
	 * The previous configuration state.
	 *
	 * @var ConfigurationInterface&Entity The configuration before update.
	 */
	private ConfigurationInterface $oldConfiguration;

	/**
	 * Constructor for ConfigurationUpdatedEvent.
	 *
	 * @param ConfigurationInterface&Entity $newConfiguration The configuration after update.
	 * @param ConfigurationInterface&Entity $oldConfiguration The configuration before update.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(ConfigurationInterface $newConfiguration, ConfigurationInterface $oldConfiguration) {
		parent::__construct();
		$this->newConfiguration = $newConfiguration;
		$this->oldConfiguration = $oldConfiguration;
	}//end __construct()

	/**
	 * Get the updated configuration.
	 *
	 * The listener that turns this event into a webhook payload calls
	 * getConfiguration(), the same name its created and deleted siblings
	 * carry. This class had only getNewConfiguration()/getOldConfiguration(),
	 * so that call was a fatal error on every dispatch; the only test
	 * covering it doubled the event with addMethods(['getConfiguration']),
	 * which invents the method on the mock and never consults the real class.
	 *
	 * @return ConfigurationInterface&Entity The configuration after update.
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
	 */
	public function getConfiguration(): ConfigurationInterface {
		return $this->newConfiguration;
	}//end getConfiguration()

	/**
	 * Get the updated configuration.
	 *
	 * @return ConfigurationInterface&Entity The configuration after update.
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
	 */
	public function getNewConfiguration(): ConfigurationInterface {
		return $this->newConfiguration;
	}//end getNewConfiguration()

	/**
	 * Get the original configuration.
	 *
	 * @return ConfigurationInterface&Entity The configuration before update.
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
	 */
	public function getOldConfiguration(): ConfigurationInterface {
		return $this->oldConfiguration;
	}//end getOldConfiguration()
}//end class
