<?php
/**
 * OpenRegister OrganisationUpdatedEvent
 *
 * This file contains the event class dispatched when an organisation is updated
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

use OCA\OpenRegister\Db\Organisation;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an organisation is updated.
 */
class OrganisationUpdatedEvent extends Event
{

    /**
     * The updated organisation state.
     *
     * @var Organisation The organisation after update.
     */
    private Organisation $newOrganisation;

    /**
     * The previous organisation state.
     *
     * @var Organisation The organisation before update.
     */
    private Organisation $oldOrganisation;


    /**
     * Constructor for OrganisationUpdatedEvent.
     *
     * @param Organisation $newOrganisation The organisation after update.
     * @param Organisation $oldOrganisation The organisation before update.
     *
     * @return void
     */
    public function __construct(Organisation $newOrganisation, Organisation $oldOrganisation)
    {
        parent::__construct();
        $this->newOrganisation = $newOrganisation;
        $this->oldOrganisation = $oldOrganisation;

    }//end __construct()


    /**
     * Get the updated organisation.
     *
     * @return Organisation The organisation after update.
     */
    public function getNewOrganisation(): Organisation
    {
        return $this->newOrganisation;

    }//end getNewOrganisation()


    /**
     * Get the original organisation.
     *
     * @return Organisation The organisation before update.
     */
    public function getOldOrganisation(): Organisation
    {
        return $this->oldOrganisation;

    }//end getOldOrganisation()


}//end class
