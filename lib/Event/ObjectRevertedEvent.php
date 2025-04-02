<?php
/**
 * OpenRegister ObjectRevertedEvent
 *
 * This file contains the event class dispatched when an object is reverted
 * to a previous state in the OpenRegister application.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an object is reverted to a previous state
 */
class ObjectRevertedEvent extends Event
{
    /**
     * The reverted object entity
     *
     * @var ObjectEntity The object that has been reverted
     */
    private ObjectEntity $object;

    /**
     * The reversion point reference
     *
     * @var \DateTime|string|null The point in time or audit ID reverted to
     */
    private $until;

    /**
     * Constructor for ObjectRevertedEvent
     *
     * @param ObjectEntity          $object The reverted object
     * @param \DateTime|string|null $until  The point in time or audit ID reverted to
     *
     * @return void
     */
    public function __construct(ObjectEntity $object, $until = null)
    {
        parent::__construct();
        $this->object = $object;
        $this->until = $until;

    }//end __construct()

    /**
     * Get the reverted object entity
     *
     * @return ObjectEntity The object that has been reverted
     */
    public function getObject(): ObjectEntity
    {
        return $this->object;

    }//end getObject()

    /**
     * Get the reversion point
     *
     * @return \DateTime|string|null The point in time or audit ID reverted to
     */
    public function getRevertPoint()
    {
        return $this->until;

    }//end getRevertPoint()

}//end class
