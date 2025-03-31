<?php


namespace OCA\OpenRegister\EventListener;

use InvalidArgumentException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\FileInfo;

class AbstractNodesFolderEventListener implements IEventListener
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
        if ($event instanceof AbstractNodesEvent === false) {
            return;
        }

        $sourceNode = $event->getSource();
        if ($sourceNode->getType() === FileInfo::TYPE_FOLDER) {
            return;
        }

        match (true) {
            $event instanceof NodeCopiedEvent => $this->handleNodeCopied(event: $event),
            $event instanceof NodeRenamedEvent => $this->handleNodeRenamed(event: $event),
        default => throw new InvalidArgumentException(message: 'Unsupported event type: '.get_class($event)),
        };

    }//end handle()


    private function handleNodeCopied(NodeCopiedEvent $event): void
    {
        // $this->objectService->nodeCopiedEventFunction();    }//end handleNodeCopied()    private function handleNodeRenamed(NodeRenamedEvent $event): void
    {
        // $this->objectService->nodeRenamedEventFunction();    }//end handleNodeRenamed()
    }//end handleNodeRenamed()
