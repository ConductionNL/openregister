<?php
/**
 * OpenRegister RegisterUpdatedEvent
 *
 * This file contains the event class dispatched when a register is updated
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

use OCA\OpenRegister\Db\Register;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a register is updated
 */
class RegisterUpdatedEvent extends Event
{

    /**
     * The updated register state
     *
     * @var Register The register after update
     */
    private Register $newRegister;

    /**
     * The previous register state
     *
     * @var Register The register before update
     */
    private Register $oldRegister;


    /**
     * Constructor for RegisterUpdatedEvent
     *
     * @param Register $newRegister The register after update
     * @param Register $oldRegister The register before update
     *
     * @return void
     */
    public function __construct(Register $newRegister, Register $oldRegister)
    {
        parent::__construct();
        $this->newRegister = $newRegister;
        $this->oldRegister = $oldRegister;

    }//end __construct()


    /**
     * Get the updated register
     *
     * @return Register The register after update
     */
    public function getNewRegister(): Register
    {
        return $this->newRegister;

    }//end getNewRegister()


    /**
     * Get the original register
     *
     * @return Register The register before update
     */
    public function getOldRegister(): Register
    {
        return $this->oldRegister;

    }//end getOldRegister()


}//end class
