<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Register;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a register is updated
 */
class RegisterUpdatedEvent extends Event
{

    /**
     * @var Register The register after update
     */
    private Register $newRegister;

    /**
     * @var Register The register before update
     */
    private Register $oldRegister;


    /**
     * Constructor for RegisterUpdatedEvent
     *
     * @param Register $newRegister The register after update
     * @param Register $oldRegister The register before update
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
