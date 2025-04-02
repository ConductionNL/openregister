<?php
/**
 * OpenRegister AbstractNodesFolderEventListener
 *
 * This file contains the event listener for node folder events
 * in the OpenRegister application.
 *
 * @category  EventListener
 * @package   OCA\OpenRegister\EventListener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git-id>
 *
 * @link      https://OpenRegister.app
 */

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

/**
 * Event listener for node folder events.
 */
class AbstractNodesFolderEventListener implements IEventListener
{
    /**
     * Constructor for AbstractNodesFolderEventListener
     *
     * @param ObjectService $objectService Service for handling object operations
     * @param FileService   $fileService   Service for handling file operations
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly FileService $fileService,
    ) {

    }//end __construct()

    /**
     * Handle incoming events.
     *
     * @param Event $event The event to be handled
     *
     * @return void
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
            default => throw new InvalidArgumentException(
                message: 'Unsupported event type: '.get_class($event)
            ),
        };

    }//end handle()

    /**
     * Handle when a node is copied.
     *
     * @param NodeCopiedEvent $event The node copied event
     *
     * @return void
     */
    private function handleNodeCopied(NodeCopiedEvent $event): void
    {
        // $this->objectService->nodeCopiedEventFunction();
    }//end handleNodeCopied()

    /**
     * Handle when a node is renamed.
     *
     * @param NodeRenamedEvent $event The node renamed event
     *
     * @return void
     */
    private function handleNodeRenamed(NodeRenamedEvent $event): void
    {
        // $this->objectService->nodeRenamedEventFunction();
    }//end handleNodeRenamed()
}//end Class
