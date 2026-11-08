<?php

/**
 * SharesProvider — exposes NC Shares (file/folder shares, public
 * links, federated shares) linked to an OpenRegister object via
 * `OCP\Share\IManager::getSharesBy()`, scoped to the object's linked
 * files.
 *
 * Storage strategy is `query-time` — share state mutates outside OR
 * (users click the Files share panel), so any link-table snapshot would
 * desync immediately. The provider walks the object's NC folder
 * (resolved lazily via `FileService`) and unions shares
 * across files of every relevant share type (user / group / link /
 * federated), then deduplicates by share id.
 *
 * The earlier `MarkerLookupTrait`-on-`share.note` implementation has
 * been retired: NC core never seeds `share.note` from the OR side, so
 * the marker scan returned empty in production. The `OCP\Share\IManager`
 * pivot is what the proposal acceptance criteria assume; see
 * `openspec/changes/integration-shares/proposal.md` for the rationale.
 *
 * Lazy-resolution policy: `IManager`, `FileService`, and
 * `IUserSession` are pulled from the server container on demand rather
 * than constructor-injected, so the ctor signature
 * `(IDBConnection, IAppManager, IL10N)` stays compatible with the
 * existing `Application.php` registration. Tests inject a mock
 * `ContainerInterface` via the optional fourth ctor arg.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
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

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata;
// Getters mirror the contract in the interface.
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Shares (NC core) integration provider.
 *
 * Always-on metadata: id='shares', group='core', requiredApp=null
 * (NC core sharing is always available), storage='query-time'.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Provider exposes list/delete/health plus lazy
 * container resolution helpers (lookup, resolveContainer, resolveCurrentUserId, resolveObjectFolder,
 * normaliseShare, mapServiceRow); each serves a distinct phase of the query-time share-walk and
 * cannot be combined.
 * @SuppressWarnings(PHPMD.StaticAccess)             \OCP\Server::get() is the Nextcloud-prescribed static
 * service-locator pattern for optional late-bound dependencies; no injectable alternative exists
 * for the lazy-resolution policy (constructor injection would fail when optional services are absent).
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)      mapServiceRow() is used as a callable in
 * array_map([$this, 'mapServiceRow'], ...) — PHPMD does not trace callable-array references
 * and incorrectly flags it as unused.
 */
class SharesProvider extends AbstractIntegrationProvider {

	/**
	 * Share types the provider surfaces. Order matters: it drives
	 * `IManager::getSharesBy()` iteration so the assembled row list is
	 * predictable across calls.
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
	 * Maximum nodes inside the object's folder to query. The registry
	 * tab is a quick-look surface; deep object folders aren't expected
	 * for normal case files, and unbounded fan-out would hammer the
	 * share manager.
	 *
	 * @var int
	 */
	private const MAX_NODES = 50;

	/**
	 * Optional server container override. Defaults to NC's global
	 * `\OCP\Server` container; tests inject a mock so the IManager /
	 * FileService / IUserSession lookups are exerciseable
	 * without spinning up the full container.
	 *
	 * @var ContainerInterface|null
	 */
	private ?ContainerInterface $container;

	/**
	 * Constructor.
	 *
	 * Required args mirror the registration block in `Application.php`
	 * so the wiring there stays untouched. The optional container
	 * argument is for unit tests only; production uses
	 * `\OCP\Server::get(...)` via {@see resolveContainer()}.
	 *
	 * @param IDBConnection $db NC DB connection (unused at runtime since
	 *                          the IManager pivot owns query construction —
	 *                          kept for parity with the registration block).
	 * @param IAppManager $appManager NC app manager (unused at runtime: NC core
	 *                                sharing is always available — kept for
	 *                                registration-block parity).
	 * @param IL10N $l10n Localisation.
	 * @param ContainerInterface|null $container Optional server-container override (tests
	 *                                           only).
	 *
	 * @return void
	 */
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private IL10N $l10n,
		?ContainerInterface $container = null,
	) {
		$this->container = $container;
	}//end __construct()

	public function getId(): string {
		return 'shares';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Shares');
	}//end getLabel()

	public function getIcon(): string {
		return 'Share';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'core';
	}//end getGroup()

	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'query-time';
	}//end getStorageStrategy()

	/**
	 * Whether the integration is available — NC core sharing is always on.
	 *
	 * @return bool
	 *
	 * @spec exclude Trivial always-on predicate (`return true`) — the registry stage-1 enabled-filter contract
	 *              is owned by pluggable-integration-registry task-3.
	 */
	public function isEnabled(): bool {
		// NC core sharing is always available; the only "disabled"
		// case is the share subsystem being unreachable at runtime,
		// which the per-call Throwable catches surface as a degraded
		// empty list (see `list()` + `health()`).
		return true;
	}//end isEnabled()

	/**
	 * List shares linked to an OR object.
	 *
	 * Flow:
	 *   1. Resolve `IManager`, `FileService`, and the
	 *      current user's UID through the lazy container.
	 *   2. Resolve the object's NC folder via the handler.
	 *   3. Walk the folder's first {@see MAX_NODES} children and call
	 *      `IManager::getSharesBy()` per child per share type.
	 *   4. Normalise each `IShare` into the leaf-row contract and
	 *      deduplicate by share id.
	 *
	 * Any Throwable from the share subsystem short-circuits to `[]` so
	 * the registry tab degrades gracefully per AD-23.
	 *
	 * Row shape:
	 *   - `id`                    — share id (string)
	 *   - `shareType`             — int IShare::TYPE_*
	 *   - `shareWith`             — recipient (uid / gid / token / mail / federated id)
	 *   - `shareWithDisplayname`  — pre-resolved label
	 *   - `permissions`           — int bitmask
	 *   - `passwordProtected`     — bool
	 *   - `expiration`            — ISO-8601 date or null
	 *   - `stime`                 — share creation timestamp
	 *   - `nodeName`              — basename of the shared file
	 *   - `canRevoke`             — bool (true when current user owns the share)
	 *   - `url`                   — deep-link into the NC Files share panel
	 *   - `data`                  — passthrough of the above (for the widget)
	 *
	 * @param string $register Register slug or numeric id (unused — link
	 *                         convention is per-object).
	 * @param string $schema Schema slug or numeric id (unused).
	 * @param string $objectId Owning object uuid.
	 * @param array<string,mixed> $filters Reserved.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  list() tries the ShareLinkService delegate first,
	 * then falls back to a local folder-walk; per-node, per-type, and per-share dedup logic are
	 * sequential — extracting them would split the single "get shares for object" contract across
	 * multiple helpers.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Service-delegate + local-walk + Throwable fallback +
	 * folder-null + MAX_NODES cap + per-type Throwable swallow produce many paths; each guards a
	 * distinct degradation level per AD-23.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) AbstractIntegrationProvider::list() mandates
	 * (register, schema, objectId, filters); $register and $schema are unused because shares are
	 * indexed per-object-uuid and there is no register/schema scope for NC core sharing.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		try {
			// Tier-2: delegate the IManager folder-walk to ShareLinkService
			// so there is exactly ONE canonical implementation (and one
			// copy of the Phase H-1 entity-input folder-resolve fix). The
			// service returns the Tier-2 row contract; this provider
			// re-maps it to the registry widget's contract (`id` / `url` /
			// `data`) so existing consumers keep working. When the service
			// is unavailable the original in-provider walk below is the
			// fallback so the registry tab degrades gracefully per AD-23.
			$serviceRows = $this->serviceRows(objectId: $objectId);
			if ($serviceRows !== null) {
				return $serviceRows;
			}

			return $this->walkObjectFolder(objectId: $objectId);
		} catch (Throwable $e) {
			// Share subsystem unreachable; degrade to empty list per
			// AD-23 graceful-degradation contract.
			return [];
		}//end try
	}//end list()

	/**
	 * The ShareLinkService rows re-mapped to the widget contract, or null to fall back to the walk.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the service is absent or fails.
	 */
	private function serviceRows(string $objectId): ?array {
		$service = $this->lookup(serviceName: 'OCA\\OpenRegister\\Service\\ShareLinkService');
		if ($service === null || method_exists($service, 'getLinkedShares') === false) {
			return null;
		}

		try {
			$serviceRows = $service->getLinkedShares($objectId);
			return array_map([$this, 'mapServiceRow'], $serviceRows);
		} catch (Throwable $e) {
			// Fall through to the local walk.
			return null;
		}
	}//end serviceRows()

	/**
	 * Walk the object's folder for the current user's shares.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty without a share manager, user or folder.
	 */
	private function walkObjectFolder(string $objectId): array {
		$shareManager = $this->lookup(serviceName: 'OCP\\Share\\IManager');
		$userId = $this->resolveCurrentUserId();
		if ($shareManager === null || $userId === null) {
			return [];
		}

		$folder = $this->resolveObjectFolder(objectId: $objectId);
		if ($folder === null) {
			return [];
		}

		return $this->walkFolder(folder: $folder, shareManager: $shareManager, userId: $userId);
	}//end walkObjectFolder()

	/**
	 * Collect the shares of the first MAX_NODES children, each share once.
	 *
	 * @param Folder $folder       The object folder.
	 * @param object $shareManager The share manager.
	 * @param string $userId       The current user uid.
	 *
	 * @return array<int, array<string, mixed>> The rows, in walk order.
	 */
	private function walkFolder(Folder $folder, object $shareManager, string $userId): array {
		$rows = [];
		$seen = [];
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($count >= self::MAX_NODES) {
				break;
			}

			$count++;

			foreach ($this->nodeShares(shareManager: $shareManager, userId: $userId, node: $node) as $row) {
				$id = $row['id'];
				if (isset($seen[$id]) === true) {
					continue;
				}

				$seen[$id] = true;
				$rows[] = $row;
			}
		}

		return $rows;
	}//end walkFolder()

	/**
	 * The readable shares of one node across every share type, in type order.
	 *
	 * @param object $shareManager The share manager.
	 * @param string $userId       The current user uid.
	 * @param mixed  $node         The child node.
	 *
	 * @return array<int, array<string, mixed>> The normalised rows (may repeat ids).
	 */
	private function nodeShares(object $shareManager, string $userId, mixed $node): array {
		$rows = [];
		foreach (self::SHARE_TYPES as $type) {
			foreach ($this->sharesOfType(shareManager: $shareManager, userId: $userId, type: $type, node: $node) as $share) {
				$row = $this->normaliseShare(share: $share, userId: $userId);
				if ($row !== null) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}//end nodeShares()

	/**
	 * The shares of one type on one node; a per-type failure yields none.
	 *
	 * A missing provider for one type keeps the other types walking rather
	 * than blanking the whole list.
	 *
	 * @param object $shareManager The share manager.
	 * @param string $userId       The current user uid.
	 * @param int    $type         The share type.
	 * @param mixed  $node         The child node.
	 *
	 * @return mixed The shares, as the manager returns them.
	 */
	private function sharesOfType(object $shareManager, string $userId, int $type, mixed $node): mixed {
		try {
			return $shareManager->getSharesBy($userId, $type, $node);
		} catch (Throwable $e) {
			return [];
		}
	}//end sharesOfType()

	/**
	 * Map a ShareLinkService row to the registry widget's row contract.
	 *
	 * The service exposes the Tier-2 shape (`shareId` / `fileId` /
	 * `shareWithDisplayName` / `createdAt`); the registry widget expects
	 * `id` / `url` / `data`. This adapter bridges the two without a
	 * second IManager walk.
	 *
	 * @param array<string,mixed> $row Service row.
	 *
	 * @return array<string,mixed> Widget row.
	 */
	private function mapServiceRow(array $row): array {
		$fileId = (int)($row['fileId'] ?? 0);
		$fileUrl = self::filesUrl(nodeId: $fileId);

		return [
			'id' => (string)($row['shareId'] ?? ''),
			'shareType' => (int)($row['shareType'] ?? 0),
			'shareWith' => (string)($row['shareWith'] ?? ''),
			'shareWithDisplayname' => (string)($row['shareWithDisplayName'] ?? ''),
			'permissions' => (int)($row['permissions'] ?? 0),
			'passwordProtected' => (bool)($row['passwordProtected'] ?? false),
			'expiration' => $row['expiration'] ?? null,
			'stime' => $row['createdAt'] ?? null,
			'nodeName' => (string)($row['fileName'] ?? ''),
			'canRevoke' => (bool)($row['canRevoke'] ?? false),
			'url' => $fileUrl,
			'data' => [
				'id' => (string)($row['shareId'] ?? ''),
				'nodeId' => $fileId,
			],
		];
	}//end mapServiceRow()

	/**
	 * Mint a public token-based "track your case" link to the object.
	 *
	 * ADDITIVE: the legacy SharesProvider inherited the abstract
	 * NotImplementedException for create() (NC file shares are minted via
	 * the Files share panel, not here). This override adds a SECOND,
	 * independent create path — a public case-token link — without
	 * changing the existing list()/delete() NC-file-share behaviour.
	 *
	 * The payload selects the kind of thing to create:
	 *   - `type: 'public-token'` (or `type` omitted) — mint a case token
	 *     bound to the object via {@see CaseTokenService::mint()}. Optional
	 *     `label` (string) and `ttlSeconds` (int) tune the link. Returns
	 *     the token metadata + the public resolve URL.
	 *
	 * The token is an addressing handle only — the public resolve
	 * endpoint enforces RBAC (`_rbac: true`), so minting never grants
	 * read access beyond what the public group already has.
	 *
	 * When the CaseTokenService can't be resolved (older build) the call
	 * defers to the abstract NotImplementedException so the controller
	 * returns the canonical 501 — preserving backward compatibility.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param array<string,mixed> $payload Mint options (type/label/ttlSeconds).
	 *
	 * @return array<string,mixed> Minted token metadata + public URL.
	 *
	 * @throws \OCA\OpenRegister\Exception\NotImplementedException When the
	 *                                                             token service is unavailable.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$type = (string)($payload['type'] ?? 'public-token');
		if ($type !== 'public-token') {
			// Only the public-token surface is supported by this provider;
			// anything else defers to the abstract 501 contract.
			return parent::create(register: $register, schema: $schema, objectId: $objectId, payload: $payload);
		}

		$service = $this->lookup(serviceName: 'OCA\\OpenRegister\\Service\\CaseTokenService');
		if ($service === null || method_exists($service, 'mint') === false) {
			return parent::create(register: $register, schema: $schema, objectId: $objectId, payload: $payload);
		}

		return $service->mint(
			objectUuid: $objectId,
			registerId: $this->numericOrNull(value: $register),
			schemaId: $this->numericOrNull(value: $schema),
			label: self::mintLabel(payload: $payload),
			ttlSeconds: self::mintTtl(payload: $payload)
		);
	}//end create()

	/**
	 * The token label from a mint payload, cast to a string when given.
	 *
	 * @param array<string,mixed> $payload Mint options.
	 *
	 * @return string|null
	 */
	private static function mintLabel(array $payload): ?string {
		$label = $payload['label'] ?? null;
		if ($label === null) {
			return null;
		}

		return (string)$label;
	}//end mintLabel()

	/**
	 * The token lifetime from a mint payload, when it is numeric.
	 *
	 * @param array<string,mixed> $payload Mint options.
	 *
	 * @return int|null
	 */
	private static function mintTtl(array $payload): ?int {
		if (isset($payload['ttlSeconds']) === true && is_numeric($payload['ttlSeconds']) === true) {
			return (int)$payload['ttlSeconds'];
		}

		return null;
	}//end mintTtl()

	/**
	 * Revoke / unlink a share OR a public case-token by id.
	 *
	 * ADDITIVE: keeps the original NC-file-share revoke
	 * (`IManager::deleteShare()`) untouched and adds a case-token revoke
	 * path. A `token:`-prefixed entityId addresses a case token; anything
	 * else is treated as an NC share id exactly as before.
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId Share id, or `token:<token-or-id>`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		// Case-token revoke path: `token:<token-or-id>`.
		if (str_starts_with($entityId, 'token:') === true) {
			$this->deleteCaseToken(register: $register, schema: $schema, objectId: $objectId, entityId: $entityId);
			return;
		}

		$service = $this->lookup(serviceName: 'OCA\\OpenRegister\\Service\\ShareLinkService');
		if ($service === null || method_exists($service, 'revokeShare') === false) {
			parent::delete(register: $register, schema: $schema, objectId: $objectId, entityId: $entityId);
			return;
		}

		$service->revokeShare($objectId, $entityId);
	}//end delete()

	/**
	 * Revoke a `token:`-addressed case token, or answer the 501 without a token service.
	 *
	 * @param string $register Register slug.
	 * @param string $schema   Schema slug.
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId `token:<token-or-id>`.
	 *
	 * @return void
	 */
	private function deleteCaseToken(string $register, string $schema, string $objectId, string $entityId): void {
		$service = $this->lookup(serviceName: 'OCA\\OpenRegister\\Service\\CaseTokenService');
		if ($service !== null && method_exists($service, 'revoke') === true) {
			$service->revoke($objectId, substr($entityId, strlen('token:')));
			return;
		}

		parent::delete(register: $register, schema: $schema, objectId: $objectId, entityId: $entityId);
	}//end deleteCaseToken()

	/**
	 * Coerce a register/schema route param to an int, or null when it is
	 * a slug rather than a numeric id.
	 *
	 * @param string $value Register or schema slug / numeric id.
	 *
	 * @return int|null Numeric id, or null for a slug.
	 */
	private function numericOrNull(string $value): ?int {
		if (ctype_digit($value) === true) {
			return (int)$value;
		}

		return null;
	}//end numericOrNull()

	/**
	 * Provider health descriptor.
	 *
	 * Always-on integration: NC core sharing is part of every install
	 * so `status: 'ok'` unless the share subsystem itself fails to
	 * resolve, in which case it reports `degraded`. Never throws.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static ok/degraded descriptor echoing share-manager reachability — no standalone health
	 *              behaviour; the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		try {
			$shareManager = $this->lookup(serviceName: 'OCP\\Share\\IManager');
			if ($shareManager === null) {
				return [
					'status' => 'degraded',
					'authStatus' => 'configured',
					'message' => 'NC share manager is unreachable',
				];
			}

			return [
				'status' => 'ok',
				'authStatus' => 'configured',
				'message' => null,
			];
		} catch (Throwable $e) {
			return [
				'status' => 'degraded',
				'authStatus' => 'configured',
				'message' => 'NC share manager is unreachable',
			];
		}//end try
	}//end health()

	/**
	 * Resolve the object's NC folder via the FileService.
	 *
	 * Looks up the ObjectEntity via MagicMapper::find() first so
	 * `FileService::getObjectFolder()` is invoked on its
	 * entity-input branch (which knows how to resolve the register from
	 * the entity itself). The string-input branch in the handler routes
	 * to `createObjectFolderById(string, registerId: null)` which throws
	 * `"Failed to create file because no objectEntity or registerId
	 * given"` — that throw used to be swallowed by the Throwable catch
	 * below, so the leaf returned `[]` for every object regardless of
	 * how many real shares existed.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return Folder|null
	 */
	private function resolveObjectFolder(string $objectId): ?Folder {
		try {
			$handler = $this->lookup(
				serviceName: 'OCA\\OpenRegister\\Service\\FileService'
			);
			if ($handler === null) {
				return null;
			}

			$entity = $this->resolveObjectEntity(objectId: $objectId);
			$folder = $handler->getObjectFolder($entity ?? $objectId);
			if (($folder instanceof Folder) === true) {
				return $folder;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end resolveObjectFolder()

	/**
	 * Look up an ObjectEntity by uuid via the lazy container so the
	 * handler can resolve the register from the entity.
	 *
	 * Returns null on any failure — the calling site falls back to the
	 * raw UUID string which preserves prior behaviour (folder won't
	 * resolve, leaf reports `[]`), letting the integration degrade
	 * gracefully per AD-23.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return object|null ObjectEntity instance or null.
	 */
	private function resolveObjectEntity(string $objectId): ?object {
		try {
			$mapper = $this->lookup(
				serviceName: 'OCA\\OpenRegister\\Db\\MagicMapper'
			);
			if ($mapper === null) {
				return null;
			}

			$entity = $mapper->find($objectId);
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
			$id = (string)$share->getId();
			$shareType = (int)$share->getShareType();
			$sharedWith = (string)($share->getSharedWith() ?? '');
			$displayName = (string)($share->getSharedWithDisplayName() ?? $sharedWith);
			$permissions = (int)$share->getPermissions();
			$hasPassword = self::hasPassword(share: $share);
			$expiration = self::expiration(share: $share);
			$stimeTs = self::shareTimestamp(share: $share);

			$owner = (string)($share->getSharedBy() ?? '');
			// IShare::getNode() is declared to return Node and throws when the
			// node is gone, so the null guards here were unreachable.
			$node = $share->getNode();
			$nodeName = (string)$node->getName();
			$nodeId = (int)$node->getId();

			$nodeUrl = self::filesUrl(nodeId: $nodeId);

			return [
				'id' => $id,
				'shareType' => $shareType,
				'shareWith' => $sharedWith,
				'shareWithDisplayname' => $displayName,
				'permissions' => $permissions,
				'passwordProtected' => $hasPassword,
				'expiration' => $expiration,
				'stime' => $stimeTs,
				'nodeName' => $nodeName,
				'canRevoke' => $owner === $userId,
				'url' => $nodeUrl,
				'data' => [
					'id' => $id,
					'token' => (string)($share->getToken() ?? ''),
					'owner' => $owner,
					'nodeId' => $nodeId,
				],
			];
		} catch (Throwable $e) {
			return null;
		}//end try
	}//end normaliseShare()

	/**
	 * Whether a share carries a non-empty password.
	 *
	 * @param IShare $share The share.
	 *
	 * @return bool
	 */
	private static function hasPassword(IShare $share): bool {
		return $share->getPassword() !== null && $share->getPassword() !== '';
	}//end hasPassword()

	/**
	 * A share's expiration as an ATOM date, or null without one.
	 *
	 * @param IShare $share The share.
	 *
	 * @return string|null
	 */
	private static function expiration(IShare $share): ?string {
		$expDate = $share->getExpirationDate();
		if ($expDate === null) {
			return null;
		}

		return $expDate->format(\DateTimeInterface::ATOM);
	}//end expiration()

	/**
	 * A share's creation time as a unix timestamp, 0 without one.
	 *
	 * @param IShare $share The share.
	 *
	 * @return int
	 */
	private static function shareTimestamp(IShare $share): int {
		$stime = $share->getShareTime();
		if ($stime === null) {
			return 0;
		}

		return $stime->getTimestamp();
	}//end shareTimestamp()

	/**
	 * The Files app link for a node, or the Files app itself for no node.
	 *
	 * @param int $nodeId The node id.
	 *
	 * @return string
	 */
	private static function filesUrl(int $nodeId): string {
		if ($nodeId > 0) {
			return '/index.php/apps/files/?fileid=' . $nodeId;
		}

		return '/index.php/apps/files';
	}//end filesUrl()

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
	 */
	private function resolveContainer(): ContainerInterface {
		if ($this->container !== null) {
			return $this->container;
		}

		// Thin PSR-11 adapter around `\OCP\Server::get()` so the rest
		// of the provider can treat container lookups uniformly.
		return new class implements ContainerInterface {
			/**
			 * Resolve a service from NC's global server container.
			 *
			 * @param string $id Service id.
			 *
			 * @return object
			 *
			 * @spec exclude Anonymous PSR-11 adapter shim around \OCP\Server::get — pure DI plumbing, no behavioural contract.
			 */
			public function get(string $id): object {
				// phpcs:ignore CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- PSR-11 adapter shim: this IS the container being handed to a collaborator, so there is no container to inject into it.
				return Server::get($id);
			}//end get()

			/**
			 * Whether NC's global server container can resolve the id.
			 *
			 * @param string $id Service id.
			 *
			 * @return bool
			 *
			 * @spec exclude Anonymous PSR-11 adapter shim around \OCP\Server::get — pure DI plumbing, no behavioural contract.
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
