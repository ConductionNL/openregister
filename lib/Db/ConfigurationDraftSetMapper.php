<?php

/**
 * Mapper for ConfigurationDraftSet entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConfigurationDraftSetMapper
 *
 * @template-extends QBMapper<ConfigurationDraftSet>
 */
class ConfigurationDraftSetMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_config_sets',
			entityClass: ConfigurationDraftSet::class
		);

	}//end __construct()

	/**
	 * Find a set by its uuid.
	 *
	 * @param string $uuid The set uuid.
	 *
	 * @return ConfigurationDraftSet The set.
	 *
	 * @throws DoesNotExistException When no such set exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findByUuid(string $uuid): ConfigurationDraftSet {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);

	}//end findByUuid()

	/**
	 * List sets, newest first, optionally filtered by state.
	 *
	 * @param string|null $state Optional state filter.
	 * @param integer     $limit How many rows at most.
	 *
	 * @return ConfigurationDraftSet[] The sets.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAllSets(?string $state = null, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		if ($state !== null) {
			$qb->where($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		return $this->findEntities(query: $qb);

	}//end findAllSets()

	/**
	 * The open set an author already has, when they have one.
	 *
	 * @param string $author The author's user id.
	 *
	 * @return ConfigurationDraftSet|null The open set, or null.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findOpenForAuthor(string $author): ?ConfigurationDraftSet {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($author)))
			->andWhere(
				$qb->expr()->eq('state', $qb->createNamedParameter(ConfigurationDraftSet::STATE_OPEN))
			)
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $exception) {
			return null;
		}

	}//end findOpenForAuthor()

	/**
	 * Create a set, assigning a uuid, the open state and timestamps.
	 *
	 * @param array<string, mixed> $data The set values.
	 *
	 * @return ConfigurationDraftSet The persisted set.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function createFromArray(array $data): ConfigurationDraftSet {
		$set = new ConfigurationDraftSet();
		$set->hydrate($data);

		if ($set->getUuid() === null) {
			$set->setUuid(Uuid::v4()->toRfc4122());
		}

		if ($set->getState() === null) {
			$set->setState(ConfigurationDraftSet::STATE_OPEN);
		}

		$now = new DateTime();
		if ($set->getCreated() === null) {
			$set->setCreated($now);
		}

		$set->setUpdated($now);

		return $this->insert(entity: $set);

	}//end createFromArray()

	/**
	 * Persist a set, refreshing the updated timestamp.
	 *
	 * @param ConfigurationDraftSet $set The set to persist.
	 *
	 * @return ConfigurationDraftSet The updated set.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function save(ConfigurationDraftSet $set): ConfigurationDraftSet {
		$set->setUpdated(new DateTime());

		return $this->update(entity: $set);

	}//end save()

	/**
	 * Count the sets in one state.
	 *
	 * @param string $state The state to count.
	 *
	 * @return integer How many sets are in that state.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function countInState(string $state): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['total'] ?? 0);

	}//end countInState()
}//end class
