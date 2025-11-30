<?php
/**
 * OpenRegister ConfigurationUpdatedEvent
 *
 * This file contains the event class dispatched when a configuration is updated
 * in the OpenRegister application.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
     */
    public function __construct(Configuration $newConfiguration, Configuration $oldConfiguration)
    {
        parent::__construct();
        $this->newConfiguration = $newConfiguration;
        $this->oldConfiguration = $oldConfiguration;

    }//end __construct()




}//end class
