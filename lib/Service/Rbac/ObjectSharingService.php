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
     * @throws \InvalidArgumentException When the scope is not in the vocabulary.
     *
     * @return array<string, mixed> The stored authorization block after the write.
     */
    public function setScope(Register $register, Schema $schema, ObjectEntity $object, string $scope): array
    {
        $this->requireOwnerOrAdmin(object: $object);

        $valid = [ObjectScopeResolver::SCOPE_ORGANISATION, ObjectScopeResolver::SCOPE_PRIVATE];
        if (in_array($scope, $valid, true) === false) {
            throw new \InvalidArgumentException(
                'Scope must be one of: '.implode(', ', $valid)
            );
        }

        // Read-modify-write ONE key. An admin-set action override in the same
        // block must survive an owner changing the scope.
        $block = ($object->getAuthorization() ?? []);
        if (is_array($block) === false) {
            $block = [];
        }

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
                    // getFullId() is the provider-prefixed form (`ocinternal:7`)
                    // that getShareById() accepts; getId() is the bare number and
                    // does NOT round-trip. Returning the wrong one made revoke
                    // fail with "No such grant".
                    $grants[$share->getFullId()] = [
                        'id'          => $share->getFullId(),
                        'type'        => $label,
                        'sharedWith'  => $share->getSharedWith(),
                        'permissions' => $share->getPermissions(),
                        'expiration'  => $share->getExpirationDate()?->format('c'),
                    ];
                }
            }
        }

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
     *
     * @throws NotAuthorizedException When the caller is neither owner nor admin.
     * @throws \InvalidArgumentException On an unsupported type or unresolvable folder.
     *
     * @return array<string, mixed> The created grant.
     */
    public function grant(ObjectEntity $object, string $type, string $shareWith, int $permissions=1): array
    {
        $this->requireOwnerOrAdmin(object: $object);

        if (isset(self::GRANTABLE_TYPES[$type]) === false) {
            throw new \InvalidArgumentException(
                'Grant type must be one of: '.implode(', ', array_keys(self::GRANTABLE_TYPES))
            );
        }

        if (trim($shareWith) === '') {
            throw new \InvalidArgumentException('A grant needs a principal to grant to');
        }

        $folder = $this->resolveFolder(object: $object);
        if ($folder === null) {
            throw new \InvalidArgumentException('Could not resolve the object folder to share');
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
        $share->setPermissions($permissions);

        $created = $this->shareManager->createShare($share);

        // The caller may re-decide access within this same request.
        $this->grantResolver->forget();

        return [
            'id'          => $created->getFullId(),
            'type'        => $type,
            'sharedWith'  => $created->getSharedWith(),
            'permissions' => $created->getPermissions(),
        ];
    }//end grant()

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
     * @throws \InvalidArgumentException When the share is not on this object.
     *
     * @return void
     */
    public function revoke(ObjectEntity $object, string $shareId): void
    {
        $this->requireOwnerOrAdmin(object: $object);

        $folder = $this->resolveFolder(object: $object);
        if ($folder === null) {
            throw new \InvalidArgumentException('Could not resolve the object folder');
        }

        try {
            $share = $this->shareManager->getShareById($shareId);
        } catch (Throwable $e) {
            throw new \InvalidArgumentException('No such grant');
        }

        if ($share->getNodeId() !== $folder->getId()) {
            throw new \InvalidArgumentException('That grant does not belong to this object');
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
     * The current caller's uid.
     *
     * @return string|null The uid, or null when anonymous.
     */
    private function callerUid(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end callerUid()
}//end class
