<?php


namespace OCA\OpenRegister\EventListener;

use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;

class AbstractNodeFolderEventListener implements IEventListener
{


    public function __construct(
        private readonly ObjectService $objectService,
        private readonly FileService $fileService,
    ) {

    }//end __construct()


    /**
     * @inheritDoc
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
            $event instanceof NodeCreatedEvent => $this->handleNodeCreated(event: $event),
            $event instanceof NodeDeletedEvent => $this->handleNodeDeleted(event: $event),
            $event instanceof NodeTouchedEvent => $this->handleNodeTouched(event: $event),
            $event instanceof NodeWrittenEvent => $this->handleNodeWritten(event: $event),
        default => throw new InvalidArgumentException(message: 'Unsupported event type: '.get_class($event)),
        };

    }//end handle()


    /**
     * Handle node created event
     *
     * @param NodeCreatedEvent $event The node created event
     *
     * @return void
     */
    private function handleNodeCreated(NodeCreatedEvent $event): void
    {
        // Call the object service to handle the node created event
        $this->objectService->nodeCreatedEventFunction(event: $event);
    }

    /**
     * Handle node deleted event
     *
     * @param NodeDeletedEvent $event The node deleted event
     *
     * @return void
     */
    private function handleNodeDeleted(NodeDeletedEvent $event): void
    {
        // Call the object service to handle the node deleted event
        $this->objectService->nodeDeletedEventFunction(event: $event);
    }

    /**
     * Handle node touched event
     *
     * @param NodeTouchedEvent $event The node touched event
     *
     * @return void
     */
    private function handleNodeTouched(NodeTouchedEvent $event): void
    {
        // Call the object service to handle the node touched event
        $this->objectService->nodeTouchedEventFunction(event: $event);
    }

    /**
     * Handle node written event
     *
     * @param NodeWrittenEvent $event The node written event
     *
     * @return void
     */
    private function handleNodeWritten(NodeWrittenEvent $event): void
    {
        // Call the object service to handle the node written event
        $this->objectService->nodeWrittenEventFunction(event: $event);
    }
}//end class