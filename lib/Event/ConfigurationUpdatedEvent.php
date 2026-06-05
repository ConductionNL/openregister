<?php

/**
 * OpenRegister ConfigurationUpdatedEvent
 *
 * This file contains the event class dispatched when a configuration is updated
 * in the OpenRegister application.
 *
<<<<<<< HEAD
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
=======
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
>>>>>>> origin/development
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
 * Event dispatched when a configuration is updated.
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
 */
class ConfigurationUpdatedEvent extends Event
{

    /**
     * The updated configuration state.
     *
     * @var Configuration The configuration after update.
     */
    private Configuration $newConfiguration;

    /**
     * The previous configuration state.
     *
     * @var Configuration The configuration before update.
     */
    private Configuration $oldConfiguration;

    /**
     * Constructor for ConfigurationUpdatedEvent.
     *
     * @param Configuration $newConfiguration The configuration after update.
     * @param Configuration $oldConfiguration The configuration before update.
     *
     * @return void
     * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-4
     */
    public function __construct(Configuration $newConfiguration, Configuration $oldConfiguration)
    {
        parent::__construct();
        $this->newConfiguration = $newConfiguration;
        $this->oldConfiguration = $oldConfiguration;
    }//end __construct()

    /**
<<<<<<< HEAD
     * Get the configuration after update.
     *
     * @return Configuration The configuration after update.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.2
=======
     * Get the updated configuration.
     *
     * @return Configuration The configuration after update.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
>>>>>>> origin/development
     */
    public function getNewConfiguration(): Configuration
    {
        return $this->newConfiguration;
    }//end getNewConfiguration()

    /**
<<<<<<< HEAD
     * Get the configuration before update.
     *
     * @return Configuration The configuration before update.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.2
=======
     * Get the original configuration.
     *
     * @return Configuration The configuration before update.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
>>>>>>> origin/development
     */
    public function getOldConfiguration(): Configuration
    {
        return $this->oldConfiguration;
    }//end getOldConfiguration()
}//end class
