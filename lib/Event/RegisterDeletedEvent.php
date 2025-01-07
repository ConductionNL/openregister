<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Register;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a register is deleted
 */
class RegisterDeletedEvent extends Event {
    
    /** @var Register The register that was deleted */
    private Register $register;

    /**
     * Constructor for RegisterDeletedEvent
     * 
     * @param Register $register The register that was deleted
     */
    public function __construct(Register $register) {
        parent::__construct();
        $this->register = $register;
    }

    /**
     * Get the deleted register
     *
     * @return Register The register that was deleted
     */
    public function getRegister(): Register {
        return $this->register;
    }
} 