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

use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud group directory.
 */
class GroupObjectSourceProvider implements ObjectSourceProvider {

	use CountsThroughFindAll;

	/**
	 * The acting-user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger for read failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IGroupManager      $groupManager Nextcloud group directory (search/get/admin).
	 * @param LinkSessionRuntime $session      The acting-user session and the logger for read failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		LinkSessionRuntime $session,
	) {
		$this->userSession = $session->userSession;
		$this->logger = $session->logger;
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
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string $id The group gid.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	public function find(SourcedTable $source, string $id): ?ObjectEntity {
		return $this->findGroup(binding: $source->binding(), id: $id);
	}//end find()

	/**
	 * Read one object for the bound register and schema.
	 *
	 * @param SourcedObjectBinding $binding The register and schema the objects belong to.
	 * @param string               $id      The object id.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 */
	private function findGroup(SourcedObjectBinding $binding, string $id): ?ObjectEntity {
		try {
			$group = $this->groupManager->get($id);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:group-source] could not read group: ' . $e->getMessage());
			return null;
		}

		if ($group === null || $this->mayRead(group: $group) === false) {
			return null;
		}

		return $this->toObjectEntity(binding: $binding, group: $group);
	}//end findGroup()

	/**
	 * {@inheritDoc}
	 *
	 * Honours `filters.search`/`_search`, `limit` and `offset`. An admin sees
	 * every group; a plain user sees only the groups they belong to.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $query Query (filters/search/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	public function findAll(SourcedTable $source, array $query = []): array {
		return $this->listGroups(binding: $source->binding(), query: $query);
	}//end findAll()

	/**
	 * Read one page of objects for the bound register and schema.
	 *
	 * @param SourcedObjectBinding $binding The register and schema the objects belong to.
	 * @param array<string, mixed> $query   Query (filters/search/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 */
	private function listGroups(SourcedObjectBinding $binding, array $query): array {
		$objects = [];
		foreach ($this->readGroups(query: $query) as $group) {
			$objects[] = $this->toObjectEntity(binding: $binding, group: $group);
		}

		return $objects;
	}//end listGroups()

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
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param IGroup               $group   The Nextcloud group.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
	 */
	private function toObjectEntity(SourcedObjectBinding $binding, IGroup $group): ObjectEntity {
		$gid = $group->getGID();

		return $binding->toEntity(uuid: $gid, data: ['id' => $gid, 'displayName' => $group->getDisplayName()]);
	}//end toObjectEntity()
}//end class
