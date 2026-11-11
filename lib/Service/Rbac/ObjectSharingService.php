<?php

/**
 * Object sharing service — the WRITE side of the object scope and its grants.
 *
 * {@see ObjectScopeResolver} and {@see ObjectGrantResolver} decide access.
 * This decides who may CHANGE it, and performs the change.
 *
 * WHY A DEDICATED SERVICE RATHER THAN THE OBJECT PAYLOAD. `_authorization` is
 * deliberately not writable through ordinary object create/update:
 * `SaveObjects::stripSelfInjectionFields()` strips it from every non-admin write,
 * and `MagicMapper::prepareObjectDataForTable()` omits the column entirely so an
 * ordinary save carries the stored value forward untouched. Both are correct —
 * per-object RBAC must not be smuggled in through a data field. So the scope gets
 * its own narrow, owner-checked entry point instead.
 *
 * WHY AN OWNER MAY SET THE SCOPE BUT NOT THE ACTION LISTS. They live in the same
 * `_authorization` block, and the difference is what they can do:
 *
 *   - `scope` can only ever NARROW. `private` removes the schema's group rules as
 *     a grant path; it cannot admit anybody the schema refuses (design D3b).
 *     Letting an owner make their own object private is the Files model.
 *   - the action lists can WIDEN — `{"read": ["public"]}` on one object would
 *     publish it. That stays admin-only.
 *
 * So this service writes the `scope` key and NOTHING else in that block. It reads
 * the stored block, replaces one key, and writes it back, so an admin-set action
 * override survives an owner changing the scope.
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

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\Files\Folder;
use OCP\Share\IShare;
use Throwable;

/**
 * Owner-checked writes for an object's scope and its per-object grants.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The split is deliberate and each
 * dependency is load-bearing: ObjectAuthorizationWriter performs the ONE targeted
 * column write (the object write path omits `_authorization` on purpose, so a save
 * cannot be used here); InheritedGrantLister answers the ancestor half of an access
 * review, and only reads; FolderManagementHandler + IManager + IShare + Folder are
 * core's share surface, which owns the grant record; ObjectScopeResolver is the
 * shared vocabulary AND the shared owner-or-admin rule, so this cannot drift from
 * the read side; ObjectGrantResolver is only asked to drop its per-request memo
 * after a write; IUserSession + IGroupManager resolve the caller for the guard;
 * Register/Schema/ObjectEntity are the addressed row. Mirrors the reasoned
 * suppression on {@see \OCA\OpenRegister\Service\ShareLinkService}, which spans the
 * same core-share types for the same reason.
 */
class ObjectSharingService implements ObjectSharingServiceInterface {

	/**
	 * Share types this service will create or revoke as an object grant.
	 *
	 * Deliberately the PRINCIPAL types only, matching
	 * {@see ObjectGrantResolver}: a link or email capability is a different
	 * thing and arrives with the link surface.
	 *
	 * @var array<string, int>
	 */
	private const GRANTABLE_TYPES = [
		'user' => IShare::TYPE_USER,
		'group' => IShare::TYPE_GROUP,
		'remote' => IShare::TYPE_REMOTE,
		'remote_group' => IShare::TYPE_REMOTE_GROUP,
	];

	/**
	 * Share types {@see listGrants()} reports.
	 *
	 * A SUPERSET of GRANTABLE_TYPES, and deliberately a separate constant.
	 * Links and email invitations are created by their own endpoints
	 * ({@see createLink()}, {@see inviteByEmail()}) rather than by
	 * {@see grant()}, so they must NOT become grantable — `type=link` posted to
	 * the grant endpoint would bypass the link surface's own rules. But they
	 * must be LISTED, because a capability you cannot see is a capability you
	 * cannot revoke.
	 *
	 * While this listed principals only, links and email invitations were
	 * write-only: `createLink()` minted a working public link that never
	 * appeared in the panel, so the revoke control for it did not exist and the
	 * only way to withdraw it was raw SQL or core's Files UI. Caught by driving
	 * the link control through the browser (task 10.3) — the create and the
	 * anonymous redeem both passed, and the revoke had nothing to click.
	 *
	 * @var array<string, int>
	 */
	private const LISTABLE_TYPES = ObjectShareReader::LISTABLE_TYPES;

	/**
	 * Constructor.
	 *
	 * @param ObjectShareSurface   $shares      The object folder, core's share manager, the share reader and the share-time writer.
	 * @param ObjectGrantRecords   $records     The authorization writer, the scope vocabulary, the grant resolver and the inherited grants.
	 * @param ObjectSharingContext $context     The session, the group manager, the logger and the grant auditor.
	 * @param GrantManageGuard     $grantManage Who may manage a record's grants: the owner, an administrator,
	 *                                          or a holder of the special right `grant_manage`.
	 */
	public function __construct(
		private readonly ObjectShareSurface $shares,
		private readonly ObjectGrantRecords $records,
		private readonly ObjectSharingContext $context,
		private readonly GrantManageGuard $grantManage,
	) {
	}//end __construct()

	/**
	 * The guard-free reader of the shares on an object folder.
	 *
	 * @return ObjectShareReader The injected reader, or one over this service's own share surface.
	 */
	private function shareReader(): ObjectShareReader {
		return ($this->shares->shareReader ?? new ObjectShareReader($this->shares->folders, $this->shares->shareManager, $this->context->logger));
	}//end shareReader()

	/**
	 * Set one object's scope, as its owner or an administrator.
	 *
	 * @param RegisterSchema $table  The register and schema the object lives in.
	 * @param ScopeChange    $change The object and the requested scope.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException When the scope is not in the vocabulary.
	 *
	 * @return array<string, mixed> The stored authorization block after the write.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function setScope(RegisterSchema $table, ScopeChange $change): array {
		$object = $change->object;
		$this->requireOwnerOrAdmin(object: $object);
		$block = $this->withScope(object: $object, scope: $change->scope);

		$this->records->authorizationWriter->writeAuthorizationBlock(
			table: $table,
			assignment: new AuthorizationAssignment(objectUuid: (string)$object->getUuid(), block: $block)
		);

		return $block;
	}//end setScope()

	/**
	 * The object's authorization block with the scope set, once the scope is in the vocabulary.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $scope  The requested scope.
	 *
	 * @throws InvalidArgumentException When the scope is not in the vocabulary.
	 *
	 * @return array<string, mixed> The block to store.
	 */
	private function withScope(ObjectEntity $object, string $scope): array {
		$valid = [ObjectScopeResolver::SCOPE_ORGANISATION, ObjectScopeResolver::SCOPE_PRIVATE];
		if (in_array($scope, $valid, true) === false) {
			throw new InvalidArgumentException(
				'Scope must be one of: ' . implode(', ', $valid)
			);
		}

		// Read-modify-write ONE key. An admin-set action override in the same
		// block must survive an owner changing the scope.
		// `getAuthorization()` declares `array|null`, so this is an array after
		// the coalesce. A defensive is_array() here is dead code — if that
		// accessor can really return something else, that is the entity's
		// contract to fix, not something to paper over at every call site.
		$block = ($object->getAuthorization() ?? []);

		$block[ObjectScopeResolver::SCOPE_KEY] = $scope;

		return $block;
	}//end withScope()

	/**
	 * List the grants on one object.
	 *
	 * Readable by the owner and administrators. A recipient does not get to
	 * enumerate who else an object was shared with.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 *
	 * @return array<int, array<string, mixed>> The grants.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function listGrants(ObjectEntity $object): array {
		$this->requireMayManageGrants(object: $object);

		// `getSharesBy()` returns shares CREATED BY the uid it is given, so
		// listing as the caller alone would show an administrator nothing on
		// somebody else's object — the grants were made by the owner. Ask for
		// both, deduplicated by share id.
		$shares = $this->shareReader()->sharesOn(
			object: $object,
			sharers: [(string)$object->getOwner(), (string)$this->callerUid()]
		);

		$grants = [];
		foreach ($shares as $fullId => $found) {
			$share = $found['share'];
			// The provider-prefixed form (`ocinternal:7`) is what
			// getShareById() accepts; getId() is the bare number and does
			// NOT round-trip. Returning the wrong one made revoke fail
			// with "No such grant".
			$grants[$fullId] = [
				'id' => $fullId,
				'type' => $found['type'],
				'sharedWith' => $share->getSharedWith(),
				'permissions' => $share->getPermissions(),
				'expiration' => $share->getExpirationDate()?->format('c'),
				// Ledger row 13.41: an access review can only be
				// FINISHED when every grant is either explained or
				// explicitly local. The provenance answers the first
				// half; this answers the second, beside it and in the
				// same row rather than in a second call nobody makes.
				'inherited' => false,
				'inheritable' => $this->records->grantResolver->isInheritable(
					(string)$object->getUuid()
				),
			];
		}//end foreach

		return array_merge(
			array_values($grants),
			$this->records->inheritedGrants->inheritedGrantsFor(object: $object)
		);
	}//end listGrants()

	/**
	 * Grant one principal access to one object with core permissions only.
	 *
	 * Kept for the apps that call it by class name; grantWithTerms takes the request.
	 *
	 * @param ObjectEntity $object      The object to share.
	 * @param string       $type        One of the GRANTABLE_TYPES keys.
	 * @param string       $shareWith   The principal to grant.
	 * @param integer      $permissions Core permission bitmask.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException On an unsupported type or unresolvable folder.
	 *
	 * @return array<string, mixed> The created grant.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function grant(ObjectEntity $object, string $type, string $shareWith, int $permissions = 1): array {
		$request = new GrantRequest(type: $type, shareWith: $shareWith, terms: new GrantTerms(permissions: $permissions));

		return $this->grantWithTerms(object: $object, request: $request);
	}//end grant()

	/**
	 * Grant one principal access to one object, on declared terms.
	 *
	 * The terms carry the extension verbs (ADR-010: core's bitmask has no room
	 * for a verb it does not define), the end of the grant and the share time to
	 * record, which core stamps with the current time, so it is written after
	 * creation.
	 *
	 * @param ObjectEntity $object  The object to share.
	 * @param GrantRequest $request The principal type (one of the GRANTABLE_TYPES keys), the principal and the terms.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException On an unsupported type or unresolvable folder.
	 *
	 * @return array<string, mixed> The created grant.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function grantWithTerms(ObjectEntity $object, GrantRequest $request): array {
		$terms = $request->terms;
		$admittedAs = $this->requireMayManageGrants(object: $object, grantee: $this->principalOf(request: $request));
		$this->assertGrantRequest(request: $request);

		$permissions = $this->withoutReshare(permissions: $terms->permissions);
		$share = $this->shareOnFolder(object: $object, shareType: self::GRANTABLE_TYPES[$request->type], permissions: $permissions);
		$share->setSharedWith(trim($request->shareWith));
		$share->setPermissions($permissions);
		$this->applyVerbs(share: $share, verbs: $terms->verbs);
		if ($terms->expiresAt !== null) {
			$share->setExpirationDate($terms->expiresAt);
		}

		$created = $this->createDated(share: $share, sharedAt: $terms->sharedAt);

		// The caller may re-decide access within this same request.
		$this->records->grantResolver->forget();

		$grant = $this->grantRecord(created: $created, request: $request);

		// Who let this principal in, and when, is the question the trail has
		// to be able to answer, whoever the caller was admitted as.
		$this->context->auditor?->recordGrant(object: $object, grant: $grant, admittedAs: $admittedAs);

		// The created share's identity, so a caller that keeps the grant can later tell it from another share.
		$identity = ['nodeId' => (int)$created->getNodeId(), 'sharedBy' => (string)$created->getSharedBy()];

		return $grant + $identity + ['shareType' => (int)$created->getShareType()];
	}//end grantWithTerms()

	/**
	 * Refuse a grant of an unknown type, or to nobody.
	 *
	 * @param GrantRequest $request The grant type and principal asked for.
	 *
	 * @throws InvalidArgumentException When the type is not grantable or the principal is blank.
	 *
	 * @return void
	 */
	private function assertGrantRequest(GrantRequest $request): void {
		if (isset(self::GRANTABLE_TYPES[$request->type]) === false) {
			throw new InvalidArgumentException(
				'Grant type must be one of: ' . implode(', ', array_keys(self::GRANTABLE_TYPES))
			);
		}

		if (trim($request->shareWith) === '') {
			throw new InvalidArgumentException('A grant needs a principal to grant to');
		}
	}//end assertGrantRequest()

	/**
	 * Create the share, and write the share time a fragment declared after creation.
	 *
	 * @param IShare        $share    The share built.
	 * @param DateTime|null $sharedAt The declared share time, or null for now.
	 *
	 * @return IShare The created share.
	 */
	private function createDated(IShare $share, ?DateTime $sharedAt): IShare {
		$created = $this->shares->shareManager->createShare($share);
		if ($sharedAt !== null && $this->shares->shareTimes !== null) {
			$this->shares->shareTimes->stamp(shareId: (int)$created->getId(), moment: $sharedAt);
			$created->setShareTime($sharedAt);
		}

		return $created;
	}//end createDated()

	/**
	 * The grant as it is returned and recorded.
	 *
	 * @param IShare       $created The created share.
	 * @param GrantRequest $request The grant type and the verbs asked for.
	 *
	 * @return array<string, mixed> The grant.
	 */
	private function grantRecord(IShare $created, GrantRequest $request): array {
		return [
			'id' => $created->getFullId(),
			'type' => $request->type,
			'sharedWith' => $created->getSharedWith(),
			'permissions' => $created->getPermissions(),
			'verbs' => array_values($request->terms->verbs),
		];
	}//end grantRecord()

	/**
	 * Attach extension verbs to a share.
	 *
	 * Stored as JSON in `IShare`'s attribute bag under OpenRegister's own scope,
	 * which is the extension point other Nextcloud apps use for exactly this.
	 * Values are filtered to non-empty strings so a malformed request cannot put
	 * an object or a null into the bag and have the read side trip over it.
	 *
	 * @param IShare $share The share being built.
	 * @param string[] $verbs The verbs to carry.
	 *
	 * @return void
	 */
	private function applyVerbs(IShare $share, array $verbs): void {
		$clean = array_values(
			array_unique(
				array_filter($verbs, static fn ($verb) => is_string($verb) === true && trim($verb) !== '')
			)
		);

		if (empty($clean) === true) {
			return;
		}

		$attributes = ($share->getAttributes() ?? $share->newAttributes());
		$attributes->setAttribute(
			ShareGrantAttributes::VERB_ATTRIBUTE_SCOPE,
			ShareGrantAttributes::VERB_ATTRIBUTE_KEY,
			json_encode($clean)
		);
		$share->setAttributes($attributes);
	}//end applyVerbs()

	/**
	 * Create a tokenised LINK to an object, optionally expiring and passworded.
	 *
	 * A link is a CAPABILITY, not a principal grant: it admits whoever presents
	 * the token, so it is deliberately absent from
	 * {@see ObjectGrantResolver::PRINCIPAL_SHARE_TYPES} and is never resolved by
	 * the RBAC filter. It is decided on the public entry point instead, from the
	 * token itself.
	 *
	 * That distinction is what reconciles this with ADR-006 (design Q4): the ADR
	 * says publication is a schema-level RBAC change and not a per-object data
	 * flag, and a revocable, expiring, core-issued bearer token is neither of
	 * those things.
	 *
	 * Everything about the token's lifecycle — generation, expiry enforcement,
	 * password hashing, revocation — stays core's.
	 *
	 * @param ObjectEntity   $object     The object to link.
	 * @param LinkInvitation $invitation The permissions (READ unless asked otherwise) and the link's protection:
	 *                                   an optional password and expiry (any format DateTime parses).
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException On an unresolvable folder or a bad expiry.
	 *
	 * @return array<string, mixed> The created link, including its token.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function createLink(ObjectEntity $object, LinkInvitation $invitation): array {
		$created = $this->createProtectedShare(object: $object, invitation: $invitation);

		$this->context->auditor?->recordGrant(object: $object, grant: $this->linkGrant(created: $created, sharedWith: null), admittedAs: null);

		return [
			'id' => $created->getFullId(),
			'type' => 'link',
			'token' => $created->getToken(),
			'permissions' => $created->getPermissions(),
			'expiration' => $created->getExpirationDate()?->format('c'),
			'hasPassword' => ($created->getPassword() !== null),
		];
	}//end createLink()

	/**
	 * Invite an EMAIL ADDRESS to an object.
	 *
	 * `TYPE_EMAIL` is core's account-less link addressed to an address — the
	 * Files behaviour, chosen deliberately (design Q2), because requiring the
	 * recipient to already have an account defeats inviting a colleague who does
	 * not have one.
	 *
	 * The message carries no object data: the recipient follows the invitation to
	 * reach the object, so revoking it still works after the mail has been sent.
	 * Delivery is core's mailer, not ours.
	 *
	 * @param ObjectEntity   $object     The object to share.
	 * @param LinkInvitation $invitation The address to invite, the permissions (READ unless asked otherwise) and
	 *                                   the invitation's protection: an optional password and expiry.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException On a missing address or unresolvable folder.
	 *
	 * @return array<string, mixed> The created invitation.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function inviteByEmail(ObjectEntity $object, LinkInvitation $invitation): array {
		if (filter_var(trim((string)$invitation->recipient), FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException('A valid email address is required');
		}

		$created = $this->createProtectedShare(object: $object, invitation: $invitation);

		$this->context->auditor?->recordGrant(
			object: $object,
			grant: array_replace($this->linkGrant(created: $created, sharedWith: $created->getSharedWith()), ['type' => 'email']),
			admittedAs: null
		);

		return [
			'id' => $created->getFullId(),
			'type' => 'email',
			'sharedWith' => $created->getSharedWith(),
			'token' => $created->getToken(),
			'permissions' => $created->getPermissions(),
			'expiration' => $created->getExpirationDate()?->format('c'),
		];
	}//end inviteByEmail()

	/**
	 * Build an owner-checked link-style share on the object's folder, protect it, and create it.
	 *
	 * @param ObjectEntity   $object     The object.
	 * @param LinkInvitation $invitation The recipient (null for a public link), the permissions and the protection.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException When the folder cannot be resolved or the expiry cannot be read.
	 *
	 * @return IShare The created share.
	 */
	private function createProtectedShare(ObjectEntity $object, LinkInvitation $invitation): IShare {
		$this->requireOwnerOrAdmin(object: $object);

		$permissions = $this->withoutReshare(permissions: $invitation->permissions);
		$share = $this->shareOnFolder(object: $object, shareType: $invitation->shareType(), permissions: $permissions);
		$share->setPermissions($permissions);
		if ($invitation->recipient !== null) {
			$share->setSharedWith(trim($invitation->recipient));
		}

		$invitation->protection->applyTo(share: $share);

		return $this->shares->shareManager->createShare($share);
	}//end createProtectedShare()

	/**
	 * A link-style share as the audit trail records it: no verbs, never its token.
	 *
	 * @param IShare      $created    The created share.
	 * @param string|null $sharedWith The address it is shared with, or null for a public link.
	 *
	 * @return array{id: string, type: string, sharedWith: string|null, permissions: int, verbs: array} The grant.
	 */
	private function linkGrant(IShare $created, ?string $sharedWith): array {
		return [
			'id' => $created->getFullId(),
			'type' => 'link',
			'sharedWith' => $sharedWith,
			'permissions' => $created->getPermissions(),
			'verbs' => [],
		];
	}//end linkGrant()

	/**
	 * A new share of one type on the object's folder, shared by the caller.
	 *
	 * The caller is given a view of the folder first ({@see SharerFolderAccess}),
	 * since core lets a user share a node only as far as they reach it.
	 *
	 * @param ObjectEntity $object      The object, which the caller may share.
	 * @param int          $shareType   Core share type.
	 * @param int          $permissions The core permissions the share will carry.
	 *
	 * @throws InvalidArgumentException When the folder cannot be resolved.
	 * @throws NotAuthorizedException   When there is no user session, or the share asks for more than the caller holds.
	 *
	 * @return IShare The unsaved share.
	 */
	private function shareOnFolder(ObjectEntity $object, int $shareType, int $permissions): IShare {
		$folder = $this->resolveFolder(object: $object);
		if ($folder === null) {
			throw new InvalidArgumentException('Could not resolve the object folder to share');
		}

		$uid = $this->callerUid();
		if ($uid === null) {
			throw new NotAuthorizedException(message: 'No user session');
		}

		$this->shares->sharerAccess->ensureFor(object: $object, folder: $folder, uid: $uid, permissions: $permissions);

		$share = $this->shares->shareManager->newShare();
		$share->setNode($folder);
		$share->setShareType($shareType);
		$share->setSharedBy($uid);

		return $share;
	}//end shareOnFolder()

	/**
	 * Revoke one grant.
	 *
	 * The share MUST hang on this object's own folder. Without that check an
	 * owner could revoke a share belonging to a different object by guessing its
	 * id through their own object's endpoint.
	 *
	 * @param ObjectEntity $object The object the grant belongs to.
	 * @param string $shareId The share to revoke.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException When the share is not on this object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function revoke(ObjectEntity $object, string $shareId): void {
		$admittedAs = $this->requireMayManageGrants(object: $object);

		$folder = $this->resolveFolder(object: $object);
		if ($folder === null) {
			throw new InvalidArgumentException('Could not resolve the object folder');
		}

		try {
			$share = $this->shares->shareManager->getShareById($shareId);
		} catch (Throwable $e) {
			throw new InvalidArgumentException('No such grant');
		}

		if ($share->getNodeId() !== $folder->getId()) {
			throw new InvalidArgumentException('That grant does not belong to this object');
		}

		$revoked = [
			'id' => $share->getFullId(),
			'type' => $this->typeLabel(shareType: (int)$share->getShareType()),
			'sharedWith' => $share->getSharedWith(),
			'permissions' => $share->getPermissions(),
		];

		$this->shares->shareManager->deleteShare($share);
		$this->records->grantResolver->forget();

		$this->context->auditor?->recordRevoke(object: $object, grant: $revoked, admittedAs: $admittedAs);
	}//end revoke()

	/**
	 * The label of a core share type, as the grants name it.
	 *
	 * @param int $shareType The core share type.
	 *
	 * @return string The label (`user`, `group`, `link`, ...), or the number when it has none.
	 */
	private function typeLabel(int $shareType): string {
		$label = array_search($shareType, self::LISTABLE_TYPES, true);
		if ($label === false) {
			return (string)$shareType;
		}

		return (string)$label;
	}//end typeLabel()

	/**
	 * Resolve the object's NC folder, creating it if it has none.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Folder|null The folder, or null when it cannot be resolved.
	 */
	private function resolveFolder(ObjectEntity $object): ?Folder {
		try {
			$folder = $this->shares->folders->getObjectFolder($object);
			if (($folder instanceof Folder) === true) {
				return $folder;
			}

			return null;
		} catch (Throwable $e) {
			$this->context->logger->warning(
				message: '[ObjectSharingService] Could not resolve an object folder',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveFolder()

	/**
	 * Require that the caller owns the object, or is an administrator.
	 *
	 * The same admit rule the read side uses, from the same resolver, so "who may
	 * change the sharing" and "who is admitted unconditionally" cannot drift.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @throws NotAuthorizedException When the caller is neither.
	 *
	 * @return void
	 */
	private function requireOwnerOrAdmin(ObjectEntity $object): void {
		$uid = $this->callerUid();
		$groups = [];
		if ($uid !== null) {
			$groups = $this->context->groupManager->getUserGroupIds($this->context->userSession->getUser());
		}

		if ($this->records->scopeResolver->admitsUnconditionally(
			userId: $uid,
			userGroups: $groups,
			objectOwner: $object->getOwner(),
			authorization: $object->getAuthorization()
		) === false
		) {
			throw new NotAuthorizedException(
				message: 'Only the owner or an administrator may change sharing on this object'
			);
		}
	}//end requireOwnerOrAdmin()

	/**
	 * Require that the caller may manage the grants on the object.
	 *
	 * The owner, the owning group and an administrator, as before. Beside them a
	 * holder of the organisation's `grant_manage` special right on this record
	 * ({@see GrantManageGuard}). It is asked for the three grant methods only: the scope, a public
	 * link and an e-mail invitation stay with the owner and administrators.
	 *
	 * A holder admitted by the special right alone may not grant to itself or to
	 * a group it is in. Without that refusal the right would be a read by one
	 * extra call.
	 *
	 * @param ObjectEntity          $object  The object.
	 * @param GrantedPrincipal|null $grantee The principal of a grant being added, or null.
	 *
	 * @throws NotAuthorizedException When the caller may not.
	 *
	 * @return string How the caller was admitted (GrantManageGuard::AS_OWNER or ::AS_HOLDER).
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	private function requireMayManageGrants(ObjectEntity $object, ?GrantedPrincipal $grantee = null): string {
		$uid = $this->callerUid();
		$groups = $this->callerGroups(uid: $uid);

		$admittedAs = $this->grantManage->admittedAs(caller: new GrantCaller(userId: $uid, userGroups: $groups), object: $object);
		if ($admittedAs === null) {
			throw new NotAuthorizedException(
				message: 'Only the owner or an administrator may change sharing on this object'
			);
		}

		if ($this->grantManage->letsItselfIn(
			admittedAs: $admittedAs,
			caller: new GrantCaller(userId: $uid, userGroups: $groups),
			principal: ($grantee ?? new GrantedPrincipal())
		) === true
		) {
			throw new NotAuthorizedException(
				message: 'The right to manage grants does not let its holder grant access to itself'
			);
		}

		return $admittedAs;
	}//end requireMayManageGrants()

	/**
	 * The principal a grant request lets in, by its type.
	 *
	 * @param GrantRequest $request The grant type and principal asked for.
	 *
	 * @return GrantedPrincipal The user, group or remote principal being let in.
	 */
	private function principalOf(GrantRequest $request): GrantedPrincipal {
		$principal = [$request->type => trim($request->shareWith)];

		return new GrantedPrincipal(
			user: ($principal['user'] ?? null),
			group: ($principal['group'] ?? null),
			remote: ($principal['remote'] ?? $principal['remote_group'] ?? null)
		);
	}//end principalOf()

	/**
	 * The caller's group ids, or none when anonymous.
	 *
	 * @param string|null $uid The caller's uid.
	 *
	 * @return string[] The group ids.
	 */
	private function callerGroups(?string $uid): array {
		if ($uid === null) {
			return [];
		}

		return $this->context->groupManager->getUserGroupIds($this->context->userSession->getUser());
	}//end callerGroups()

	/**
	 * Strip core's re-share bit from a requested permission mask.
	 *
	 * Task 4.4, and the half of it that `requireOwnerOrAdmin()` does NOT cover.
	 * That guard stops a recipient calling OUR endpoints — every write method here
	 * goes through it, so a recipient cannot add a principal or widen a grant
	 * through the sharing API.
	 *
	 * But an object grant is a share on the object's FOLDER, and core's Files
	 * sharing UI acts on that folder directly. With `PERMISSION_SHARE` (16) set,
	 * the recipient could re-share the folder to anyone through core, handing the
	 * object's data onward without ever touching an OpenRegister endpoint — and
	 * the resulting share would be a perfectly valid object grant, since the
	 * resolver reads grants from exactly those folder shares. The spec's "SHALL
	 * NOT be able to widen a grant, add a principal, or re-share onward" would be
	 * satisfied on paper and false in practice.
	 *
	 * So the bit is stripped rather than rejected. Rejecting would break callers
	 * that pass a convenience mask like 31 ("everything") without meaning to
	 * delegate re-sharing, and the safe reading of an ambiguous request is the
	 * narrower one — a grant narrows within what the schema permits and never
	 * widens the principal set. Re-sharing stays the owner's prerogative,
	 * exercised through `grant()`, where the owner check applies.
	 *
	 * Applied in ONE place used by both share-construction paths (`grant()` and
	 * `createProtectedShare()`, which backs links and email invitations) so the two
	 * cannot drift on the bit that matters most.
	 *
	 * @param integer $permissions The requested core permission bitmask.
	 *
	 * @return integer The mask with the re-share bit cleared.
	 */
	private function withoutReshare(int $permissions): int {
		return ($permissions & ~\OCP\Constants::PERMISSION_SHARE);
	}//end withoutReshare()

	/**
	 * The current caller's uid.
	 *
	 * @return string|null The uid, or null when anonymous.
	 */
	private function callerUid(): ?string {
		return $this->context->userSession->getUser()?->getUID();
	}//end callerUid()
}//end class
