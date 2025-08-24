<?php
/**
 * Organisation Created Event
 *
 * This file contains the event class that is dispatched when an organisation entity is created.
 *
 * @category  Event
 * @package   OCA\OpenRegister\Event
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/OpenRegister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;
use OCA\OpenRegister\Db\Organisation;

/**
 * Event dispatched when an organisation entity is created
 *
 * This event is fired after an organisation entity has been successfully
 * created and committed to the database.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/OpenRegister
 */
class OrganisationCreatedEvent extends Event
{

    /**
     * The organisation that was created
     *
     * @var Organisation
     */
    private Organisation $organisation;


    /**
     * OrganisationCreatedEvent constructor
     *
     * @param Organisation $organisation The organisation that was created
     */
    public function __construct(Organisation $organisation)
    {
        parent::__construct();
        $this->organisation = $organisation;

    }//end __construct()


    /**
     * Get the organisation that was created
     *
     * @return Organisation The organisation entity
     */
    public function getOrganisation(): Organisation
    {
        return $this->organisation;

    }//end getOrganisation()


}//end class
