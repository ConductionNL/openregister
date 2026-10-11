<?php

/**
 * The Nextcloud Files and WebDAV door of the file freeze: a write into a
 * frozen or archived object's folder is aborted.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\BeforeNodeCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Node;
use OCP\HintException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aborts a write, create, delete or rename of a node that sits in a frozen
 * or archived object's folder.
 *
 * The files API refuses through {@see FileWriteGuard} in the controller, but
 * an object's folder is also reachable through Nextcloud Files and WebDAV,
 * and those never pass the controller. This listener is that door.
 *
 * How a node finds its object: object folders live in the `openregister`
 * account's home and are named by the object's UUID. A node outside that
 * home is ignored at once (one string compare, so the rest of the instance
 * pays nothing). Inside it, the nearest ancestor named like a UUID is the
 * candidate owner, and it counts only when that object's own `folder` is
 * that ancestor's id, the same proof `FolderManagementHandler::getObjectForFile()`
 * asks for.
 *
 * Fail closed: when the lookup of a candidate owner FAILS (a database error,
 * anything but "no such object"), the write is refused and logged with the
 * node id. "No such object" is not a failure: it is the folder of an object
 * that is still being created, and refusing it would break every upload.
 *
 * How the abort reaches the caller: delete and rename have
 * `abortOperation()`, which Nextcloud turns into a cancelled operation.
 * Write and create have no abort; Nextcloud's hook emitter swallows every
 * exception there except a {@see HintException}, so the refusal is thrown as
 * one, and the write never starts.
 *
 * @template-implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The four node events, the node and the abort are the contract.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */
class FrozenNodeWriteListener implements IEventListener {

	/**
	 * How far up from a node the owner search climbs.
	 *
	 * Object folders sit directly under a register folder; a few levels of
	 * subfolders inside an object folder are allowed for, no more.
	 *
	 * @var integer
	 */
	private const MAX_DEPTH = 8;

	/**
	 * Constructor.
	 *
	 * @param FileWriteGuard  $guard   Decides whether the owning object refuses file writes.
	 * @param MagicMapper     $objects Resolves the owning object by its UUID.
	 * @param LoggerInterface $logger  Records a refusal on an unresolvable owner.
	 */
	public function __construct(
		private readonly FileWriteGuard $guard,
		private readonly MagicMapper $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Abort the operation when a node it touches sits in a frozen or archived object's folder.
	 *
	 * @param Event $event The node event.
	 *
	 * @return void
	 *
	 * @throws HintException On a refused write or create (Nextcloud has no abort for those).
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
	 */
	public function handle(Event $event): void {
		$nodes = $this->nodesOf(event: $event);
		foreach ($nodes as $node) {
			$refusal = $this->refusalFor(node: $node);
			if ($refusal === null) {
				continue;
			}

			if ($event instanceof BeforeNodeRenamedEvent || $event instanceof BeforeNodeDeletedEvent) {
				$event->abortOperation(ex: $refusal);
			}

			throw new HintException(
				message: $refusal->getMessage(),
				hint: $refusal->getMessage(),
				code: ObjectStateWriteException::HTTP_STATUS,
				previous: $refusal
			);
		}
	}//end handle()

	/**
	 * The nodes an event writes to: the node itself, or both ends of a rename.
	 *
	 * @param Event $event The event.
	 *
	 * @return list<Node> The nodes to check; empty for an event this listener does not handle.
	 */
	private function nodesOf(Event $event): array {
		if ($event instanceof BeforeNodeRenamedEvent) {
			return [$event->getSource(), $event->getTarget()];
		}

		$handled = ($event instanceof BeforeNodeWrittenEvent
			|| $event instanceof BeforeNodeCreatedEvent
			|| $event instanceof BeforeNodeDeletedEvent);
		if ($handled === true && $event instanceof AbstractNodeEvent) {
			return [$event->getNode()];
		}

		return [];
	}//end nodesOf()

	/**
	 * The refusal for a write to this node, or null when it may go ahead.
	 *
	 * @param Node $node The node about to change.
	 *
	 * @return ObjectStateWriteException|null The refusal, or null.
	 */
	private function refusalFor(Node $node): ?ObjectStateWriteException {
		try {
			$path = $node->getPath();
		} catch (Throwable $e) {
			return null;
		}

		$home = '/' . FileOwnershipHandler::APP_USER . '/files/';
		if (str_starts_with($path, $home) === false) {
			return null;
		}

		$candidate = $this->candidateOwnerFolder(node: $node);
		if ($candidate === null) {
			return null;
		}

		try {
			$found = $this->objects->findAcrossAllSources(
				identifier: $candidate['uuid'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'[FrozenNodeWriteListener] A file write was refused: the owning object of node {node} could not be resolved.',
				['node' => $this->nodeId(node: $node), 'path' => $path, 'exception' => $e]
			);
			return new ObjectStateWriteException(
				message: 'Cannot write to this file: the object that owns it could not be checked. Try again later.',
				previous: $e
			);
		}

		$object = ($found['object'] ?? null);
		if ($object instanceof ObjectEntity === false) {
			return null;
		}

		if ((string)$object->getFolder() !== (string)$candidate['folderId']) {
			return null;
		}

		return $this->guard->refusalFor(object: $object);
	}//end refusalFor()

	/**
	 * The nearest ancestor of a node named like a UUID, with its folder id.
	 *
	 * @param Node $node The node.
	 *
	 * @return array{uuid: string, folderId: int|string}|null The candidate, or null when no ancestor qualifies.
	 */
	private function candidateOwnerFolder(Node $node): ?array {
		$current = $node;
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			try {
				$current = $current->getParent();
				$name = $current->getName();
			} catch (Throwable $e) {
				return null;
			}

			if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) === 1) {
				try {
					return ['uuid' => $name, 'folderId' => (int)$current->getId()];
				} catch (Throwable $e) {
					return null;
				}
			}

			if ($name === 'files' || $name === '') {
				return null;
			}
		}

		return null;
	}//end candidateOwnerFolder()

	/**
	 * The node's id for the log, or null when it has none yet.
	 *
	 * @param Node $node The node.
	 *
	 * @return int|null The id.
	 */
	private function nodeId(Node $node): ?int {
		try {
			return $node->getId();
		} catch (Throwable $e) {
			return null;
		}
	}//end nodeId()
}//end class
