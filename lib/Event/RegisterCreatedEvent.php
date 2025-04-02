<?php
/**
 * OpenRegister RegisterCreatedEvent
 *
 * This file contains the event class dispatched when a register is created
 * in the OpenRegister application.
 *
 * @category  Event
 * @package   OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git-id>
 *
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Register;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a register is created
 */
class RegisterCreatedEvent extends Event
{
    /**
     * The newly created register
     *
     * @var Register The register that was created
     */
    private Register $register;

    /**
     * Constructor for RegisterCreatedEvent
     *
     * @param Register $register The register that was created
     *
     * @return void
     */
    public function __construct(Register $register)
    {
        parent::__construct();
        $this->register = $register;

    }//end __construct()

    /**
     * Get the created register
     *
     * @return Register The register that was created
     */
    public function getRegister(): Register
    {
        return $this->register;

    }//end getRegister()

}//end class
