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
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\LockedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;

/**
 * Class RevertService
 * Service for handling object reversion
 */
class RevertService
{
    /**
     * Audit trail mapper
     *
     * @var AuditTrailMapper
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Container
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Event dispatcher
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * Object entity mapper
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectEntityMapper;

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
        $object = $this->objectEntityMapper->find(identifier: $id);

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
            identifier: $id,
            until: $until,
            overwriteVersion: $overwriteVersion
        );

        // Save the reverted object.
        $savedObject = $this->objectEntityMapper->update($revertedObject);

        // Dispatch revert event.
        $this->eventDispatcher->dispatchTyped(new ObjectRevertedEvent(object: $savedObject, until: $until));

        return $savedObject;

    }//end revert()


}//end class
