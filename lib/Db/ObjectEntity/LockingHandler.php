<?php
/**
 * OpenRegister ObjectEntity Locking Handler
 *
 * Handles object locking and unlocking operations for concurrency control.
 * Extracted from ObjectEntityMapper as part of SOLID refactoring.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ObjectEntity
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Db\ObjectEntity;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * LockingHandler
 *
 * Handles locking and unlocking of ObjectEntity instances for concurrency control.
 * Manages lock acquisition, release, and permission checks.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ObjectEntity
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class LockingHandler
{

    /**
     * Default lock duration in seconds
     *
     * @var int
     */
    private const DEFAULT_LOCK_DURATION = 300;

    /**
     * Object entity mapper
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $mapper;

    /**
     * User session
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Event dispatcher
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param ObjectEntityMapper $mapper          Object entity mapper.
     * @param IUserSession       $userSession     User session.
     * @param IEventDispatcher   $eventDispatcher Event dispatcher.
     * @param LoggerInterface    $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        ObjectEntityMapper $mapper,
        IUserSession $userSession,
        IEventDispatcher $eventDispatcher,
        LoggerInterface $logger
    ) {
        $this->mapper          = $mapper;
        $this->userSession     = $userSession;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger          = $logger;

    }//end __construct()


    /**
     * Lock an object
     *
     * Locks an object for exclusive access by the current user/process.
     * Dispatches ObjectLockedEvent after successful locking.
     *
     * @param string|int  $identifier Object ID, UUID, or URI.
     * @param string|null $process    Optional process identifier.
     * @param int|null    $duration   Lock duration in seconds (default: 300).
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \Exception If user not logged in or locking fails.
     *
     * @return ObjectEntity The locked object
     */
    public function lockObject($identifier, ?string $process=null, ?int $duration=null): ObjectEntity
    {
        // Find the object.
        $object = $this->mapper->find($identifier);

        // Use default duration if not provided.
        if ($duration === null) {
            $duration = self::DEFAULT_LOCK_DURATION;
        }

        // Check if user has permission to lock.
        if ($this->userSession->isLoggedIn() === false) {
            throw new Exception('Must be logged in to lock objects');
        }

        $this->logger->debug(
            message: '[LockingHandler] Locking object',
            context: [
                'identifier' => $identifier,
                'process'    => $process,
                'duration'   => $duration,
            ]
        );

        // Attempt to lock the object.
        $object->lock(userSession: $this->userSession, process: $process, duration: $duration);

        // Save the locked object.
        $object = $this->mapper->update($object);

        // Dispatch lock event.
        $this->eventDispatcher->dispatchTyped(new ObjectLockedEvent($object));

        $this->logger->info(
            message: '[LockingHandler] Object locked successfully',
            context: [
                'objectId' => $object->getId(),
            ]
        );

        return $object;

    }//end lockObject()


    /**
     * Unlock an object
     *
     * Unlocks an object, releasing exclusive access.
     * Dispatches ObjectUnlockedEvent after successful unlocking.
     *
     * @param string|int $identifier Object ID, UUID, or URI.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \Exception If user not logged in or unlocking fails.
     *
     * @return ObjectEntity The unlocked object
     */
    public function unlockObject($identifier): ObjectEntity
    {
        // Find the object.
        $object = $this->mapper->find($identifier);

        // Check if user has permission to unlock.
        if ($this->userSession->isLoggedIn() === false) {
            throw new Exception('Must be logged in to unlock objects');
        }

        $this->logger->debug(
            message: '[LockingHandler] Unlocking object',
            context: [
                'identifier' => $identifier,
            ]
        );

        // Attempt to unlock the object.
        $object->unlock($this->userSession);

        // Save the unlocked object.
        $object = $this->mapper->update($object);

        // Dispatch unlock event.
        $this->eventDispatcher->dispatchTyped(new ObjectUnlockedEvent($object));

        $this->logger->info(
            message: '[LockingHandler] Object unlocked successfully',
            context: [
                'objectId' => $object->getId(),
            ]
        );

        return $object;

    }//end unlockObject()


}//end class

