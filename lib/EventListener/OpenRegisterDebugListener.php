<?php

/**
 * OpenRegister Debug Event Listener
 *
 * A comprehensive debug listener that handles all OpenRegister events for debugging purposes.
 * This listener logs detailed information about events when debug mode is enabled.
 *
 * @category EventListener
 * @package  OCA\OpenRegister\EventListener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\EventListener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use Psr\Log\LoggerInterface;

/**
 * Debug event listener for all OpenRegister events
 *
 * This listener provides comprehensive debugging information for all OpenRegister events.
 * It logs event details at debug level and can be easily enabled/disabled.
 *
 * @template T of Event
 *
 * @implements IEventListener<T>
 */
class OpenRegisterDebugListener implements IEventListener
{

    /**
     * Logger instance for debug logging
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Whether debug logging is enabled
     *
     * @var bool
     */
    private readonly bool $debugEnabled;


    /**
     * Constructor for the debug listener
     *
     * @param LoggerInterface $logger Logger instance for debug output
     * @param bool           $debugEnabled Whether debug logging should be enabled
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        bool $debugEnabled = true
    ) {
        $this->logger = $logger;
        $this->debugEnabled = $debugEnabled;

    }//end __construct()


    /**
     * Handle any OpenRegister event for debugging purposes
     *
     * This method processes all OpenRegister events and logs detailed debug information
     * including event type, object details, and any relevant metadata.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @phpstan-param T $event
     */
    public function handle(Event $event): void
    {
        // CRITICAL: Always log regardless of debug flag to ensure we see if it's called
        $eventClass = get_class($event);
        $eventType = $this->getEventTypeName($eventClass);
        
        $this->logger->critical('🔍 OPENREGISTER: DEBUG LISTENER TRIGGERED!', [
            'app' => 'openregister',
            'eventType' => $eventType,
            'eventClass' => $eventClass,
            'listenerClass' => self::class,
            'debugEnabled' => $this->debugEnabled,
            'timestamp' => date('Y-m-d H:i:s'),
            'microtime' => microtime(true),
            'source' => 'OpenRegister',
        ]);
        
        // Log to structured logger for better integration
        $this->logger->info('OpenRegister debug listener triggered', [
            'eventType' => $eventType,
            'eventClass' => $eventClass,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        if (!$this->debugEnabled) {
            $this->logger->warning('OpenRegister Debug: Debug disabled, skipping detailed logging');
            $this->logger->warning('Debug logging disabled for event', ['eventType' => $eventType]);
            return;
        }

        $eventData = $this->extractEventData($event);

        // Log comprehensive debug information
        $this->logger->info(
            '[OpenRegister] 🔍 DEBUG EVENT: {eventType} received',
            [
                'app' => 'openregister',
                'eventType' => $eventType,
                'eventClass' => $eventClass,
                'listenerClass' => self::class,
                'eventData' => $eventData,
                'timestamp' => date('Y-m-d H:i:s'),
                'source' => 'OpenRegister',
            ]
        );

    }//end handle()


    /**
     * Extract a human-readable event type name from the class name
     *
     * @param string $eventClass The full event class name
     *
     * @return string The simplified event type name
     *
     * @phpstan-return string
     * @psalm-return   string
     */
    private function getEventTypeName(string $eventClass): string
    {
        // Extract the class name without namespace
        $className = substr($eventClass, strrpos($eventClass, '\\') + 1);
        
        // Remove 'Event' suffix if present
        if (str_ends_with($className, 'Event')) {
            $className = substr($className, 0, -5);
        }

        return $className;

    }//end getEventTypeName()


    /**
     * Extract relevant data from the event for debugging
     *
     * This method extracts useful information from different event types
     * to provide comprehensive debug logging.
     *
     * @param Event $event The event to extract data from
     *
     * @return array<string, mixed> Array of extracted event data
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return   array<string, mixed>
     */
    private function extractEventData(Event $event): array
    {
        $data = [
            'eventClass' => get_class($event),
        ];

        // Handle Object events
        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
            $data = array_merge($data, [
                'eventType' => 'ObjectCreated',
                'objectId' => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId' => $object->getSchema(),
                'owner' => $object->getOwner(),
                'created' => $object->getCreated()?->format('Y-m-d H:i:s'),
            ]);
        } else if ($event instanceof ObjectUpdatedEvent) {
            $newObject = $event->getNewObject();
            $oldObject = $event->getOldObject();
            $data = array_merge($data, [
                'eventType' => 'ObjectUpdated',
                'newObjectId' => $newObject->getId(),
                'newObjectUuid' => $newObject->getUuid(),
                'oldObjectId' => $oldObject?->getId(),
                'oldObjectUuid' => $oldObject?->getUuid(),
                'registerId' => $newObject->getRegister(),
                'schemaId' => $newObject->getSchema(),
                'owner' => $newObject->getOwner(),
                'updated' => $newObject->getUpdated()?->format('Y-m-d H:i:s'),
            ]);
        } else if ($event instanceof ObjectDeletedEvent) {
            $object = $event->getObject();
            $data = array_merge($data, [
                'eventType' => 'ObjectDeleted',
                'objectId' => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId' => $object->getSchema(),
                'owner' => $object->getOwner(),
            ]);
        } else if ($event instanceof ObjectLockedEvent) {
            $object = $event->getObject();
            $data = array_merge($data, [
                'eventType' => 'ObjectLocked',
                'objectId' => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId' => $object->getSchema(),
                'lockedBy' => $object->getLockedBy(),
                'lockedAt' => $object->getLockedAt()?->format('Y-m-d H:i:s'),
            ]);
        } else if ($event instanceof ObjectUnlockedEvent) {
            $object = $event->getObject();
            $data = array_merge($data, [
                'eventType' => 'ObjectUnlocked',
                'objectId' => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId' => $object->getSchema(),
            ]);
        } else if ($event instanceof ObjectRevertedEvent) {
            $object = $event->getObject();
            $data = array_merge($data, [
                'eventType' => 'ObjectReverted',
                'objectId' => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId' => $object->getSchema(),
                'revertedTo' => $event->getRevertedToVersion(),
            ]);
        }

        // Handle Register events
        else if ($event instanceof RegisterCreatedEvent) {
            $register = $event->getRegister();
            $data = array_merge($data, [
                'eventType' => 'RegisterCreated',
                'registerId' => $register->getId(),
                'registerTitle' => $register->getTitle(),
                'registerSlug' => $register->getSlug(),
            ]);
        } else if ($event instanceof RegisterUpdatedEvent) {
            $newRegister = $event->getNewRegister();
            $oldRegister = $event->getOldRegister();
            $data = array_merge($data, [
                'eventType' => 'RegisterUpdated',
                'registerId' => $newRegister->getId(),
                'registerTitle' => $newRegister->getTitle(),
                'registerSlug' => $newRegister->getSlug(),
                'oldRegisterId' => $oldRegister->getId(),
                'oldRegisterTitle' => $oldRegister->getTitle(),
            ]);
        } else if ($event instanceof RegisterDeletedEvent) {
            $register = $event->getRegister();
            $data = array_merge($data, [
                'eventType' => 'RegisterDeleted',
                'registerId' => $register->getId(),
                'registerTitle' => $register->getTitle(),
                'registerSlug' => $register->getSlug(),
            ]);
        }

        // Handle Schema events
        else if ($event instanceof SchemaCreatedEvent) {
            $schema = $event->getSchema();
            $data = array_merge($data, [
                'eventType' => 'SchemaCreated',
                'schemaId' => $schema->getId(),
                'schemaTitle' => $schema->getTitle(),
                'schemaVersion' => $schema->getVersion(),
            ]);
        } else if ($event instanceof SchemaUpdatedEvent) {
            $schema = $event->getNewSchema();
            $data = array_merge($data, [
                'eventType' => 'SchemaUpdated',
                'schemaId' => $schema->getId(),
                'schemaTitle' => $schema->getTitle(),
                'schemaVersion' => $schema->getVersion(),
            ]);
        } else if ($event instanceof SchemaDeletedEvent) {
            $schema = $event->getSchema();
            $data = array_merge($data, [
                'eventType' => 'SchemaDeleted',
                'schemaId' => $schema->getId(),
                'schemaTitle' => $schema->getTitle(),
                'schemaVersion' => $schema->getVersion(),
            ]);
        }

        // Handle Organisation events
        else if ($event instanceof OrganisationCreatedEvent) {
            $organisation = $event->getOrganisation();
            $data = array_merge($data, [
                'eventType' => 'OrganisationCreated',
                'organisationId' => $organisation->getId(),
                'organisationTitle' => $organisation->getTitle(),
            ]);
        }

        // Unknown event type
        else {
            $data['eventType'] = 'Unknown';
            $data['note'] = 'Event type not specifically handled by debug listener';
        }

        return $data;

    }//end extractEventData()


}//end class
