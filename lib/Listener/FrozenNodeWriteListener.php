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

use Generator;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Listener\Support\NodeWriteTarget;
use OCA\OpenRegister\Service\Object\FileWriteGuardInterface;
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
	 * The `openregister` account's home, where object folders live.
	 *
	 * @var string
	 */
	private const HOME = '/' . FileOwnershipHandler::APP_USER . '/files/';

	/**
	 * What the user reads when the object that owns a file could not be checked.
	 *
	 * @var string
	 */
	private const UNCHECKABLE = 'Cannot write to this file: the object that owns it could not be checked. Try again later.';

	/**
	 * The log line of a node of the app account that could not be placed in its home.
	 *
	 * @var string
	 */
	private const LOG_NOT_IN_HOME = '[FrozenNodeWriteListener] A file write was refused: '
		. 'node {node} is owned by the app account but could not be placed in its home.';

	/**
	 * The log line of an owning object that could not be resolved.
	 *
	 * @var string
	 */
	private const LOG_OWNER_UNRESOLVED = '[FrozenNodeWriteListener] A file write was refused: the owning object of node {node} could not be resolved.';

	/**
	 * The node events that touch one node.
	 *
	 * @var list<class-string<AbstractNodeEvent>>
	 */
	private const SINGLE_NODE_EVENTS = [
		BeforeNodeWrittenEvent::class,
		BeforeNodeCreatedEvent::class,
		BeforeNodeDeletedEvent::class,
	];

	/**
	 * Constructor.
	 *
	 * @param FileWriteGuardInterface     $guard      Decides whether the owning object refuses file writes.
	 * @param ContainerInterface $container  Resolves the MagicMapper (the owning object by its UUID) once a node
	 *                                       has proved to be in an object folder: this listener sits on the four
	 *                                       node events of every file operation on the instance, and the mapper's
	 *                                       graph is wide.
	 * @param LoggerInterface    $logger     Records a refusal on an unresolvable owner.
	 * @param IRootFolder        $rootFolder Finds a shared node's twin in the `openregister` home by file id.
	 */
	public function __construct(
		private readonly FileWriteGuardInterface $guard,
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
		foreach ($this->nodesOf(event: $event) as $node) {
			$refusal = $this->refusalFor(node: $node);
			if ($refusal !== null) {
				$this->abort(event: $event, refusal: $refusal);
			}
		}
	}//end handle()

	/**
	 * Abort the operation with the refusal.
	 *
	 * @param Event                     $event   The node event.
	 * @param ObjectStateWriteException $refusal Why the operation may not go ahead.
	 *
	 * @return never
	 *
	 * @throws HintException Always: write and create have no abort, and the operation must not start.
	 */
	private function abort(Event $event, ObjectStateWriteException $refusal): never {
		if ($event instanceof BeforeNodeRenamedEvent || $event instanceof BeforeNodeDeletedEvent) {
			$event->abortOperation(ex: $refusal);
		}

		throw new HintException(
			message: $refusal->getMessage(),
			hint: $refusal->getMessage(),
			code: ObjectStateWriteException::HTTP_STATUS,
			previous: $refusal
		);
	}//end abort()

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

		if ($event instanceof AbstractNodeEvent && $this->touchesOneNode(event: $event) === true) {
			return [$event->getNode()];
		}

		return [];
	}//end nodesOf()

	/**
	 * Whether the event is one of the single-node events this listener handles.
	 *
	 * @param AbstractNodeEvent $event The event.
	 *
	 * @return bool True for a write, create or delete.
	 */
	private function touchesOneNode(AbstractNodeEvent $event): bool {
		foreach (self::SINGLE_NODE_EVENTS as $class) {
			if ($event instanceof $class) {
				return true;
			}
		}

		return false;
	}//end touchesOneNode()

	/**
	 * The refusal for a write to this node, or null when it may go ahead.
	 *
	 * @param Node $node The node about to change.
	 *
	 * @return ObjectStateWriteException|null The refusal, or null.
	 */
	private function refusalFor(Node $node): ?ObjectStateWriteException {
		try {
			$target = new NodeWriteTarget(node: $node, path: $node->getPath());
		} catch (Throwable $e) {
			return null;
		}

		$anchor = $this->anchorOf(target: $target);
		if (is_array($anchor) === false) {
			return $anchor;
		}

		return $this->refusalInOwnerFolder(target: $target, anchor: $anchor);
	}//end refusalFor()

	/**
	 * Where the owner search starts: the node in the `openregister` home that stands for the target.
	 *
	 * @param NodeWriteTarget $target The node about to change.
	 *
	 * @return array{node: Node, includeSelf: bool}|ObjectStateWriteException|null The start of the walk;
	 *     the refusal when a node of the app account cannot be placed in its home; null when the node is not one of the app's files.
	 */
	private function anchorOf(NodeWriteTarget $target): array|ObjectStateWriteException|null {
		if (str_starts_with($target->path, self::HOME) === true) {
			return ['node' => $target->node, 'includeSelf' => false];
		}

		try {
			$resolved = $this->homeNodeOf(node: $target->node);
		} catch (Throwable $e) {
			return $this->uncheckable(logMessage: self::LOG_NOT_IN_HOME, context: $target->logContext(failure: $e));
		}

		if ($resolved === null) {
			return null;
		}

		return ['node' => $resolved['node'], 'includeSelf' => ($resolved['exact'] === false)];
	}//end anchorOf()

	/**
	 * The refusal of the object whose folder holds the anchor, or null when no frozen or archived object owns it.
	 *
	 * @param NodeWriteTarget                       $target The node about to change.
	 * @param array{node: Node, includeSelf: bool} $anchor Where the owner search starts.
	 *
	 * @return ObjectStateWriteException|null The refusal, or null.
	 */
	private function refusalInOwnerFolder(NodeWriteTarget $target, array $anchor): ?ObjectStateWriteException {
		$candidate = $this->candidateOwnerFolder(node: $anchor['node'], includeSelf: $anchor['includeSelf']);
		if ($candidate === null) {
			return null;
		}

		$object = $this->owningObjectOf(target: $target, candidate: $candidate);
		if ($object instanceof ObjectEntity) {
			return $this->guard->refusalFor(object: $object);
		}

		return $object;
	}//end refusalInOwnerFolder()

	/**
	 * Log a refusal on an owner that could not be checked, and build it.
	 *
	 * Fail closed: the write is refused, and the log names the node.
	 *
	 * @param string                                                     $logMessage What the log says.
	 * @param array{node: int|null, path: string, exception: Throwable} $context    The node, its path and the failure.
	 *
	 * @return ObjectStateWriteException The refusal.
	 */
	private function uncheckable(string $logMessage, array $context): ObjectStateWriteException {
		$this->logger->warning($logMessage, $context);
		return new ObjectStateWriteException(message: self::UNCHECKABLE, previous: $context['exception']);
	}//end uncheckable()

	/**
	 * The object that owns a file's folder, or a refusal when it cannot be resolved.
	 *
	 * @param NodeWriteTarget                           $target    The node about to change.
	 * @param array{uuid: string, folderId: int|string} $candidate The ancestor folder named like a UUID.
	 *
	 * @return ObjectEntity|ObjectStateWriteException|null The owning object; the refusal when the lookup failed; null when the folder owns nothing.
	 */
	private function owningObjectOf(NodeWriteTarget $target, array $candidate): ObjectEntity|ObjectStateWriteException|null {
		try {
			$found = $this->container->get(MagicMapperInterface::class)->findAcrossAllSources(
				identifier: $candidate['uuid'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (Throwable $e) {
			return $this->uncheckable(logMessage: self::LOG_OWNER_UNRESOLVED, context: $target->logContext(failure: $e));
		}

		return $this->ownerOfFolder(found: $found, candidate: $candidate);
	}//end owningObjectOf()

	/**
	 * The found object, when its own `folder` is the candidate folder.
	 *
	 * @param array<string,mixed>                       $found     The lookup result.
	 * @param array{uuid: string, folderId: int|string} $candidate The ancestor folder named like a UUID.
	 *
	 * @return ObjectEntity|null The owning object, or null when the folder is not its own.
	 */
	private function ownerOfFolder(array $found, array $candidate): ?ObjectEntity {
		$object = ($found['object'] ?? null);
		if ($object instanceof ObjectEntity === false) {
			return null;
		}

		if ((string)$object->getFolder() !== (string)$candidate['folderId']) {
			return null;
		}

		return $object;
	}//end ownerOfFolder()

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
	 * @param Node $node The node about to change.
	 *
	 * @return array{node: Node, exact: bool}|null The twin in the home, or null when this node is not one of the app's files.
	 *
	 * @throws Throwable When the home cannot be searched (the caller refuses).
	 */
	private function homeNodeOf(Node $node): ?array {
		$stored = $this->nearestStored(node: $node);
		if ($stored === null) {
			return null;
		}

		return $this->twinInHome(stored: $stored);
	}//end homeNodeOf()

	/**
	 * The node itself or, when it is not stored yet, its nearest existing ancestor, with its owner and id.
	 *
	 * @param Node $node The node about to change.
	 *
	 * @return array{owner: \OCP\IUser|null, id: int, exact: bool}|null The stored node's owner and id; null when none is found.
	 */
	private function nearestStored(Node $node): ?array {
		$current = $node;
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			try {
				return ['owner' => $current->getOwner(), 'id' => $current->getId(), 'exact' => ($depth === 0)];
			} catch (Throwable $e) {
				// Not stored yet: the nearest existing ancestor decides.
				$current = $this->parentOf(node: $current);
			}

			if ($current === null) {
				return null;
			}
		}

		return null;
	}//end nearestStored()

	/**
	 * The stored node's twin in the `openregister` home, when the app account owns it.
	 *
	 * @param array{owner: \OCP\IUser|null, id: int, exact: bool} $stored The stored node's owner and id.
	 *
	 * @return array{node: Node, exact: bool}|null The twin, or null when the node is not one of the app's files.
	 */
	private function twinInHome(array $stored): ?array {
		if ($stored['owner']?->getUID() !== FileOwnershipHandler::APP_USER) {
			return null;
		}

		$twins = $this->rootFolder->getUserFolder(FileOwnershipHandler::APP_USER)->getById($stored['id']);
		foreach ($twins as $twin) {
			if (str_starts_with($twin->getPath(), self::HOME) === true) {
				return ['node' => $twin, 'exact' => $stored['exact']];
			}
		}

		return null;
	}//end twinInHome()

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
		$first = $node;
		if ($includeSelf === false) {
			$first = $this->parentOf(node: $node);
		}

		foreach ($this->ancestry(first: $first) as $folder) {
			$verdict = $this->ownerFolderVerdict(folder: $folder);
			if ($verdict !== false) {
				return $verdict;
			}
		}

		return null;
	}//end candidateOwnerFolder()

	/**
	 * The folders the owner search visits, from the first upwards, at most MAX_DEPTH of them.
	 *
	 * A generator, so a parent is only read when the search goes on climbing.
	 *
	 * @param Node|null $first The first folder, or null when there is none.
	 *
	 * @return Generator<int, Node> The folders, nearest first; it ends at a parent that cannot be read.
	 */
	private function ancestry(?Node $first): Generator {
		$current = $first;
		for ($depth = 1; $current !== null; $depth++) {
			yield $current;
			if ($depth === self::MAX_DEPTH) {
				return;
			}

			$current = $this->parentOf(node: $current);
		}
	}//end ancestry()

	/**
	 * Whether this folder ends the owner search, and with what.
	 *
	 * @param Node $folder A folder on the way up.
	 *
	 * @return array{uuid: string, folderId: int}|false|null The candidate when the folder is named like a UUID;
	 *     null when the search stops without one; false to climb on.
	 */
	private function ownerFolderVerdict(Node $folder): array|false|null {
		try {
			$name = $folder->getName();
		} catch (Throwable $e) {
			return null;
		}

		if (UuidFormat::isCanonical(value: $name) === true) {
			return $this->ownerFolderCandidate(folder: $folder, uuid: $name);
		}

		if ($name === 'files' || $name === '') {
			return null;
		}

		return false;
	}//end ownerFolderVerdict()

	/**
	 * A folder named like a UUID as an owner candidate.
	 *
	 * @param Node   $folder The folder.
	 * @param string $uuid   Its name.
	 *
	 * @return array{uuid: string, folderId: int}|null The candidate, or null when the folder has no id.
	 */
	private function ownerFolderCandidate(Node $folder, string $uuid): ?array {
		try {
			return ['uuid' => $uuid, 'folderId' => (int)$folder->getId()];
		} catch (Throwable $e) {
			return null;
		}
	}//end ownerFolderCandidate()

	/**
	 * The node's parent, or null when it cannot be read.
	 *
	 * @param Node $node The node.
	 *
	 * @return Node|null The parent.
	 */
	private function parentOf(Node $node): ?Node {
		try {
			return $node->getParent();
		} catch (Throwable $e) {
			return null;
		}
	}//end parentOf()
}//end class
