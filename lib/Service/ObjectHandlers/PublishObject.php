<?php
/**
 * OpenRegister PublishObject
 *
 * Handler class for publishing objects in the OpenRegister application.
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
 * Handler for publishing objects
 */
class PublishObject
{

    /**
     * Object entity mapper
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectEntityMapper;


    /**
     * Publish an object
     *
     * @param string        $uuid  The UUID of the object to publish
     * @param DateTime|null $date  Optional publication date
     * @param bool          $rbac  Whether to apply RBAC checks (default: true).
     * @param bool          $multi Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The published object
     *
     * @throws Exception If the object is not found or if there's an error during update
     */
    public function publish(
        string $uuid,
        ?DateTime $date=null,
        bool $_rbac=true,
        bool $_multi=true
    ): ObjectEntity {
        // Get the object.
        $object = $this->objectEntityMapper->find($uuid);
        /** @psalm-suppress TypeDoesNotContainNull - find() throws DoesNotExistException, never returns null */
        if ($object === null) {
            throw new Exception('Object not found');
        }

        // Set publication date to now if not specified.
        $date = $date ?? new DateTime();

        // Set the publication date directly on the object.
        $object->setPublished($date);
        $object->setDepublished(null);

        // Update the object in the database.
        return $this->objectEntityMapper->update($object);

    }//end publish()


}//end class
