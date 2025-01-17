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
	) {}

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
			default => throw new InvalidArgumentException(message: 'Unsupported event type: ' . get_class($event)),
		};
	}

	private function handleNodeCreated(NodeCreatedEvent $event): void {
//		$this->objectService->nodeCreatedEventFunction();
	}

	private function handleNodeDeleted(NodeDeletedEvent $event): void {
//		$this->objectService->nodeDeletedEventFunction();
	}

	private function handleNodeTouched(NodeTouchedEvent $event): void {
//		$this->objectService->nodeTouchedEventFunction();
	}

	private function handleNodeWritten(NodeWrittenEvent $event): void {
//		$this->objectService->nodeWrittenEventFunction();
	}
}
