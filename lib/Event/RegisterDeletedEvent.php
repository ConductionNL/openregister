<?php
/**
 * OpenRegister RegisterDeletedEvent
 *
 * This file contains the event class dispatched when a register is deleted
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
 * Event dispatched when a register is deleted
 */
class RegisterDeletedEvent extends Event
{

    /**
     * The deleted register
     *
     * @var Register The register that was deleted
     */
    private Register $register;


    /**
     * Constructor for RegisterDeletedEvent
     *
     * @param Register $register The register that was deleted
     *
     * @return void
     */
    public function __construct(Register $register)
    {
        parent::__construct();
        $this->register = $register;

    }//end __construct()


    /**
     * Get the deleted register
     *
     * @return Register The register that was deleted
     */
    public function getRegister(): Register
    {
        return $this->register;

    }//end getRegister()


}//end class
