<?php
/**
 * OpenRegister RevertService
 *
 * Service class for handling object reversion in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\LockedException;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;

/**
 * Class RevertService
 * Service for handling object reversion
 */
class RevertService
{


    /**
     * Constructor for RevertService
     *
     * @param AuditTrailMapper   $auditTrailMapper   The audit trail mapper
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper     $registerMapper     The register mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param ContainerInterface $container          The DI container
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ContainerInterface $container
    ) {

    }//end __construct()


    /**
     * Revert an object to a previous state
     *
     * @param string $register         The register identifier
     * @param string $schema           The schema identifier
     * @param string $id               The object ID
     * @param mixed  $until            The point to revert to (DateTime|string)
     * @param bool   $overwriteVersion Whether to overwrite the version
     *
     * @return ObjectEntity The reverted object
     *
     * @throws DoesNotExistException If object not found
     * @throws NotAuthorizedException If user not authorized
     * @throws LockedException If object is locked
     * @throws \Exception If reversion fails
     */
    public function revert(
        string $register,
        string $schema,
        string $id,
        mixed $until,
        bool $overwriteVersion=false
    ): ObjectEntity {
        // Get the object.
        $object = $this->objectEntityMapper->find($id);

        // Verify that the object belongs to the specified register and schema.
        if ($object->getRegister() !== $register || $object->getSchema() !== $schema) {
            throw new DoesNotExistException('Object not found in specified register/schema');
        }

        // Check if the object is locked.
        if ($object->isLocked() === true) {
            $userId = $this->container->get('userId');
            if ($object->getLockedBy() !== $userId) {
                throw new LockedException(
                    sprintf('Object is locked by %s', $object->getLockedBy())
                );
            }
        }

        // Get the reverted object using AuditTrailMapper.
        $revertedObject = $this->auditTrailMapper->revertObject(
            $id,
            $until,
            $overwriteVersion
        );

        // Save the reverted object.
        return $this->objectEntityMapper->update($revertedObject);

    }//end revert()


}//end class
