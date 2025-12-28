<?php

/**
 * OpenRegister AbstractNodeFolderEventListener
 *
 * This file contains the event class dispatched when a schema is updated
 * in the OpenRegister application.
 *
 * @category EventListener
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

namespace OCA\OpenRegister\EventListener;

use InvalidArgumentException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;

/**
 * Abstract node folder event listener template
 *
 * @template-implements IEventListener<Event>
 */
class AbstractNodeFolderEventListener implements IEventListener
{
    /**
     * Constructor for AbstractNodeFolderEventListener.
     *
     * @param ObjectService                         $objectService The object service for handling node events.
     * @param \OCA\OpenRegister\Service\FileService $fileService   The file service for file operations.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly FileService $fileService,
    ) {
    }//end __construct()

    /**
     * Handle event dispatched by the event dispatcher.
     *
     * This method processes node events and dispatches them to appropriate handlers.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof AbstractNodeEvent === false) {
            return;
        }

        $node = $event->getNode();
        if ($node->getType() !== FileInfo::TYPE_FOLDER) {
            return;
        }

        match (true) {
            $event instanceof NodeCreatedEvent => $this->handleNodeCreated($event),
            $event instanceof NodeDeletedEvent => $this->handleNodeDeleted($event),
            $event instanceof NodeTouchedEvent => $this->handleNodeTouched($event),
            $event instanceof NodeWrittenEvent => $this->handleNodeWritten($event),
            default => throw new InvalidArgumentException('Unsupported event type: ' . get_class($event)),
        };
    }//end handle()

    /**
     * Handle node created event
     *
     * @param NodeCreatedEvent $_event The node created event (unused but required by interface)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function handleNodeCreated(NodeCreatedEvent $_event): void
    {
        // ObjectService doesn't have nodeCreatedEventFunction, these methods need to be implemented.
        // For now, log the event but don't call non-existent method.
        // TODO: Implement node event handling in ObjectService or remove these calls.
        // $this->objectService->nodeCreatedEventFunction(event: $event).
    }//end handleNodeCreated()

    /**
     * Handle node deleted event
     *
     * @param NodeDeletedEvent $_event The node deleted event (unused but required by interface)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function handleNodeDeleted(NodeDeletedEvent $_event): void
    {
        // ObjectService doesn't have nodeDeletedEventFunction, these methods need to be implemented.
        // For now, log the event but don't call non-existent method.
        // TODO: Implement node event handling in ObjectService or remove these calls.
        // $this->objectService->nodeDeletedEventFunction(event: $event).
    }//end handleNodeDeleted()

    /**
     * Handle node touched event
     *
     * @param NodeTouchedEvent $_event The node touched event
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function handleNodeTouched(NodeTouchedEvent $_event): void
    {
        // ObjectService doesn't have nodeTouchedEventFunction, these methods need to be implemented.
        // For now, log the event but don't call non-existent method.
        // TODO: Implement node event handling in ObjectService or remove these calls.
        // $this->objectService->nodeTouchedEventFunction(event: $event).
    }//end handleNodeTouched()

    /**
     * Handle node written event
     *
     * @param NodeWrittenEvent $_event The node written event (unused but required by interface)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function handleNodeWritten(NodeWrittenEvent $_event): void
    {
        // ObjectService doesn't have nodeWrittenEventFunction, these methods need to be implemented.
        // For now, log the event but don't call non-existent method.
        // TODO: Implement node event handling in ObjectService or remove these calls.
        // $this->objectService->nodeWrittenEventFunction(event: $event).
    }//end handleNodeWritten()
}//end class
