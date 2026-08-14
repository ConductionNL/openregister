<?php

/**
 * Object grant resolver — which objects has this caller been invited to.
 *
 * A per-object grant is a real Nextcloud share on the object's NC folder (design
 * D1 / Q1: `IShare` is Files-bound and cannot target an object, but every object
 * has a folder, created on demand). Core owns the share record — token, expiry,
 * password, mailer, federation handshake, revocation — and OpenRegister owns only
 * the authorization verdict.
 *
 * READ-THROUGH, NEVER CACHED ACROSS REQUESTS (design D2). `IShare` is core state
 * that mutates outside OpenRegister: a user opens the Files share panel and
 * revokes a share, and nothing tells us. A stored copy would be an
 * access-control bug in BOTH directions — a stale grant admits somebody whose
 * share was revoked, and a stale revocation hides an object from somebody
 * entitled to it. So this resolves from `IManager` and memoises for the LIFETIME
 * OF ONE REQUEST only, which is what makes "revocation denies on the next
 * request" true by construction rather than by a cache-invalidation rule
 * somebody has to remember.
 *
 * WHY IT RETURNS UUIDs RATHER THAN ANSWERING PER ROW. The list emitters need the
 * grant set INSIDE a SQL query, and there is no join from a magic table to core's
 * share tables that survives both platforms and the UNION arm. So the caller's
 * grants are resolved ONCE per request and passed into the emitters as a value.
 * That is a per-request resolve, not a per-row one, and both emitters and both
 * PHP paths read the same set.
 *
 * FOLDER SHARES ONLY. A share on a FILE inside an object's folder is a file
 * share: a different concept, already served by
 * {@see \OCA\OpenRegister\Service\ShareLinkService}, and deliberately left alone.
 * Only a share whose node IS the object's folder grants the OBJECT.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\Constants;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the object UUIDs a caller has been granted, from core's shares.
 */
class ObjectGrantResolver {

	/**
	 * Share types that name a PRINCIPAL, and so can grant an object.
	 *
	 * Link and email shares are deliberately absent: they are bearer
	 * capabilities resolved from a token by whoever presents it, not grants to a
	 * logged-in caller, so they are decided on the public entry point rather
	 * than in this filter. Remote types are here so a federated principal is one
	 * more principal (design D5) rather than a second decision path.
	 *
	 * @var int[]
	 */
	private const PRINCIPAL_SHARE_TYPES = [
		IShare::TYPE_USER,
		IShare::TYPE_GROUP,
		IShare::TYPE_REMOTE,
		IShare::TYPE_REMOTE_GROUP,
	];

	/**
	 * Shares to read per type before giving up.
	 *
	 * Core's own default is 50. A caller with more granted objects than this
	 * would silently stop seeing the rest, so the bound is paged through and a
	 * WARNING is logged when it is actually hit — a silent truncation here reads
	 * as "you have no access", which is indistinguishable from a revoked share.
	 *
	 * @var integer
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Maximum pages per share type.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 20;

	/**
	 * Per-REQUEST memoisation, keyed by user id. Never persisted (design D2).
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $memoised = [];

	/**
	 * Extension verbs carried by the caller's grants, keyed by object UUID.
	 *
	 * Populated during the same resolve as the bitmask and cleared by the same
	 * `forget()`, so the two can never disagree about which request they belong
	 * to.
	 *
	 * @var array<string, string[]>
	 */
	private array $verbs = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface|null $container Optional container override (tests only).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()

	/**
	 * The object UUIDs granted to a caller, mapped to the permission bitmask.
	 *
	 * @param string|null $userId The caller, or null when anonymous.
	 *
	 * @return array<string, int> Object UUID => core permission bitmask.
	 */
	public function grantedObjectUuids(?string $userId): array {
		// An anonymous caller holds no principal grants. A link or email
		// capability is decided from its token on the public entry point, not
		// here — see PRINCIPAL_SHARE_TYPES.
		if ($userId === null || $userId === '') {
			return [];
		}

		if (array_key_exists($userId, $this->memoised) === true) {
			return $this->memoised[$userId];
		}

		$granted = [];

		$manager = $this->shareManager();
		if ($manager === null) {
			// Fail CLOSED: an unreachable share manager means "no grants", which
			// hides objects rather than exposing them. It must never fall
			// through to an open result.
			$this->logger->error(
				message: '[ObjectGrantResolver] Share manager unreachable; treating the caller as holding no grants',
				context: ['file' => __FILE__, 'line' => __LINE__, 'userId' => $userId]
			);
			$this->memoised[$userId] = [];
			return [];
		}

		foreach (self::PRINCIPAL_SHARE_TYPES as $shareType) {
			$this->collectForType(
				manager: $manager,
				userId: $userId,
				shareType: $shareType,
				granted: $granted
			);
		}

		$this->memoised[$userId] = $granted;

		return $granted;
	}//end grantedObjectUuids()

	/**
	 * Whether the caller holds any grant at all.
	 *
	 * The list emitters use this to decide whether the grant branch is worth
	 * emitting, and `MagicSearchHandler` uses it to force the organisation
	 * filter on — a grant must never widen the tenant edge (design D3c).
	 *
	 * @param string|null $userId The caller.
	 *
	 * @return bool True when at least one object is granted to this caller.
	 */
	public function hasAnyGrant(?string $userId): bool {
		return empty($this->grantedObjectUuids(userId: $userId)) === false;
	}//end hasAnyGrant()

	/**
	 * The core permission bit an action requires, or null when it has none.
	 *
	 * Core's bitmask has five verbs. An OpenRegister action outside that set —
	 * a custom verb like ZGW's `besluit_nemen` — has NO bit, and a grant
	 * therefore cannot admit it: it returns null and the caller fails closed.
	 * That is the conservative direction and it matches design Q5, where RBAC
	 * grants visibility only and an extension verb is enforced at the endpoint
	 * that performs it.
	 *
	 * @param string $action The action being decided.
	 *
	 * @return integer|null The required bit, or null when the action has none.
	 */
	public function permissionFor(string $action): ?int {
		$bits = [
			'read' => Constants::PERMISSION_READ,
			'update' => Constants::PERMISSION_UPDATE,
			'create' => Constants::PERMISSION_CREATE,
			'delete' => Constants::PERMISSION_DELETE,
			'share' => Constants::PERMISSION_SHARE,
		];

		return ($bits[$action] ?? null);
	}//end permissionFor()

	/**
	 * The object UUIDs granted to a caller FOR ONE ACTION.
	 *
	 * A grant carries a permission, and until this existed every grant admitted
	 * every action — a read-only invitation silently allowed delete. The list
	 * emitters use this so the grant branch only ever names rows the caller may
	 * reach for the action being filtered.
	 *
	 * @param string|null $userId The caller.
	 * @param string $action The action being decided.
	 *
	 * @return array<string, int> Object UUID => permission bitmask.
	 */
	public function grantedObjectUuidsFor(?string $userId, string $action): array {
		$required = $this->permissionFor(action: $action);
		if ($required === null) {
			return [];
		}

		$granted = [];
		foreach ($this->grantedObjectUuids(userId: $userId) as $uuid => $permissions) {
			if (($permissions & $required) === $required) {
				$granted[$uuid] = $permissions;
			}
		}

		return $granted;
	}//end grantedObjectUuidsFor()

	/**
	 * Whether one object is granted to the caller for one action.
	 *
	 * @param string|null $userId The caller.
	 * @param string|null $objectUuid The object's UUID.
	 * @param string $action The action being decided.
	 *
	 * @return bool True when a grant exists that carries the action's permission.
	 */
	public function isGranted(?string $userId, ?string $objectUuid, string $action = 'read'): bool {
		if ($objectUuid === null || $objectUuid === '') {
			return false;
		}

		return array_key_exists(
			$objectUuid,
			$this->grantedObjectUuidsFor(userId: $userId, action: $action)
		);
	}//end isGranted()

	/**
	 * Forget the memoised grants.
	 *
	 * For tests, and for the one production case that needs it: code that
	 * creates or revokes a grant and then re-decides within the SAME request.
	 *
	 * @param string|null $userId Only this caller, or null for everybody.
	 *
	 * @return void
	 */
	public function forget(?string $userId = null): void {
		if ($userId === null) {
			$this->memoised = [];
			$this->verbs = [];
			return;
		}

		unset($this->memoised[$userId]);

		// The verb map is keyed by OBJECT, not by user, so a per-user forget
		// cannot prune it precisely. Clearing it wholly is the safe direction:
		// it is rebuilt on the next resolve, and a stale verb would admit.
		$this->verbs = [];
	}//end forget()

	/**
	 * Page through one share type, adding folder shares to the grant map.
	 *
	 * @param IManager $manager Core share manager.
	 * @param string $userId The caller.
	 * @param integer $shareType One of PRINCIPAL_SHARE_TYPES.
	 * @param array<string, int> $granted Grant map, modified in place.
	 *
	 * @return void
	 */
	private function collectForType(IManager $manager, string $userId, int $shareType, array &$granted): void {
		$page = 0;
		while ($page < self::MAX_PAGES) {
			try {
				$shares = $manager->getSharedWith(
					$userId,
					$shareType,
					null,
					self::PAGE_SIZE,
					($page * self::PAGE_SIZE)
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[ObjectGrantResolver] Could not read shares for a type; skipping it',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'shareType' => $shareType,
						'exception' => $e->getMessage(),
					]
				);
				return;
			}

			if (empty($shares) === true) {
				return;
			}

			foreach ($shares as $share) {
				$uuid = $this->objectUuidOf(share: $share);
				if ($uuid === null) {
					continue;
				}

				// A caller may hold several grants on one object (directly and
				// through a group). The widest wins, which is how core itself
				// composes overlapping shares.
				$existing = ($granted[$uuid] ?? 0);
				$granted[$uuid] = ($existing | $share->getPermissions());

				// Extension verbs ride in IShare's attribute bag (ADR-010), not
				// in the permission integer, because core's bitmask has no room
				// for a verb it does not define. Unioned across overlapping
				// grants for the same reason the bitmask is.
				$verbs = $this->verbsOf(share: $share);
				if (empty($verbs) === false) {
					$this->verbs[$uuid] = array_values(
						array_unique(array_merge(($this->verbs[$uuid] ?? []), $verbs))
					);
				}
			}//end foreach

			if (count($shares) < self::PAGE_SIZE) {
				return;
			}

			$page++;
		}//end while

		$this->logger->warning(
			message: '[ObjectGrantResolver] Hit the share paging bound; some grants were NOT read',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'shareType' => $shareType,
				'bound' => (self::PAGE_SIZE * self::MAX_PAGES),
			]
		);
	}//end collectForType()

	/**
	 * The attribute scope OpenRegister's extension verbs live under.
	 *
	 * @var string
	 */
	public const VERB_ATTRIBUTE_SCOPE = 'openregister';

	/**
	 * The attribute key holding the verb list.
	 *
	 * @var string
	 */
	public const VERB_ATTRIBUTE_KEY = 'verbs';

	/**
	 * Whether a grant carries one EXTENSION verb for this caller.
	 *
	 * Core's bitmask has five verbs and OpenRegister has more concepts than
	 * that — `use` a credential, `run` a flow. ADR-010 puts those in the share's
	 * attribute bag rather than widening the RBAC vocabulary, and this is the
	 * read side of it.
	 *
	 * Deliberately SEPARATE from {@see isGranted()}. That method answers for the
	 * five core verbs and refuses everything else, which is what keeps the RBAC
	 * evaluator finite and reviewable. An extension verb is asked for by the
	 * endpoint that performs the action, and only there.
	 *
	 * @param string|null $userId The caller.
	 * @param string|null $objectUuid The object.
	 * @param string $verb The extension verb, e.g. `use`.
	 *
	 * @return bool True when a grant to this caller carries that verb.
	 */
	public function grantCarriesVerb(?string $userId, ?string $objectUuid, string $verb): bool {
		if ($objectUuid === null || $objectUuid === '') {
			return false;
		}

		// Force the resolve — that is what populates the verb map.
		$granted = $this->grantedObjectUuids(userId: $userId);
		if (array_key_exists($objectUuid, $granted) === false) {
			return false;
		}

		return in_array($verb, ($this->verbs[$objectUuid] ?? []), true);
	}//end grantCarriesVerb()

	/**
	 * The extension verbs one share carries.
	 *
	 * @param IShare $share The share.
	 *
	 * @return string[] The verbs, empty when it carries none.
	 */
	private function verbsOf(IShare $share): array {
		try {
			$attributes = $share->getAttributes();
			if ($attributes === null) {
				return [];
			}

			$raw = $attributes->getAttribute(self::VERB_ATTRIBUTE_SCOPE, self::VERB_ATTRIBUTE_KEY);
		} catch (Throwable $e) {
			return [];
		}

		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_values(
			array_filter($raw, static fn ($verb) => is_string($verb) === true && $verb !== '')
		);
	}//end verbsOf()

	/**
	 * The object UUID a share grants, or null when it grants no object.
	 *
	 * An object's folder is named after its UUID — the convention
	 * `FolderManagementHandler` creates and `FileMapper::findOwningObjectUuid()`
	 * already relies on. A share on a FILE inside that folder is a file share
	 * and grants no object.
	 *
	 * @param IShare $share The share to inspect.
	 *
	 * @return string|null The granted object's UUID, or null.
	 */
	private function objectUuidOf(IShare $share): ?string {
		try {
			if ($share->getNodeType() !== 'folder') {
				return null;
			}

			// `getNode()` is typed to return a Node and `getName()` a string, so
			// neither is re-checked here — both throw instead when the node has
			// gone, which the catch below is for.
			$name = $share->getNode()->getName();
		} catch (Throwable $e) {
			// A share whose node has gone is not a grant. Core will clean it up.
			return null;
		}

		if ($name === '') {
			return null;
		}

		// Only accept something UUID-shaped. Register and schema folders sit in
		// the same tree, and admitting one of those by name would turn a share
		// of a CONTAINER into a grant on an object that merely shares its name.
		if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $name) !== 1) {
			return null;
		}

		return $name;
	}//end objectUuidOf()

	/**
	 * Resolve core's share manager lazily.
	 *
	 * Mirrors {@see \OCA\OpenRegister\Service\ShareLinkService::getShareManager()}:
	 * constructor-injecting `IManager` pulls the whole Files sharing stack into
	 * every construction of this class, including boot paths that never share
	 * anything.
	 *
	 * @return IManager|null The manager, or null when unreachable.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) \OCP\Server::get() is Nextcloud's prescribed
	 * service locator for optional late-bound dependencies.
	 */
	private function shareManager(): ?IManager {
		try {
			$container = ($this->container ?? \OCP\Server::get(ContainerInterface::class));
			$manager = $container->get(IManager::class);
			if (($manager instanceof IManager) === true) {
				return $manager;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end shareManager()
}//end class
