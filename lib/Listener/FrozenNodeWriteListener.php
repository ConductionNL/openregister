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
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\HintException;
use Psr\Container\ContainerInterface;
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
 * account's home and are named by the object's UUID. A node in that home is
 * checked by its path. A node reached through a share (the way a person gets
 * at an object's files) has the path of the sharee's own tree, so it is
 * resolved by file id instead: a node whose owner is not the `openregister`
 * account is ignored at once (one owner compare, so the rest of the instance
 * pays nothing); one that is owned by it is looked up by id in the account's
 * home, and a node that does not exist yet stands for its nearest existing
 * ancestor. In the home, the nearest ancestor named like a UUID is the
 * candidate owner, and it counts only when that object's own `folder` is
 * that ancestor's id, the same proof `FolderManagementHandler::getObjectForFile()`
 * asks for. The mapper that resolves the object is fetched from the container
 * only at that point, so a node outside every object folder never builds it.
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
	 * @param FileWriteGuard     $guard      Decides whether the owning object refuses file writes.
	 * @param ContainerInterface $container  Resolves the MagicMapper (the owning object by its UUID) once a node
	 *                                       has proved to be in an object folder: this listener sits on the four
	 *                                       node events of every file operation on the instance, and the mapper's
	 *                                       graph is wide.
	 * @param LoggerInterface    $logger     Records a refusal on an unresolvable owner.
	 * @param IRootFolder        $rootFolder Finds a shared node's twin in the `openregister` home by file id.
	 */
	public function __construct(
		private readonly FileWriteGuard $guard,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IRootFolder $rootFolder,
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
		$anchor = $node;
		$includeSelf = false;
		if (str_starts_with($path, $home) === false) {
			try {
				$resolved = $this->homeNodeOf(node: $node, home: $home);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[FrozenNodeWriteListener] A file write was refused: node {node} is owned by the app account but could not be placed in its home.',
					['node' => $this->nodeId(node: $node), 'path' => $path, 'exception' => $e]
				);
				return new ObjectStateWriteException(
					message: 'Cannot write to this file: the object that owns it could not be checked. Try again later.',
					previous: $e
				);
			}

			if ($resolved === null) {
				return null;
			}

			$anchor = $resolved['node'];
			$includeSelf = ($resolved['exact'] === false);
		}

		$candidate = $this->candidateOwnerFolder(node: $anchor, includeSelf: $includeSelf);
		if ($candidate === null) {
			return null;
		}

		$object = $this->owningObjectOf(node: $node, path: $path, candidate: $candidate);
		if ($object instanceof ObjectStateWriteException) {
			return $object;
		}

		if ($object === null) {
			return null;
		}

		return $this->guard->refusalFor(object: $object);
	}//end refusalFor()

	/**
	 * The object that owns a file's folder, or a refusal when it cannot be resolved.
	 *
	 * @param Node                                      $node      The node about to change.
	 * @param string                                    $path      The node's path, for the log.
	 * @param array{uuid: string, folderId: int|string} $candidate The ancestor folder named like a UUID.
	 *
	 * @return ObjectEntity|ObjectStateWriteException|null The owning object; the refusal when the lookup failed; null when the folder owns nothing.
	 */
	private function owningObjectOf(Node $node, string $path, array $candidate): ObjectEntity|ObjectStateWriteException|null {
		try {
			$found = $this->container->get(MagicMapper::class)->findAcrossAllSources(
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

		return $object;
	}//end owningObjectOf()

	/**
	 * The node in the `openregister` home that a node outside it stands for, found by file id.
	 *
	 * A node reached through a share has the path of the acting user's tree,
	 * but is the same file as the one in the home. Only a node owned by the
	 * `openregister` account can be one, so everything else answers null
	 * without a lookup. A node that does not exist yet (a write or a rename
	 * target) has no id: its nearest existing ancestor stands for it, and
	 * `exact` says that it is not the node itself.
	 *
	 * @param Node   $node The node about to change.
	 * @param string $home The `openregister` home prefix, with trailing slash.
	 *
	 * @return array{node: Node, exact: bool}|null The twin in the home, or null when this node is not one of the app's files.
	 *
	 * @throws Throwable When the home cannot be searched (the caller refuses).
	 */
	private function homeNodeOf(Node $node, string $home): ?array {
		$current = $node;
		$exact = true;
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			try {
				$owner = $current->getOwner();
				$identifier = $current->getId();
			} catch (Throwable $e) {
				// Not stored yet: the nearest existing ancestor decides.
				try {
					$current = $current->getParent();
				} catch (Throwable $parentError) {
					return null;
				}

				$exact = false;
				continue;
			}

			if ($owner === null || $owner->getUID() !== FileOwnershipHandler::APP_USER) {
				return null;
			}

			$twins = $this->rootFolder->getUserFolder(FileOwnershipHandler::APP_USER)->getById($identifier);
			foreach ($twins as $twin) {
				if (str_starts_with($twin->getPath(), $home) === true) {
					return ['node' => $twin, 'exact' => $exact];
				}
			}

			return null;
		}

		return null;
	}//end homeNodeOf()

	/**
	 * The nearest ancestor of a node named like a UUID, with its folder id.
	 *
	 * @param Node $node        The node.
	 * @param bool $includeSelf Whether the node itself may be the owner folder (a node standing for a not-yet-existing child).
	 *
	 * @return array{uuid: string, folderId: int|string}|null The candidate, or null when no ancestor qualifies.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag names where the walk starts (the node or its parent), not a second behaviour.
	 */
	private function candidateOwnerFolder(Node $node, bool $includeSelf = false): ?array {
		$current = $node;
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			try {
				if ($depth > 0 || $includeSelf === false) {
					$current = $current->getParent();
				}

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
