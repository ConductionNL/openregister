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
 * than constructor-injected. Tests inject a mock `ContainerInterface`
 * via the optional second ctor arg.
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
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\CoreIntegrationProvider;
use OCA\OpenRegister\Service\CaseTokenTarget;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Sharing\ObjectShareLookup;
use OCP\Files\Folder;
use OCP\IL10N;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Provider exposes list/delete/health plus the
 * share-walk helpers (normaliseShare, mapServiceRow); each serves a distinct phase of the
 * query-time share-walk and cannot be combined.
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)      mapServiceRow() is used as a callable in
 * array_map([$this, 'mapServiceRow'], ...) — PHPMD does not trace callable-array references
 * and incorrectly flags it as unused.
 */
class SharesProvider extends CoreIntegrationProvider {

	/**
	 * Share types the provider surfaces. Order matters: it drives
	 * `IManager::getSharesBy()` iteration so the assembled row list is
	 * predictable across calls.
	 *
	 * @var int[]
	 */
	private const SHARE_TYPES = ObjectShareLookup::SHARE_TYPES;

	/**
	 * Maximum nodes inside the object's folder to query. The registry
	 * tab is a quick-look surface; deep object folders aren't expected
	 * for normal case files, and unbounded fan-out would hammer the
	 * share manager.
	 *
	 * @var int
	 */
	private const MAX_NODES = ObjectShareLookup::MAX_NODES;

	/**
	 * Container-backed lookups of the share manager, object folder, entity and user.
	 *
	 * @var ObjectShareLookup
	 */
	private readonly ObjectShareLookup $shares;

	/**
	 * Constructor.
	 *
	 * The optional container argument is for unit tests only; production uses
	 * `\OCP\Server::get(...)` through {@see ObjectShareLookup}.
	 *
	 * @param IL10N $l10n Localisation.
	 * @param ContainerInterface|null $container Optional server-container override (tests
	 *                                           only).
	 *
	 * @return void
	 */
	public function __construct(
		private IL10N $l10n,
		?ContainerInterface $container = null,
	) {
		$this->shares = new ObjectShareLookup(container: $container);
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

	public function getStorageStrategy(): string {
		return 'query-time';
	}//end getStorageStrategy()

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
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
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
	public function list(ObjectRef $ref, array $filters = []): array {
		return $this->sharesOf(objectId: $ref->id);
	}//end list()

	/**
	 * The shares of an object's folder: the service rows, else the local walk, else none.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function sharesOf(string $objectId): array {
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
	}//end sharesOf()

	/**
	 * The ShareLinkService rows re-mapped to the widget contract, or null to fall back to the walk.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the service is absent or fails.
	 */
	private function serviceRows(string $objectId): ?array {
		$service = $this->shares->lookup(serviceName: 'OCA\\OpenRegister\\Service\\ShareLinkService');
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
		$shareManager = $this->shares->lookup(serviceName: 'OCP\\Share\\IManager');
		$userId = $this->shares->currentUserId();
		if ($shareManager === null || $userId === null) {
			return [];
		}

		$folder = $this->shares->objectFolder(objectUuid: $objectId);
		if ($folder === null) {
			return [];
		}

		return $this->walkFolder(run: new ShareWalkRun(shareManager: $shareManager, userId: $userId), folder: $folder);
	}//end walkObjectFolder()

	/**
	 * Collect the shares of the first MAX_NODES children, each share once.
	 *
	 * @param ShareWalkRun $run    The share manager and the current user.
	 * @param Folder       $folder The object folder.
	 *
	 * @return array<int, array<string, mixed>> The rows, in walk order.
	 */
	private function walkFolder(ShareWalkRun $run, Folder $folder): array {
		$rows = [];
		$seen = [];
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($count >= self::MAX_NODES) {
				break;
			}

			$count++;
			$run->node = $node;

			foreach ($this->nodeShares(run: $run) as $row) {
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
	 * @param ShareWalkRun $run The share manager, the current user and the child node in hand.
	 *
	 * @return array<int, array<string, mixed>> The normalised rows (may repeat ids).
	 */
	private function nodeShares(ShareWalkRun $run): array {
		$rows = [];
		foreach (self::SHARE_TYPES as $type) {
			foreach ($this->sharesOfType(run: $run, type: $type) as $share) {
				$row = $this->normaliseShare(share: $share, userId: $run->userId);
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
	 * @param ShareWalkRun $run  The share manager, the current user and the child node in hand.
	 * @param int          $type The share type.
	 *
	 * @return mixed The shares, as the manager returns them.
	 */
	private function sharesOfType(ShareWalkRun $run, int $type): mixed {
		try {
			return $run->shareManager->getSharesBy($run->userId, $type, $run->node);
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
	 *     bound to the object via {@see CaseTokenService::mintFor()}. Optional
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
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $payload Mint options (type/label/ttlSeconds).
	 *
	 * @return array<string,mixed> Minted token metadata + public URL.
	 *
	 * @throws \OCA\OpenRegister\Exception\NotImplementedException When the
	 *                                                             token service is unavailable.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function create(ObjectRef $ref, array $payload): array {
		return $this->mintCaseTokenFor(ref: $ref, payload: $payload);
	}//end create()

	/**
	 * Mint a public case token for the object; any other type, or no token service, answers the 501.
	 *
	 * @param ObjectRef           $ref     The owning object.
	 * @param array<string,mixed> $payload `type` (only `public-token`), `label`, `ttlSeconds`.
	 *
	 * @return array<string,mixed>
	 */
	private function mintCaseTokenFor(ObjectRef $ref, array $payload): array {
		$type = (string)($payload['type'] ?? 'public-token');
		if ($type !== 'public-token') {
			// Only the public-token surface is supported by this provider;
			// anything else defers to the abstract 501 contract.
			return parent::create(ref: $ref, payload: $payload);
		}

		$service = $this->shares->lookup(serviceName: 'OCA\\OpenRegister\\Service\\CaseTokenService');
		if ($service === null || method_exists($service, 'mintFor') === false) {
			return parent::create(ref: $ref, payload: $payload);
		}

		return $service->mintFor(
			target: new CaseTokenTarget(
				objectUuid: $ref->id,
				registerId: $this->numericOrNull(value: (string)$ref->register),
				schemaId: $this->numericOrNull(value: (string)$ref->schema),
				label: self::mintLabel(payload: $payload)
			),
			ttlSeconds: self::mintTtl(payload: $payload)
		);
	}//end mintCaseTokenFor()

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
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param string $entityId Share id, or `token:<token-or-id>`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 */
	public function delete(ObjectRef $ref, string $entityId): void {
		$this->revokeFor(ref: $ref, entityId: $entityId);
	}//end delete()

	/**
	 * Revoke a case token (`token:<token-or-id>`) or a file share of the object; without the service, the 501.
	 *
	 * @param ObjectRef $ref      The owning object.
	 * @param string    $entityId `token:<token-or-id>`, or a share id.
	 *
	 * @return void
	 */
	private function revokeFor(ObjectRef $ref, string $entityId): void {
		// Case-token revoke path: `token:<token-or-id>`.
		if (str_starts_with($entityId, 'token:') === true) {
			$this->deleteCaseToken(ref: $ref, entityId: $entityId);
			return;
		}

		$service = $this->shares->lookup(serviceName: 'OCA\\OpenRegister\\Service\\ShareLinkService');
		if ($service === null || method_exists($service, 'revokeShare') === false) {
			parent::delete(ref: $ref, entityId: $entityId);
			return;
		}

		$service->revokeShare($ref->id, $entityId);
	}//end revokeFor()

	/**
	 * Revoke a `token:`-addressed case token, or answer the 501 without a token service.
	 *
	 * @param ObjectRef $ref      The owning object.
	 * @param string    $entityId `token:<token-or-id>`.
	 *
	 * @return void
	 */
	private function deleteCaseToken(ObjectRef $ref, string $entityId): void {
		$service = $this->shares->lookup(serviceName: 'OCA\\OpenRegister\\Service\\CaseTokenService');
		if ($service !== null && method_exists($service, 'revoke') === true) {
			$service->revoke($ref->id, substr($entityId, strlen('token:')));
			return;
		}

		parent::delete(ref: $ref, entityId: $entityId);
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
			$shareManager = $this->shares->lookup(serviceName: 'OCP\\Share\\IManager');
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

}//end class
