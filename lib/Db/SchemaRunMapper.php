<?php

/**
 * Mapper for SchemaRun entities.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class SchemaRunMapper
 *
 * @template-extends QBMapper<SchemaRun>
 */
class SchemaRunMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_schema_runs', entityClass: SchemaRun::class);

	}//end __construct()

	/**
	 * Find a run by id.
	 *
	 * @param int $id The run id.
	 *
	 * @return SchemaRun The run.
	 *
	 * @throws DoesNotExistException When not found.
	 */
	public function find(int $id): SchemaRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find runs for a schema, newest first.
	 *
	 * @param int $schemaId The schema id.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return SchemaRun[] The runs.
	 */
	public function findBySchema(int $schemaId, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities(query: $qb);
	}//end findBySchema()

	/**
	 * Find the active (blocking) run for a schema, if any.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return SchemaRun|null The active run, or null.
	 */
	public function findActiveForSchema(int $schemaId): ?SchemaRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(SchemaRun::ACTIVE_STATES, IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findActiveForSchema()

	/**
	 * Create a run from an array of values, assigning a UUID and timestamps.
	 *
	 * @param array<string, mixed> $data The run values.
	 *
	 * @return SchemaRun The persisted run.
	 */
	public function createFromArray(array $data): SchemaRun {
		$run = new SchemaRun();
		$run->hydrate($data);

		if ($run->getUuid() === null) {
			$run->setUuid(Uuid::v4()->toRfc4122());
		}

		$now = new DateTime();
		if ($run->getCreated() === null) {
			$run->setCreated($now);
		}

		$run->setUpdated($now);

		return $this->insert(entity: $run);
	}//end createFromArray()

	/**
	 * Persist run progress/state changes, refreshing the updated timestamp.
	 *
	 * @param SchemaRun $run The run to persist.
	 *
	 * @return SchemaRun The updated run.
	 */
	public function save(SchemaRun $run): SchemaRun {
		$run->setUpdated(new DateTime());

		return $this->update(entity: $run);
	}//end save()
}//end class
