<?php

/**
 * GroupObjectSourceProvider — serves the `nc-group` virtual schema's objects
 * live from the Nextcloud group directory (read-only).
 *
 * The authoritative record is the Nextcloud group; this provider projects each
 * {@see \OCP\IGroup} as a virtual ObjectEntity (uuid = gid; object =
 * {id, displayName}) and never writes back. Reads are scoped to the acting user
 * per instance policy: an admin sees every group, a plain user sees at least the
 * groups they belong to (denied == not-found, no enumeration oracle) — mirroring
 * the user-scoping approach of {@see CalDavVtodoObjectSourceProvider}.
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
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud group directory.
 */
class GroupObjectSourceProvider implements ObjectSourceProvider {
	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Nextcloud group directory (search/get/admin).
	 * @param IUserSession $userSession Acting-user session for read-scoping.
	 * @param LoggerInterface $logger Logger for read failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	public function getId(): string {
		return 'group-source';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * The Nextcloud group directory is a core service, so this provider is always
	 * available.
	 *
	 * @return bool Always true.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * MUST return null when the group is absent OR the acting user may not read
	 * it, so the two cases are indistinguishable (no enumeration oracle).
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param string $id The group gid.
	 * @param array<string, mixed> $config The object-source config block (unused).
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
		try {
			$group = $this->groupManager->get($id);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:group-source] could not read group: ' . $e->getMessage());
			return null;
		}

		if ($group === null || $this->mayRead(group: $group) === false) {
			return null;
		}

		return $this->toObjectEntity(register: $register, schema: $schema, group: $group);
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * Honours `filters.search`/`_search`, `limit` and `offset`. An admin sees
	 * every group; a plain user sees only the groups they belong to.
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param array<string, mixed> $query Query (filters/search/limit/offset).
	 * @param array<string, mixed> $config The object-source config block (unused).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
		$objects = [];
		foreach ($this->readGroups(query: $query) as $group) {
			$objects[] = $this->toObjectEntity(register: $register, schema: $schema, group: $group);
		}

		return $objects;
	}//end findAll()

	/**
	 * {@inheritDoc}
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param array<string, mixed> $query Query (filters/search).
	 * @param array<string, mixed> $config The object-source config block (unused).
	 *
	 * @return int The number of matching virtual objects.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
		return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
	}//end count()

	/**
	 * Read the groups visible to the acting user, failing closed to an empty list.
	 *
	 * Admins get the full group list (optionally filtered by a search term); a
	 * plain user gets only the groups they belong to.
	 *
	 * @param array<string, mixed> $query Query (filters/search/limit/offset).
	 *
	 * @return array<int, IGroup> The visible groups.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	private function readGroups(array $query): array {
		$acting = $this->userSession->getUser();
		if ($acting === null) {
			return [];
		}

		$search = (string)($query['filters']['search'] ?? $query['_search'] ?? $query['search'] ?? '');
		$limit = (int)($query['limit'] ?? 200);
		$offset = (int)($query['offset'] ?? 0);

		try {
			if ($this->groupManager->isAdmin($acting->getUID()) === true) {
				return array_values($this->groupManager->search($search, $limit, $offset));
			}

			// A plain user only sees the groups they belong to.
			$groups = array_values($this->groupManager->getUserGroups($acting));
			if ($search === '') {
				return $groups;
			}

			return array_values(
				array_filter(
					$groups,
					static fn (IGroup $group) => str_contains(strtolower($group->getGID()), strtolower($search)) === true
						|| str_contains(strtolower($group->getDisplayName()), strtolower($search)) === true
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:group-source] could not list groups: ' . $e->getMessage());
			return [];
		}//end try
	}//end readGroups()

	/**
	 * Whether the acting user may read the given group's projection.
	 *
	 * Admins may read any group; a plain user may read only groups they belong to.
	 *
	 * @param IGroup $group The group being read.
	 *
	 * @return bool True when the acting user may read it.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	private function mayRead(IGroup $group): bool {
		$acting = $this->userSession->getUser();
		if ($acting === null) {
			return false;
		}

		try {
			if ($this->groupManager->isAdmin($acting->getUID()) === true) {
				return true;
			}

			return $group->inGroup($acting);
		} catch (Throwable $e) {
			return false;
		}
	}//end mayRead()

	/**
	 * Map a Nextcloud group onto a non-persisted virtual ObjectEntity.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The sourced schema.
	 * @param IGroup $group The Nextcloud group.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	private function toObjectEntity(Register $register, Schema $schema, IGroup $group): ObjectEntity {
		$gid = $group->getGID();

		$data = [
			'id' => $gid,
			'displayName' => $group->getDisplayName(),
		];

		$entity = new ObjectEntity();
		$entity->setUuid($gid);
		$entity->setRegister((string)$register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject($data);

		return $entity;
	}//end toObjectEntity()
}//end class
