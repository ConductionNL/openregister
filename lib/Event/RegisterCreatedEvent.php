<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Register;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a register is created
 */
class RegisterCreatedEvent extends Event {
    
    /** @var Register The register that was created */
    private Register $register;

    /**
     * Constructor for RegisterCreatedEvent
     * 
     * @param Register $register The register that was created
     */
    public function __construct(Register $register) {
        parent::__construct();
        $this->register = $register;
    }

    /**
     * Get the created register
     *
     * @return Register The register that was created
     */
    public function getRegister(): Register {
        return $this->register;
    }
} 