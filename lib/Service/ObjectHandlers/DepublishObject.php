<?php
/**
 * OpenRegister DepublishObject
 *
 * Handler class for depublishing objects in the OpenRegister application.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\ObjectHandlers;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Handler for depublishing objects
 */
class DepublishObject
{
    /**
     * Constructor for DepublishObject
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper
    ) {
    }

    /**
     * Depublish an object
     *
   * @param string        $uuid     The UUID of the object to depublish
     * @param DateTime|null $date     Optional depublication date
     *
     * @return ObjectEntity The depublished object
     *
     * @throws Exception If the object is not found or if there's an error during update
     */
    public function depublish(
        string $uuid,
        ?DateTime $date = null
    ): ObjectEntity {
        // Get the object
        $object = $this->objectEntityMapper->find($uuid);
        if ($object === null) {
            throw new Exception('Object not found');
        }

        // Set depublication date to now if not specified
        $date = $date ?? new DateTime();

        // Set the depublication date directly on the object
        $object->setDepublished($date);

        // Update the object in the database
        return $this->objectEntityMapper->update($object);
    }
} 