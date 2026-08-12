<?php

/**
 * ShareLinkService — Tier-2 shares integration service.
 *
 * Composes `OCP\Share\IManager` (NC core sharing — the single source of
 * truth) with the OR `ObjectEntityMapper` + `FolderManagementHandler`
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
 * `FolderManagementHandler`, `ObjectEntityMapper` and `IUserSession`
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

use DateTime;
use DateTimeInterface;
use Exception;
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
class ShareLinkService {

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
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface|null $container Optional server-container override (tests only).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		?ContainerInterface $container = null,
	) {
		$this->container = $container;
	}//end __construct()

	/**
	 * List shares linked to an OR object.
	 *
	 * Walks the object's NC folder (resolved via the H-1 entity-input
	 * branch of {@see FolderManagementHandler::getObjectFolder()}) and
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) getLinkedShares() walks six share types per folder
	 * node with per-type Throwable swallow and per-share deduplication; each branch is a required
	 * degradation guard per the AD-23 graceful-degradation contract.
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

		$rows = [];
		$seen = [];
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($count >= self::MAX_NODES) {
				break;
			}

			$count++;

			foreach (self::SHARE_TYPES as $type) {
				try {
					$shares = $shareManager->getSharesBy($userId, $type, $node);
				} catch (Throwable $e) {
					continue;
				}

				foreach ($shares as $share) {
					$row = $this->normaliseShare(share: $share, userId: $userId);
					if ($row === null) {
						continue;
					}

					$id = $row['shareId'];
					if (isset($seen[$id]) === true) {
						continue;
					}

					$seen[$id] = true;
					$rows[] = $row;
				}
			}//end foreach
		}//end foreach

		return $rows;
	}//end getLinkedShares()

	/**
	 * Create a share on a file inside the object's folder.
	 *
	 * The target `fileId` MUST resolve to a node within the object's
	 * folder — this is the ownership boundary that keeps OR from
	 * minting shares on arbitrary files. `IManager::newShare()` builds
	 * the prototype, which is then populated and handed to
	 * `IManager::createShare()`.
	 *
	 * @param string $objectUuid Owning object uuid.
	 * @param int $registerId OR register id (reserved — folder is per-object).
	 * @param int $schemaId OR schema id (reserved).
	 * @param int $fileId Target file node id (must be in the object's folder).
	 * @param int $shareType IShare::TYPE_* (user/group/link/email).
	 * @param string|null $shareWith Recipient uid/gid/email; null for public link.
	 * @param int $permissions Permission bitmask.
	 * @param string|null $password Optional password (public/email shares).
	 * @param string|null $expiration Optional ISO-8601 expiration date.
	 *
	 * @return IShare The created share.
	 *
	 * @throws Exception On missing user (401), file-not-in-folder (404),
	 *                   invalid recipient (400) or share-manager failure.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) createShare() maps directly to
	 * IManager::createShare()'s required fields (node, shareType, sharedBy, permissions, shareWith,
	 * password, expiration); bundling into a value-object would add an intermediate type not used elsewhere.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)   createShare() guards share type validity, shareWith
	 * requirement per type, password/expiration optional fields, and ownership boundary
	 * (node-in-folder check); each branch enforces a distinct access-control requirement.
	 * @SuppressWarnings(PHPMD.NPathComplexity)        Optional password + optional expiration + three share-type
	 * branches that require shareWith produce many independent paths; all enforce the
	 * IManager::createShare contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)  $registerId and $schemaId are reserved for future
	 * folder-scoping; removing them now would break the method signature used by the controller
	 * and SharesProvider.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-share contract is owned by the
	 *              integration-shares / generic-integrations capability.
	 */
	public function createShare(
		string $objectUuid,
		int $registerId,
		int $schemaId,
		int $fileId,
		int $shareType,
		?string $shareWith,
		int $permissions,
		?string $password = null,
		?string $expiration = null,
	): IShare {
		$shareManager = $this->getShareManager();
		$userId = $this->resolveCurrentUserId();
		if ($userId === null) {
			throw new Exception('No user logged in', 401);
		}

		$node = $this->resolveNodeInFolder(objectUuid: $objectUuid, fileId: $fileId);
		if ($node === null) {
			throw new Exception('File does not belong to this object', 404);
		}

		if (in_array($shareType, self::SHARE_TYPES, true) === false) {
			throw new Exception('Unsupported share type', 400);
		}

		if (($shareType === IShare::TYPE_USER
			|| $shareType === IShare::TYPE_GROUP
			|| $shareType === IShare::TYPE_EMAIL)
			&& ($shareWith === null || trim($shareWith) === '')
		) {
			throw new Exception('shareWith is required for this share type', 400);
		}

		$share = $shareManager->newShare();
		$share->setNode($node);
		$share->setShareType($shareType);
		$share->setSharedBy($userId);
		$share->setPermissions($permissions);

		if ($shareWith !== null && trim($shareWith) !== '') {
			$share->setSharedWith(trim($shareWith));
		}

		if ($password !== null && trim($password) !== '') {
			$share->setPassword($password);
		}

		if ($expiration !== null && trim($expiration) !== '') {
			try {
				$share->setExpirationDate(new DateTime($expiration));
			} catch (Throwable $e) {
				throw new Exception('Invalid expiration date', 400);
			}
		}

		try {
			return $shareManager->createShare($share);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ShareLinkService::createShare failed: ' . $e->getMessage(),
				['app' => 'openregister']
			);
			throw new Exception($e->getMessage(), 400);
		}
	}//end createShare()

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
	 * @throws Exception On missing user (401), share-not-found (404),
	 *                   ownership mismatch (403).
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

		try {
			$share = $shareManager->getShareById($shareId);
		} catch (Throwable $e) {
			throw new Exception('Share not found', 404);
		}

		$folder = $this->resolveObjectFolder(objectUuid: $objectUuid);
		if ($folder === null || $this->shareIsInFolder(share: $share, folder: $folder) === false) {
			throw new Exception('Share does not belong to this object', 403);
		}

		$shareManager->deleteShare($share);
	}//end revokeShare()

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
		foreach ($folder->getDirectoryListing() as $node) {
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
	 * Resolve the object's NC folder via FolderManagementHandler,
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
				serviceName: 'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler'
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

		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($count >= self::MAX_NODES) {
				break;
			}

			$count++;

			if ((int)$node->getId() === $fileId) {
				return $node;
			}
		}

		return null;
	}//end resolveNodeInFolder()

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
		$count = 0;
		foreach ($folder->getDirectoryListing() as $child) {
			if ($count >= self::MAX_NODES) {
				break;
			}

			$count++;

			if ((int)$child->getId() === $nodeId) {
				return true;
			}
		}

		return false;
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
				serviceName: 'OCA\\OpenRegister\\Db\\ObjectEntityMapper'
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
			$expiration = null;
			$expDate = $share->getExpirationDate();
			if ($expDate !== null) {
				$expiration = $expDate->format(DateTimeInterface::ATOM);
			}

			$stime = $share->getShareTime();
			$createdAt = null;
			if ($stime !== null) {
				$createdAt = $stime->format(DateTimeInterface::ATOM);
			}

			$owner = (string)($share->getSharedBy() ?? '');
			$node = $share->getNode();
			$fileName = '';
			$fileId = 0;
			if ($node !== null) {
				$fileName = (string)$node->getName();
				$fileId = (int)$node->getId();
			}

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
					Server::get($id);
					return true;
				} catch (Throwable $e) {
					return false;
				}
			}//end has()
		};
	}//end resolveContainer()
}//end class
