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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owner-checked writes for an object's scope and its per-object grants.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The split is deliberate and each
 * dependency is load-bearing: MagicMapper + IDBConnection perform the ONE targeted
 * column write (the object write path omits `_authorization` on purpose, so a save
 * cannot be used here); FolderManagementHandler + IManager + IShare + Folder are
 * core's share surface, which owns the grant record; ObjectScopeResolver is the
 * shared vocabulary AND the shared owner-or-admin rule, so this cannot drift from
 * the read side; ObjectGrantResolver is only asked to drop its per-request memo
 * after a write; IUserSession + IGroupManager resolve the caller for the guard;
 * Register/Schema/ObjectEntity are the addressed row. Mirrors the reasoned
 * suppression on {@see \OCA\OpenRegister\Service\ShareLinkService}, which spans the
 * same core-share types for the same reason.
 */
class ObjectSharingService
{

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
        'user'         => IShare::TYPE_USER,
        'group'        => IShare::TYPE_GROUP,
        'remote'       => IShare::TYPE_REMOTE,
        'remote_group' => IShare::TYPE_REMOTE_GROUP,
    ];

    /**
     * Constructor.
     *
     * @param MagicMapper             $mapper        Object mapper.
     * @param IDBConnection           $db            Database, for the targeted scope write.
     * @param IUserSession            $userSession   Resolves the caller.
     * @param IGroupManager           $groupManager  Resolves the caller's groups.
     * @param FolderManagementHandler $folders       Resolves an object's NC folder.
     * @param ObjectScopeResolver     $scopeResolver The scope vocabulary.
     * @param ObjectGrantResolver     $grantResolver The grant resolver, to drop its per-request memo.
     * @param IManager                $shareManager  Core share manager.
     * @param LoggerInterface         $logger        Logger.
     */
    public function __construct(
        private readonly MagicMapper $mapper,
        private readonly IDBConnection $db,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly FolderManagementHandler $folders,
        private readonly ObjectScopeResolver $scopeResolver,
        private readonly ObjectGrantResolver $grantResolver,
        private readonly IManager $shareManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Set one object's scope, as its owner or an administrator.
     *
     * @param Register     $register The register.
     * @param Schema       $schema   The schema.
     * @param ObjectEntity $object   The object.
     * @param string       $scope    The requested scope.
     *
     * @throws NotAuthorizedException When the caller is neither owner nor admin.
     * @throws InvalidArgumentException When the scope is not in the vocabulary.
     *
     * @return array<string, mixed> The stored authorization block after the write.
     */
    public function setScope(Register $register, Schema $schema, ObjectEntity $object, string $scope): array
    {
        $this->requireOwnerOrAdmin(object: $object);

        $valid = [ObjectScopeResolver::SCOPE_ORGANISATION, ObjectScopeResolver::SCOPE_PRIVATE];
        if (in_array($scope, $valid, true) === false) {
            throw new InvalidArgumentException(
                'Scope must be one of: '.implode(', ', $valid)
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

        $this->writeAuthorizationBlock(
            register: $register,
            schema: $schema,
            objectUuid: (string) $object->getUuid(),
            block: $block
        );

        return $block;
    }//end setScope()

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
     */
    public function listGrants(ObjectEntity $object): array
    {
        $this->requireOwnerOrAdmin(object: $object);

        $folder = $this->resolveFolder(object: $object);
        if ($folder === null) {
            return [];
        }

        // `getSharesBy()` returns shares CREATED BY the uid it is given, so
        // listing as the caller alone would show an administrator nothing on
        // somebody else's object — the grants were made by the owner. Ask for
        // both, deduplicated by share id.
        $sharers = array_values(
            array_unique(
                array_filter([$object->getOwner(), $this->callerUid()])
            )
        );

        $grants = [];
        foreach ($sharers as $sharer) {
            foreach (self::GRANTABLE_TYPES as $label => $shareType) {
                try {
                    $shares = $this->shareManager->getSharesBy($sharer, $shareType, $folder, false, -1);
                } catch (Throwable $e) {
                    continue;
                }

                foreach ($shares as $share) {
                    // The provider-prefixed form (`ocinternal:7`) is what
                    // getShareById() accepts; getId() is the bare number and does
                    // NOT round-trip. Returning the wrong one made revoke fail
                    // with "No such grant".
                    $grants[$share->getFullId()] = [
                        'id'          => $share->getFullId(),
                        'type'        => $label,
                        'sharedWith'  => $share->getSharedWith(),
                        'permissions' => $share->getPermissions(),
                        'expiration'  => $share->getExpirationDate()?->format('c'),
                    ];
                }//end foreach
            }//end foreach
        }//end foreach

        return array_values($grants);
    }//end listGrants()

    /**
     * Grant one principal access to one object.
     *
     * The grant is a real Nextcloud share on the object's folder, so core owns
     * the record and its whole lifecycle (design D1).
     *
     * @param ObjectEntity $object      The object to share.
     * @param string       $type        One of the GRANTABLE_TYPES keys.
     * @param string       $shareWith   The principal to grant.
     * @param integer      $permissions Core permission bitmask.
     * @param string[]     $verbs       Extension verbs (e.g. `use`, `run`) carried in the share's
     *                                  attribute bag. Core's bitmask has no room for a verb it does
     *                                  not define, so ADR-010 puts them here rather than widening
     *                                  the RBAC vocabulary.
     *
     * @throws NotAuthorizedException When the caller is neither owner nor admin.
     * @throws InvalidArgumentException On an unsupported type or unresolvable folder.
     *
     * @return array<string, mixed> The created grant.
     */
    public function grant(
        ObjectEntity $object,
        string $type,
        string $shareWith,
        int $permissions=1,
        array $verbs=[]
    ): array {
        $this->requireOwnerOrAdmin(object: $object);

        if (isset(self::GRANTABLE_TYPES[$type]) === false) {
            throw new InvalidArgumentException(
                'Grant type must be one of: '.implode(', ', array_keys(self::GRANTABLE_TYPES))
            );
        }

        if (trim($shareWith) === '') {
            throw new InvalidArgumentException('A grant needs a principal to grant to');
        }

        $folder = $this->resolveFolder(object: $object);
        if ($folder === null) {
            throw new InvalidArgumentException('Could not resolve the object folder to share');
        }

        $uid = $this->callerUid();
        if ($uid === null) {
            throw new NotAuthorizedException(message: 'No user session');
        }

        $share = $this->shareManager->newShare();
        $share->setNode($folder);
        $share->setShareType(self::GRANTABLE_TYPES[$type]);
        $share->setSharedWith(trim($shareWith));
        $share->setSharedBy($uid);
        $share->setPermissions($this->withoutReshare(permissions: $permissions));

        $this->applyVerbs(share: $share, verbs: $verbs);

        $created = $this->shareManager->createShare($share);

        // The caller may re-decide access within this same request.
        $this->grantResolver->forget();

        return [
            'id'          => $created->getFullId(),
            'type'        => $type,
            'sharedWith'  => $created->getSharedWith(),
            'permissions' => $created->getPermissions(),
            'verbs'       => array_values($verbs),
        ];
    }//end grant()

    /**
     * Attach extension verbs to a share.
     *
     * Stored as JSON in `IShare`'s attribute bag under OpenRegister's own scope,
     * which is the extension point other Nextcloud apps use for exactly this.
     * Values are filtered to non-empty strings so a malformed request cannot put
     * an object or a null into the bag and have the read side trip over it.
     *
     * @param IShare   $share The share being built.
     * @param string[] $verbs The verbs to carry.
     *
     * @return void
     */
    private function applyVerbs(IShare $share, array $verbs): void
    {
        $clean = array_values(
            array_unique(
                array_filter($verbs, static fn($verb) => is_string($verb) === true && trim($verb) !== '')
            )
        );

        if (empty($clean) === true) {
            return;
        }

        $attributes = ($share->getAttributes() ?? $share->newAttributes());
        $attributes->setAttribute(
            ObjectGrantResolver::VERB_ATTRIBUTE_SCOPE,
            ObjectGrantResolver::VERB_ATTRIBUTE_KEY,
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
     * @param ObjectEntity $object      The object to link.
     * @param integer      $permissions Core permission bitmask; defaults to READ.
     * @param string|null  $password    Optional password.
     * @param string|null  $expiration  Optional expiry, any format DateTime parses.
     *
     * @throws NotAuthorizedException When the caller is neither owner nor admin.
     * @throws InvalidArgumentException On an unresolvable folder or a bad expiry.
     *
     * @return array<string, mixed> The created link, including its token.
     */
    public function createLink(
        ObjectEntity $object,
        int $permissions=1,
        ?string $password=null,
        ?string $expiration=null
    ): array {
        $share = $this->newFolderShare(
            object: $object,
            shareType: IShare::TYPE_LINK,
            shareWith: null,
            permissions: $permissions
        );

        $this->applyLinkOptions(share: $share, password: $password, expiration: $expiration);

        $created = $this->shareManager->createShare($share);

        return [
            'id'          => $created->getFullId(),
            'type'        => 'link',
            'token'       => $created->getToken(),
            'permissions' => $created->getPermissions(),
            'expiration'  => $created->getExpirationDate()?->format('c'),
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
     * @param ObjectEntity $object      The object to share.
     * @param string       $email       The address to invite.
     * @param integer      $permissions Core permission bitmask; defaults to READ.
     * @param string|null  $password    Optional password.
     * @param string|null  $expiration  Optional expiry.
     *
     * @throws NotAuthorizedException When the caller is neither owner nor admin.
     * @throws InvalidArgumentException On a missing address or unresolvable folder.
     *
     * @return array<string, mixed> The created invitation.
     */
    public function inviteByEmail(
        ObjectEntity $object,
        string $email,
        int $permissions=1,
        ?string $password=null,
        ?string $expiration=null
    ): array {
        if (filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email address is required');
        }

        $share = $this->newFolderShare(
            object: $object,
            shareType: IShare::TYPE_EMAIL,
            shareWith: trim($email),
            permissions: $permissions
        );

        $this->applyLinkOptions(share: $share, password: $password, expiration: $expiration);

        $created = $this->shareManager->createShare($share);

        return [
            'id'          => $created->getFullId(),
            'type'        => 'email',
            'sharedWith'  => $created->getSharedWith(),
            'token'       => $created->getToken(),
            'permissions' => $created->getPermissions(),
            'expiration'  => $created->getExpirationDate()?->format('c'),
        ];
    }//end inviteByEmail()

    /**
     * Build an owner-checked share on the object's folder.
     *
     * @param ObjectEntity $object      The object.
     * @param integer      $shareType   Core share type.
     * @param string|null  $shareWith   Principal or address, or null for a link.
     * @param integer      $permissions Core permission bitmask.
     *
     * @throws NotAuthorizedException When the caller is neither owner nor admin.
     * @throws InvalidArgumentException When the folder cannot be resolved.
     *
     * @return IShare The unsaved share.
     */
    private function newFolderShare(
        ObjectEntity $object,
        int $shareType,
        ?string $shareWith,
        int $permissions
    ): IShare {
        $this->requireOwnerOrAdmin(object: $object);

        $folder = $this->resolveFolder(object: $object);
        if ($folder === null) {
            throw new InvalidArgumentException('Could not resolve the object folder to share');
        }

        $uid = $this->callerUid();
        if ($uid === null) {
            throw new NotAuthorizedException(message: 'No user session');
        }

        $share = $this->shareManager->newShare();
        $share->setNode($folder);
        $share->setShareType($shareType);
        $share->setSharedBy($uid);

        // A share must never exceed what the sharer holds. The owner-or-admin
        // check above establishes the sharer can reach the object at all; core
        // additionally clamps against the node's own permissions when the share
        // is created, so a wider request cannot become a wider share.
        $share->setPermissions($this->withoutReshare(permissions: $permissions));

        if ($shareWith !== null) {
            $share->setSharedWith($shareWith);
        }

        return $share;
    }//end newFolderShare()

    /**
     * Apply the optional password and expiry to a link-style share.
     *
     * @param IShare      $share      The share being built.
     * @param string|null $password   Optional password.
     * @param string|null $expiration Optional expiry.
     *
     * @throws InvalidArgumentException When the expiry cannot be parsed.
     *
     * @return void
     */
    private function applyLinkOptions(IShare $share, ?string $password, ?string $expiration): void
    {
        if ($password !== null && trim($password) !== '') {
            $share->setPassword($password);
        }

        if ($expiration === null || trim($expiration) === '') {
            return;
        }

        try {
            $share->setExpirationDate(new DateTime($expiration));
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Could not read that expiry date');
        }
    }//end applyLinkOptions()

    /**
     * Revoke one grant.
     *
     * The share MUST hang on this object's own folder. Without that check an
     * owner could revoke a share belonging to a different object by guessing its
     * id through their own object's endpoint.
     *
     * @param ObjectEntity $object  The object the grant belongs to.
     * @param string       $shareId The share to revoke.
     *
     * @throws NotAuthorizedException When the caller is neither owner nor admin.
     * @throws InvalidArgumentException When the share is not on this object.
     *
     * @return void
     */
    public function revoke(ObjectEntity $object, string $shareId): void
    {
        $this->requireOwnerOrAdmin(object: $object);

        $folder = $this->resolveFolder(object: $object);
        if ($folder === null) {
            throw new InvalidArgumentException('Could not resolve the object folder');
        }

        try {
            $share = $this->shareManager->getShareById($shareId);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('No such grant');
        }

        if ($share->getNodeId() !== $folder->getId()) {
            throw new InvalidArgumentException('That grant does not belong to this object');
        }

        $this->shareManager->deleteShare($share);
        $this->grantResolver->forget();
    }//end revoke()

    /**
     * Write the authorization block for one object.
     *
     * A targeted single-column UPDATE, deliberately NOT a save through the
     * object write path: that path omits the column so an ordinary save carries
     * the stored value forward, which is what stops a routine update from
     * destroying per-object RBAC.
     *
     * @param Register             $register   The register.
     * @param Schema               $schema     The schema.
     * @param string               $objectUuid The object UUID.
     * @param array<string, mixed> $block      The block to store.
     *
     * @return void
     */
    private function writeAuthorizationBlock(
        Register $register,
        Schema $schema,
        string $objectUuid,
        array $block
    ): void {
        $table = $this->mapper->getTableNameForRegisterSchema($register, $schema);

        $qb = $this->db->getQueryBuilder();
        $qb->update($table)
            ->set('_authorization', $qb->createNamedParameter(json_encode($block)))
            ->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($objectUuid)));
        $qb->executeStatement();

        $this->logger->info(
            message: '[ObjectSharingService] Wrote the authorization block for an object',
            context: [
                'file'  => __FILE__,
                'line'  => __LINE__,
                'uuid'  => $objectUuid,
                'scope' => ($block[ObjectScopeResolver::SCOPE_KEY] ?? null),
            ]
        );
    }//end writeAuthorizationBlock()

    /**
     * Resolve the object's NC folder, creating it if it has none.
     *
     * @param ObjectEntity $object The object.
     *
     * @return Folder|null The folder, or null when it cannot be resolved.
     */
    private function resolveFolder(ObjectEntity $object): ?Folder
    {
        try {
            $folder = $this->folders->getObjectFolder($object);
            if (($folder instanceof Folder) === true) {
                return $folder;
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->warning(
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
    private function requireOwnerOrAdmin(ObjectEntity $object): void
    {
        $uid    = $this->callerUid();
        $groups = [];
        if ($uid !== null) {
            $groups = $this->groupManager->getUserGroupIds($this->userSession->getUser());
        }

        if ($this->scopeResolver->admitsUnconditionally(
                userId: $uid,
                userGroups: $groups,
                objectOwner: $object->getOwner()
            ) === false
        ) {
            throw new NotAuthorizedException(
                message: 'Only the owner or an administrator may change sharing on this object'
            );
        }
    }//end requireOwnerOrAdmin()

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
     * `newFolderShare()`, which backs links and email invitations) so the two
     * cannot drift on the bit that matters most.
     *
     * @param integer $permissions The requested core permission bitmask.
     *
     * @return integer The mask with the re-share bit cleared.
     */
    private function withoutReshare(int $permissions): int
    {
        return ($permissions & ~\OCP\Constants::PERMISSION_SHARE);
    }//end withoutReshare()

    /**
     * The current caller's uid.
     *
     * @return string|null The uid, or null when anonymous.
     */
    private function callerUid(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end callerUid()
}//end class
