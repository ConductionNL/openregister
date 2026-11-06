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

	use SelectByColumnTrait;

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
		return $this->findEntity(query: $this->selectWhere(column: new LinkColumn(name: 'uuid', value: $uuid)));

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

		$this->fillIdentity(set: $set);

		$now = new DateTime();
		if ($set->getCreated() === null) {
			$set->setCreated($now);
		}

		$set->setUpdated($now);

		return $this->insert(entity: $set);

	}//end createFromArray()

	/**
	 * Give a new set a uuid and the open state unless it has them.
	 *
	 * @param ConfigurationDraftSet $set The set.
	 *
	 * @return void
	 */
	private function fillIdentity(ConfigurationDraftSet $set): void {
		if ($set->getUuid() === null) {
			$set->setUuid(Uuid::v4()->toRfc4122());
		}

		if ($set->getState() === null) {
			$set->setState(ConfigurationDraftSet::STATE_OPEN);
		}
	}//end fillIdentity()

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
}//end class
