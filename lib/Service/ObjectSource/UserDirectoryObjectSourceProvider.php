<?php

/**
 * UserDirectoryObjectSourceProvider — serves the `nc-user` virtual schema's
 * objects live from the Nextcloud user directory (read-only).
 *
 * The authoritative record is the Nextcloud user account; this provider projects
 * each {@see \OCP\IUser} as a virtual ObjectEntity (uuid = uid; object =
 * {id, displayName, email}) and never writes back. Reads are scoped to the acting
 * user per instance policy: an admin sees every user, a plain user sees at least
 * themselves (denied == not-found, no enumeration oracle) — mirroring the
 * user-scoping approach of {@see CalDavVtodoObjectSourceProvider}.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud user directory.
 */
class UserDirectoryObjectSourceProvider implements ObjectSourceProvider
{
    /**
     * Constructor.
     *
     * @param IUserManager    $userManager  Nextcloud user directory (search/get/count).
     * @param IUserSession    $userSession  Acting-user session for read-scoping.
     * @param IGroupManager   $groupManager Group manager, used only for the admin check.
     * @param LoggerInterface $logger       Logger for read failures.
     *
     * @return void
     */
    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    public function getId(): string
    {
        return 'user-directory-source';
    }//end getId()

    /**
     * {@inheritDoc}
     *
     * The Nextcloud user directory is a core service, so this provider is always
     * available.
     *
     * @return bool Always true.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    /**
     * {@inheritDoc}
     *
     * MUST return null when the user is absent OR the acting user may not read it,
     * so the two cases are indistinguishable (no enumeration oracle).
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param string               $id       The user uid.
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return ObjectEntity|null The virtual object, or null when absent/denied.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
    {
        try {
            $user = $this->userManager->get($id);
        } catch (Throwable $e) {
            $this->logger->warning('[ObjectSource:user-directory-source] could not read user: '.$e->getMessage());
            return null;
        }

        if ($user === null || $this->mayRead(user: $user) === false) {
            return null;
        }

        return $this->toObjectEntity(register: $register, schema: $schema, user: $user);
    }//end find()

    /**
     * {@inheritDoc}
     *
     * Honours `filters.search`/`_search`, `limit` and `offset`. An admin sees
     * every user; a plain user sees only themselves.
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/search/limit/offset).
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return ObjectEntity[] The matching virtual objects.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
    {
        $objects = [];
        foreach ($this->readUsers(query: $query) as $user) {
            $objects[] = $this->toObjectEntity(register: $register, schema: $schema, user: $user);
        }

        return $objects;
    }//end findAll()

    /**
     * {@inheritDoc}
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/search).
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return int The number of matching virtual objects.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
    {
        return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
    }//end count()

    /**
     * Read the users visible to the acting user, failing closed to an empty list.
     *
     * Admins get the full directory (optionally filtered by a search term); a
     * plain user gets only their own account.
     *
     * @param array<string, mixed> $query Query (filters/search/limit/offset).
     *
     * @return array<int, IUser> The visible users.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    private function readUsers(array $query): array
    {
        $acting = $this->userSession->getUser();
        if ($acting === null) {
            return [];
        }

        $search = (string) ($query['filters']['search'] ?? $query['_search'] ?? $query['search'] ?? '');
        $limit  = (int) ($query['limit'] ?? 200);
        $offset = (int) ($query['offset'] ?? 0);

        try {
            // A plain user only ever sees their own account.
            if ($this->isAdmin(user: $acting) === false) {
                if ($search !== '' && str_contains(strtolower($acting->getUID()), strtolower($search)) === false) {
                    return [];
                }

                return [$acting];
            }

            return array_values($this->userManager->search($search, $limit, $offset));
        } catch (Throwable $e) {
            $this->logger->warning('[ObjectSource:user-directory-source] could not list users: '.$e->getMessage());
            return [];
        }
    }//end readUsers()

    /**
     * Whether the acting user may read the given user's projection.
     *
     * Admins may read any user; a plain user may read only themselves.
     *
     * @param IUser $user The user being read.
     *
     * @return bool True when the acting user may read it.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    private function mayRead(IUser $user): bool
    {
        $acting = $this->userSession->getUser();
        if ($acting === null) {
            return false;
        }

        if ($this->isAdmin(user: $acting) === true) {
            return true;
        }

        return ($acting->getUID() === $user->getUID());
    }//end mayRead()

    /**
     * Whether the given user is a Nextcloud administrator.
     *
     * @param IUser $user The user to check.
     *
     * @return bool True when the user is an admin.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    private function isAdmin(IUser $user): bool
    {
        try {
            return $this->groupManager->isAdmin($user->getUID());
        } catch (Throwable $e) {
            return false;
        }
    }//end isAdmin()

    /**
     * Map a Nextcloud user onto a non-persisted virtual ObjectEntity.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The sourced schema.
     * @param IUser    $user     The Nextcloud user.
     *
     * @return ObjectEntity The virtual object (never saved).
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
     */
    private function toObjectEntity(Register $register, Schema $schema, IUser $user): ObjectEntity
    {
        $uid = $user->getUID();

        $data = [
            'id'          => $uid,
            'displayName' => $user->getDisplayName(),
            'email'       => ($user->getEMailAddress() ?? ''),
        ];

        $entity = new ObjectEntity();
        $entity->setUuid($uid);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($data);

        return $entity;
    }//end toObjectEntity()
}//end class
