<?php

/**
 * ShareLinkService — Tier-2 shares integration service.
 *
 * Composes `OCP\Share\IManager` (NC core sharing — the single source of
 * truth) with the OR `MagicMapper` + `FileService`
 * so the registry "Shares" leaf can list, create and revoke shares on
 * the files inside an object's NC folder.
 *
 * NO CACHE / NO LINK TABLE: `IShare` is first-class NC core state and
 * mutates outside OpenRegister (users open the Files share panel
 * directly). Any OR-side snapshot table would desync immediately, so
 * every read/write goes straight through `IManager`. This is the
 * deliberate divergence from the polls/flow/talk Tier-2 services, which
 * own a private link table because their upstream apps expose no
 * per-object query surface.
 *
 * Surface:
 *   - getLinkedShares(objectUuid)                       — list (H-1 folder-resolve)
 *   - createShare(objectUuid, registerId, schemaId,
 *                 fileId, shareType, shareWith?,
 *                 permissions, password?, expiration?)  — IManager::createShare
 *   - revokeShare(objectUuid, shareId)                  — IManager::deleteShare (ownership-checked)
 *   - getShareableFiles(objectUuid)                     — files in the object's folder
 *
 * Lazy-resolution policy mirrors {@see SharesProvider}: `IManager`,
 * `FileService`, `MagicMapper` and `IUserSession`
 * are pulled from the server container on demand so the ctor stays a
 * plain `(IL10N, LoggerInterface, ?ContainerInterface)` and unit tests
 * inject a mock container.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeInterface;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ShareLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Service references IManager, IShare, Folder, Node,
 * IL10N, LoggerInterface, ContainerInterface, DateTime, DateTimeInterface, OCP\Server, and Throwable —
 * every type is required for the share list/create/revoke contract or the lazy-resolution policy.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Service exposes
 * getLinkedShares/createShare/revokeShare/getShareableFiles plus internal resolution helpers
 * (getShareManager, resolveObjectFolder, resolveNodeInFolder, normaliseShare, shareIsInFolder,
 * resolveCurrentUserId, resolveObjectEntity, lookup, resolveContainer); each is a required step
 * of a distinct public flow.
 */
class ShareLinkService implements ShareLinkServiceInterface {

	/**
	 * Share types the service surfaces when listing. Mirrors
	 * {@see SharesProvider::SHARE_TYPES} so the registry tab and this
	 * service agree on which shares belong to the object.
	 *
	 * @var int[]
	 */
	private const SHARE_TYPES = [
		IShare::TYPE_USER,
		IShare::TYPE_GROUP,
		IShare::TYPE_LINK,
		IShare::TYPE_EMAIL,
		IShare::TYPE_REMOTE,
		IShare::TYPE_REMOTE_GROUP,
	];

	/**
	 * Maximum nodes inside the object's folder to walk when listing or
	 * collecting shareable files. Matches the provider's bound so deep
	 * object folders don't hammer the share manager.
	 *
	 * @var int
	 */
	private const MAX_NODES = 50;

	/**
	 * Optional server-container override (tests only). Production uses
	 * NC's global `\OCP\Server` container via {@see resolveContainer()}.
	 *
	 * @var ContainerInterface|null
	 */
	private ?ContainerInterface $container;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface|null $container Optional server-container override (tests only).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		?ContainerInterface $container = null,
	) {
		$this->container = $container;
	}//end __construct()

	/**
	 * List shares linked to an OR object.
	 *
	 * Walks the object's NC folder (resolved via the H-1 entity-input
	 * branch of {@see FileService::getObjectFolder()}) and
	 * unions shares across the object's files for every relevant share
	 * type, deduplicated by share id.
	 *
	 * @param string $objectUuid Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>> Normalised share rows.
	 *
	 * @throws Exception When the share subsystem is unreachable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the shares-listing contract is owned by the
	 *              integration-shares / generic-integrations capability.
	 *
	 */
	public function getLinkedShares(string $objectUuid): array {
		$shareManager = $this->getShareManager();
		$userId = $this->resolveCurrentUserId();
		if ($userId === null) {
			throw new Exception('No user logged in', 401);
		}

		$folder = $this->resolveObjectFolder(objectUuid: $objectUuid);
		if ($folder === null) {
			return [];
		}

		$run = new ShareListingRun(manager: $shareManager, sharers: $this->sharersOf(userId: $userId, objectUuid: $objectUuid));

		$rows = [];
		$count = 0;
		foreach ($this->readableChildren(folder: $folder) as $node) {
			if ($count >= self::MAX_NODES) {
				break;
			}

			$count++;

			$rows = array_merge(
				$rows,
				$this->newRowsForNode(run: $run, node: $node)
			);
		}//end foreach

		return $rows;
	}//end getLinkedShares()

	/**
	 * Whose shares are listed: the caller's, and the app account's when the caller may update the object.
	 *
	 * @param string $userId     The caller's uid.
	 * @param string $objectUuid Owning object uuid.
	 *
	 * @return array<int, string> The sharers' uids.
	 */
	private function sharersOf(string $userId, string $objectUuid): array {
		$sharers = [$userId];
		$systemUserId = $this->systemUserId();
		if ($systemUserId !== null && $this->mayUpdateObject(entity: $this->resolveObjectEntity(objectUuid: $objectUuid)) === true) {
			$sharers[] = $systemUserId;
		}

		return $sharers;
	}//end sharersOf()

	/**
	 * The normalised rows of one node's shares not seen before, marking them seen.
	 *
	 * @param ShareListingRun $run  The share manager, the sharers and the share ids already listed (extended).
	 * @param Node            $node The node.
	 *
	 * @return array<int, array<string, mixed>> The new rows.
	 */
	private function newRowsForNode(ShareListingRun $run, Node $node): array {
		$rows = [];
		foreach ($this->sharesOn(run: $run, node: $node) as [$sharer, $share]) {
			$row = $this->normaliseShare(share: $share, userId: $sharer);
			if ($row === null || isset($run->seen[$row['shareId']]) === true) {
				continue;
			}

			$run->seen[$row['shareId']] = true;
			$rows[] = $row;
		}

		return $rows;
	}//end newRowsForNode()

	/**
	 * The shares each sharer made of a node, across the supported types, in sharer order.
	 *
	 * A type the share manager fails on is skipped.
	 *
	 * @param ShareListingRun $run  The share manager and the sharers.
	 * @param Node            $node The node.
	 *
	 * @return array<int, array{0: string, 1: IShare}> Each share with the uid of its sharer.
	 */
	private function sharesOn(ShareListingRun $run, Node $node): array {
		$found = [];
		foreach ($run->sharers as $sharer) {
			foreach (self::SHARE_TYPES as $type) {
				try {
					$shares = $run->manager->getSharesBy($sharer, $type, $node);
				} catch (Throwable $e) {
					continue;
				}

				foreach ($shares as $share) {
					$found[] = [$sharer, $share];
				}
			}
		}

		return $found;
	}//end sharesOn()

	/**
	 * The uid of the app's account, which holds the objects' files and shares them.
	 *
	 * @return string|null The uid, or null when the account cannot be reached.
	 */
	private function systemUserId(): ?string {
		try {
			return $this->lookup(serviceName: 'OCA\\OpenRegister\\Service\\FileService')?->getUser()->getUID();
		} catch (Throwable $e) {
			return null;
		}
	}//end systemUserId()

	/**
	 * Whether the caller holds the `update` right editing the object requires.
	 *
	 * @param object|null $entity The object.
	 *
	 * @return bool True when they do; false too when it cannot be asked.
	 */
	private function mayUpdateObject(?object $entity): bool {
		$permissions = $this->lookup(serviceName: 'OCA\\OpenRegister\\Service\\Object\\PermissionHandlerInterface');
		if (($entity instanceof ObjectEntity) === false || $permissions === null) {
			return false;
		}

		return $this->askUpdateRight(permissions: $permissions, entity: $entity);
	}//end mayUpdateObject()

	/**
	 * Ask the permission handler for the update right on the object; false when it cannot answer.
	 *
	 * False too when the schema mapper cannot be reached.
	 *
	 * @param object       $permissions The permission handler.
	 * @param ObjectEntity $entity      The object.
	 *
	 * @return bool True when the caller holds the right.
	 */
	private function askUpdateRight(object $permissions, ObjectEntity $entity): bool {
		$schemas = $this->lookup(serviceName: 'OCA\\OpenRegister\\Db\\SchemaMapper');
		if ($schemas === null) {
			return false;
		}

		try {
			return $permissions->hasPermission(
				ask: (new PermissionAsk(schema: $schemas->find((int)$entity->getSchema()), action: 'update'))
					->askedBy(userId: null, objectOwner: $entity->getOwner())
					->about(object: $entity, register: null)
			);
		} catch (Throwable $e) {
			return false;
		}
	}//end askUpdateRight()

	/**
	 * Put a share on the object's audit trail under the session user.
	 *
	 * The share itself is made by the app's account, so this is the only record of who asked.
	 * A failed entry is logged: the share has already changed.
	 *
	 * @param object|null $entity The object.
	 * @param IShare      $share  The created share.
	 *
	 * @return void
	 */
	private function auditGrant(?object $entity, IShare $share): void {
		try {
			$this->auditorFor(entity: $entity)?->recordGrant(object: $entity, grant: $this->grantOf(share: $share), admittedAs: 'update');
		} catch (Throwable $e) {
			$this->logUnaudited(error: $e);
		}
	}//end auditGrant()

	/**
	 * Put a revoked share on the object's audit trail under the session user.
	 *
	 * A failed entry is logged: the share has already been deleted.
	 *
	 * @param object|null $entity The object.
	 * @param IShare      $share  The revoked share.
	 *
	 * @return void
	 */
	private function auditRevoke(?object $entity, IShare $share): void {
		try {
			$this->auditorFor(entity: $entity)?->recordRevoke(object: $entity, grant: $this->grantOf(share: $share), admittedAs: 'update');
		} catch (Throwable $e) {
			$this->logUnaudited(error: $e);
		}
	}//end auditRevoke()

	/**
	 * The grant-change auditor, or null when there is nothing to audit against or no auditor.
	 *
	 * @param object|null $entity The object.
	 *
	 * @return object|null The auditor, only for an ObjectEntity.
	 */
	private function auditorFor(?object $entity): ?object {
		$auditor = $this->lookup(serviceName: 'OCA\\OpenRegister\\Service\\Rbac\\GrantChangeAuditor');
		if (($entity instanceof ObjectEntity) === false) {
			return null;
		}

		return $auditor;
	}//end auditorFor()

	/**
	 * The grant the audit trail records for a share.
	 *
	 * @param IShare $share The share.
	 *
	 * @return array{id: string, type: string, sharedWith: string|null, permissions: int}
	 */
	private function grantOf(IShare $share): array {
		return [
			'id' => $share->getId(),
			'type' => (string)$share->getShareType(),
			'sharedWith' => $share->getSharedWith(),
			'permissions' => $share->getPermissions(),
		];
	}//end grantOf()

	/**
	 * Log a share change the audit trail did not take.
	 *
	 * @param Throwable $error Why the entry failed.
	 *
	 * @return void
	 */
	private function logUnaudited(Throwable $error): void {
		$this->logger->error(
			'ShareLinkService: a share change was not audited: ' . $error->getMessage(),
			['app' => 'openregister']
		);
	}//end logUnaudited()

	/**
	 * Create a share on a file inside the object's folder.
	 *
	 * The target `fileId` MUST resolve to a node within the object's
	 * folder — this is the ownership boundary that keeps OR from
	 * minting shares on arbitrary files. `IManager::newShare()` builds
	 * the prototype, which is then populated and handed to
	 * `IManager::createShare()`.
	 *
	 * @param string     $objectUuid Owning object uuid.
	 * @param ShareDraft $draft      File (must be in the object's folder), share type, recipient,
	 *                               permissions and optional password and expiration.
	 *
	 * @return IShare The created share.
	 *
	 * @throws Exception On missing user (401), file-not-in-folder (404),
	 *                   invalid recipient (400) or share-manager failure.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-share contract is owned by the
	 *              integration-shares / generic-integrations capability.
	 */
	public function createShare(string $objectUuid, ShareDraft $draft): IShare {
		$shareManager = $this->getShareManager();
		$userId = $this->resolveCurrentUserId();
		if ($userId === null) {
			throw new Exception('No user logged in', 401);
		}

		$target = $this->shareTarget(objectUuid: $objectUuid, draft: $draft);

		$entity = $this->resolveObjectEntity(objectUuid: $objectUuid);
		if ($this->mayUpdateObject(entity: $entity) === false) {
			throw new Exception('You may not change this object, so you cannot share its files.', 403);
		}

		$sharer = $this->systemUserId();
		if ($sharer === null) {
			throw new Exception('The OpenRegister account is not available', 503);
		}

		$share = $this->buildShare(request: $target, sharer: $sharer);

		try {
			$created = $shareManager->createShare($share);
			$this->auditGrant(entity: $entity, share: $created);
			return $created;
		} catch (Throwable $e) {
			$this->logger->warning(
				'ShareLinkService::createShare failed: ' . $e->getMessage(),
				['app' => 'openregister']
			);
			throw new Exception($e->getMessage(), 400);
		}
	}//end createShare()

	/**
	 * The node and share type a draft targets, refused unless the node is in the object's folder and the draft is complete.
	 *
	 * @param string     $objectUuid Owning object uuid.
	 * @param ShareDraft $draft      The draft.
	 *
	 * @return ShareRequest The node to share, the supported type and the draft.
	 *
	 * @throws Exception On file-not-in-folder (404), unsupported type or missing recipient (400).
	 */
	private function shareTarget(string $objectUuid, ShareDraft $draft): ShareRequest {
		$node = $this->resolveNodeInFolder(objectUuid: $objectUuid, fileId: $draft->fileId);
		if ($node === null) {
			throw new Exception('File does not belong to this object', 404);
		}

		$shareType = $this->supportedShareType(draft: $draft);

		if ($draft->lacksRecipient() === true) {
			throw new Exception('shareWith is required for this share type', 400);
		}

		return new ShareRequest(node: $node, shareType: $shareType, draft: $draft);
	}//end shareTarget()

	/**
	 * The draft's share type, when it is one of SHARE_TYPES.
	 *
	 * @param ShareDraft $draft The draft.
	 *
	 * @return value-of<self::SHARE_TYPES> The supported type.
	 *
	 * @throws Exception On an unsupported type (400).
	 */
	private function supportedShareType(ShareDraft $draft): int {
		foreach (self::SHARE_TYPES as $type) {
			if ($type === $draft->shareType) {
				return $type;
			}
		}

		throw new Exception('Unsupported share type', 400);
	}//end supportedShareType()

	/**
	 * Build the share prototype from the draft, made by the app's account.
	 *
	 * @param ShareRequest $request The node, type and draft from shareTarget().
	 * @param string       $sharer  The app account's uid.
	 *
	 * @return IShare The populated, not yet created, share.
	 *
	 * @throws Exception When the expiration date cannot be parsed (400).
	 */
	private function buildShare(ShareRequest $request, string $sharer): IShare {
		$share = $this->getShareManager()->newShare();
		$share->setNode($request->node);
		$share->setShareType($request->shareType);
		$share->setSharedBy($sharer);
		$share->setPermissions($request->draft->permissions());

		$this->applyOptionalFields(share: $share, draft: $request->draft);

		return $share;
	}//end buildShare()

	/**
	 * Set the draft's recipient, password and expiration date on the share, each only when given.
	 *
	 * @param IShare     $share The share being built.
	 * @param ShareDraft $draft The draft.
	 *
	 * @return void
	 *
	 * @throws Exception When the expiration date cannot be parsed (400).
	 */
	private function applyOptionalFields(IShare $share, ShareDraft $draft): void {
		if ($draft->recipient() !== null) {
			$share->setSharedWith($draft->recipient());
		}

		if ($draft->password() !== null) {
			$share->setPassword($draft->password());
		}

		$expiresAt = $draft->expiresAt();
		if ($expiresAt !== null) {
			$share->setExpirationDate($expiresAt);
		}
	}//end applyOptionalFields()

	/**
	 * Revoke a share by id, ownership-checked.
	 *
	 * The share's backing node MUST live inside the object's folder,
	 * otherwise the request is rejected with 404 — this prevents
	 * revoking a share through an unrelated object's endpoint.
	 *
	 * @param string $objectUuid Owning object uuid.
	 * @param string $shareId Share id to revoke.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401), or 404 when the share does not exist,
	 *                   lies outside the object's folder or is not the caller's.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the revoke-share contract is owned by the
	 *              integration-shares / generic-integrations capability.
	 */
	public function revokeShare(string $objectUuid, string $shareId): void {
		$shareManager = $this->getShareManager();
		$userId = $this->resolveCurrentUserId();
		if ($userId === null) {
			throw new Exception('No user logged in', 401);
		}

		$share = $this->shareInObjectFolder(objectUuid: $objectUuid, shareId: $shareId);

		$entity = $this->resolveObjectEntity(objectUuid: $objectUuid);
		if ($this->mayRevoke(share: $share, entity: $entity) === false) {
			throw new Exception('Share not found', 404);
		}

		$shareManager->deleteShare($share);
		$this->auditRevoke(entity: $entity, share: $share);
	}//end revokeShare()

	/**
	 * The share by id, refused unless its node lives inside the object's folder.
	 *
	 * @param string $objectUuid Owning object uuid.
	 * @param string $shareId    Share id.
	 *
	 * @return IShare The share.
	 *
	 * @throws Exception 404 when the share does not exist or lies outside the object's folder.
	 */
	private function shareInObjectFolder(string $objectUuid, string $shareId): IShare {
		try {
			$share = $this->getShareManager()->getShareById($shareId);
		} catch (Throwable $e) {
			throw new Exception('Share not found', 404);
		}

		$folder = $this->resolveObjectFolder(objectUuid: $objectUuid);
		if ($folder === null || $this->shareIsInFolder(share: $share, folder: $folder) === false) {
			throw new Exception('Share not found', 404);
		}

		return $share;
	}//end shareInObjectFolder()

	/**
	 * Whether the caller may revoke the share: their own, or the app account's with the update right.
	 *
	 * Both questions are always asked, in this order. The caller is the session user.
	 *
	 * @param IShare      $share  The share.
	 * @param object|null $entity The object.
	 *
	 * @return bool True when the caller may revoke it.
	 */
	private function mayRevoke(IShare $share, ?object $entity): bool {
		$userId    = $this->resolveCurrentUserId();
		$isCallers = ($share->getSharedBy() === $userId || $share->getShareOwner() === $userId);
		$isManaged = ($share->getSharedBy() === $this->systemUserId() && $this->mayUpdateObject(entity: $entity) === true);

		return $isCallers === true || $isManaged === true;
	}//end mayRevoke()

	/**
	 * List the files inside an object's folder that can be shared.
	 *
	 * @param string $objectUuid Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>> File descriptors `{fileId, fileName, mimetype, size}`.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the shareable-files listing contract is owned by
	 *              the integration-shares / generic-integrations capability.
	 */
	public function getShareableFiles(string $objectUuid): array {
		$folder = $this->resolveObjectFolder(objectUuid: $objectUuid);
		if ($folder === null) {
			return [];
		}

		$files = [];
		$count = 0;
		foreach ($this->readableChildren(folder: $folder) as $node) {
			if ($count >= self::MAX_NODES) {
				break;
			}

			$count++;

			if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
				continue;
			}

			$files[] = [
				'fileId' => (int)$node->getId(),
				'fileName' => (string)$node->getName(),
				'mimetype' => (string)$node->getMimetype(),
				'size' => (int)$node->getSize(),
			];
		}

		return $files;
	}//end getShareableFiles()

	/**
	 * Resolve the NC share manager or throw a 503.
	 *
	 * @return IManager
	 *
	 * @throws Exception When the share subsystem is unreachable (503).
	 */
	private function getShareManager(): IManager {
		$manager = $this->lookup(serviceName: 'OCP\\Share\\IManager');
		if (($manager instanceof IManager) === false) {
			throw new Exception('NC share manager is unreachable', 503);
		}

		return $manager;
	}//end getShareManager()

	/**
	 * The folder's children the caller may read, by the file restrictions.
	 *
	 * Fails closed: without the guard nothing is listed.
	 *
	 * @param Folder $folder The folder.
	 *
	 * @return array<int, Node> The readable children.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
	 */
	private function readableChildren(Folder $folder): array {
		$guard = $this->lookup(serviceName: FileRestrictionGuardInterface::class);
		if ($guard === null) {
			return [];
		}

		return $guard->readableNodes(nodes: $folder->getDirectoryListing());
	}//end readableChildren()

	/**
	 * Resolve the object's NC folder via FileService,
	 * passing the resolved ObjectEntity so the handler uses its
	 * entity-input branch (the H-1 fix — string input throws "no
	 * objectEntity or registerId given").
	 *
	 * @param string $objectUuid Owning object uuid.
	 *
	 * @return Folder|null
	 */
	private function resolveObjectFolder(string $objectUuid): ?Folder {
		try {
			$handler = $this->lookup(
				serviceName: 'OCA\\OpenRegister\\Service\\FileService'
			);
			if ($handler === null) {
				return null;
			}

			$entity = $this->resolveObjectEntity(objectUuid: $objectUuid);
			$folder = $handler->getObjectFolder($entity ?? $objectUuid);
			if (($folder instanceof Folder) === true) {
				return $folder;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end resolveObjectFolder()

	/**
	 * Resolve a file node inside the object's folder by file id.
	 *
	 * @param string $objectUuid Owning object uuid.
	 * @param int $fileId Target file node id.
	 *
	 * @return Node|null Node when present in the folder, else null.
	 */
	private function resolveNodeInFolder(string $objectUuid, int $fileId): ?Node {
		$folder = $this->resolveObjectFolder(objectUuid: $objectUuid);
		if ($folder === null) {
			return null;
		}

		return $this->firstNodeWithId(nodes: $this->readableChildren(folder: $folder), nodeId: $fileId);
	}//end resolveNodeInFolder()

	/**
	 * The node with the given id among the first MAX_NODES nodes.
	 *
	 * @param array<int, Node> $nodes  The nodes, in listing order.
	 * @param int              $nodeId The id to find.
	 *
	 * @return Node|null The node, or null when not among the first MAX_NODES.
	 */
	private function firstNodeWithId(array $nodes, int $nodeId): ?Node {
		$count = 0;
		foreach ($nodes as $node) {
			if ($count >= self::MAX_NODES) {
				break;
			}

			$count++;

			if ((int)$node->getId() === $nodeId) {
				return $node;
			}
		}

		return null;
	}//end firstNodeWithId()

	/**
	 * Whether a share's backing node lives inside the given folder.
	 *
	 * @param IShare $share Share to check.
	 * @param Folder $folder Object folder.
	 *
	 * @return bool
	 */
	private function shareIsInFolder(IShare $share, Folder $folder): bool {
		try {
			$node = $share->getNode();
		} catch (Throwable $e) {
			return false;
		}

		if ($node === null) {
			return false;
		}

		$nodeId = (int)$node->getId();
		return $this->firstNodeWithId(nodes: $folder->getDirectoryListing(), nodeId: $nodeId) !== null;
	}//end shareIsInFolder()

	/**
	 * Look up an ObjectEntity by uuid via the lazy container.
	 *
	 * @param string $objectUuid Object uuid.
	 *
	 * @return object|null
	 */
	private function resolveObjectEntity(string $objectUuid): ?object {
		try {
			$mapper = $this->lookup(
				serviceName: 'OCA\\OpenRegister\\Db\\MagicMapper'
			);
			if ($mapper === null) {
				return null;
			}

			$entity = $mapper->find($objectUuid);
			if (is_object($entity) === true) {
				return $entity;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end resolveObjectEntity()

	/**
	 * Resolve the current user's UID via IUserSession.
	 *
	 * @return string|null
	 */
	private function resolveCurrentUserId(): ?string {
		try {
			$session = $this->lookup(serviceName: 'OCP\\IUserSession');
			if ($session === null) {
				return null;
			}

			$user = $session->getUser();
			if ($user === null) {
				return null;
			}

			return $user->getUID();
		} catch (Throwable $e) {
			return null;
		}
	}//end resolveCurrentUserId()

	/**
	 * Normalise an `IShare` into the leaf-row contract.
	 *
	 * @param IShare $share Share to normalise.
	 * @param string $userId Current user uid (drives `canRevoke`).
	 *
	 * @return array<string,mixed>|null
	 */
	private function normaliseShare(IShare $share, string $userId): ?array {
		try {
			$shareId = (string)$share->getId();
			$shareType = (int)$share->getShareType();
			$sharedWith = (string)($share->getSharedWith() ?? '');
			$displayName = (string)($share->getSharedWithDisplayName() ?? $sharedWith);
			$permissions = (int)$share->getPermissions();
			$hasPassword = $share->getPassword() !== null && $share->getPassword() !== '';
			$expiration = $this->atomOrNull(date: $share->getExpirationDate());
			$createdAt = $this->atomOrNull(date: $share->getShareTime());

			$owner = (string)($share->getSharedBy() ?? '');
			// IShare::getNode() is declared to return Node and throws when the
			// node is gone, so the null guard here was unreachable.
			$node = $share->getNode();
			$fileName = (string)$node->getName();
			$fileId = (int)$node->getId();

			return [
				'shareId' => $shareId,
				'shareType' => $shareType,
				'shareWith' => $sharedWith,
				'shareWithDisplayName' => $displayName,
				'permissions' => $permissions,
				'passwordProtected' => $hasPassword,
				'expiration' => $expiration,
				'createdAt' => $createdAt,
				'fileId' => $fileId,
				'fileName' => $fileName,
				'canRevoke' => $owner === $userId,
			];
		} catch (Throwable $e) {
			return null;
		}//end try
	}//end normaliseShare()

	/**
	 * A date as an ATOM string, or null.
	 *
	 * @param DateTimeInterface|null $date The date.
	 *
	 * @return string|null The formatted date.
	 */
	private function atomOrNull(?DateTimeInterface $date): ?string {
		if ($date === null) {
			return null;
		}

		return $date->format(DateTimeInterface::ATOM);
	}//end atomOrNull()

	/**
	 * Resolve a service from the container.
	 *
	 * @param string $serviceName Fully qualified class name to resolve.
	 *
	 * @return object|null
	 */
	private function lookup(string $serviceName): ?object {
		try {
			$container = $this->resolveContainer();
			$service = $container->get($serviceName);
			if (is_object($service) === true) {
				return $service;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end lookup()

	/**
	 * Resolve the active container — the test override if injected,
	 * otherwise NC's global server container.
	 *
	 * @return ContainerInterface
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) \OCP\Server::get() is the Nextcloud-prescribed static
	 * service-locator for optional late-bound dependencies; no injectable alternative exists for the
	 * lazy-resolution policy (constructor injection would fail when optional services are absent).
	 */
	private function resolveContainer(): ContainerInterface {
		if ($this->container !== null) {
			return $this->container;
		}

		// Thin PSR-11 adapter around `\OCP\Server::get()` so the rest
		// of the service can treat container lookups uniformly.
		return new class implements ContainerInterface {
			/**
			 * Resolve a service from the NC server container.
			 *
			 * @param string $id Service id.
			 *
			 * @return object
			 *
			 * @spec exclude Plumbing: PSR-11 adapter method around Server::get() on an inline anonymous container; no standalone behavioral contract.
			 */
			public function get(string $id): object {
				// phpcs:ignore CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- PSR-11 adapter shim: this IS the container being handed to a collaborator, so there is no container to inject into it.
				return Server::get($id);
			}//end get()

			/**
			 * Whether the NC server container can resolve a service.
			 *
			 * @param string $id Service id.
			 *
			 * @return bool
			 *
			 * @spec exclude Plumbing: PSR-11 adapter method around Server::get() on an inline anonymous container; no standalone behavioral contract.
			 */
			public function has(string $id): bool {
				try {
					// phpcs:ignore CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- PSR-11 adapter shim: this IS the container being handed to a collaborator, so there is no container to inject into it.
					Server::get($id);
					return true;
				} catch (Throwable $e) {
					return false;
				}
			}//end has()
		};
	}//end resolveContainer()
}//end class
