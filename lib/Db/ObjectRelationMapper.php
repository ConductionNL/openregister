<?php

/**
 * Mapper for ObjectRelation rows.
 *
 * Reads go in one of two directions and the table is indexed for both, because
 * the graph walk alternates: from a node, the rows it is the source of and the
 * rows it is the target of are the same edge seen from two ends.
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
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class ObjectRelationMapper
 *
 * @template-extends QBMapper<ObjectRelation>
 */
class ObjectRelationMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_object_relations',
			entityClass: ObjectRelation::class
		);

	}//end __construct()

	/**
	 * The rows an object is the source of.
	 *
	 * @param string $sourceUuid The object's uuid.
	 * @param string|null $kind Optional `object` / `external` filter.
	 *
	 * @return ObjectRelation[] The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findBySource(string $sourceUuid, ?string $kind = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_uuid', $qb->createNamedParameter($sourceUuid)))
			->orderBy('id', 'ASC');

		if ($kind !== null) {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}

		return $this->findEntities(query: $qb);
	}//end findBySource()

	/**
	 * The rows an object is the target of.
	 *
	 * @param string $targetUuid The object's uuid.
	 *
	 * @return ObjectRelation[] The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findByTarget(string $targetUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('target_uuid', $qb->createNamedParameter($targetUuid)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByTarget()

	/**
	 * Every row touching any of a set of objects, in either direction.
	 *
	 * One query per graph level rather than one per node: a level of forty
	 * nodes is one round trip, which is the difference between a graph read
	 * that returns and one that does not.
	 *
	 * @param array<int, string> $uuids The objects at this level.
	 *
	 * @return ObjectRelation[] The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findTouching(array $uuids): array {
		if ($uuids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$parameter = $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY);
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->in('source_uuid', $parameter),
					$qb->expr()->in('target_uuid', $parameter)
				)
			)
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findTouching()

	/**
	 * One row by its uuid.
	 *
	 * @param string $uuid The row's uuid.
	 *
	 * @return ObjectRelation The row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row has that uuid.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When more than one does.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findByUuid(string $uuid): ObjectRelation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * The rows one text anchor wrote.
	 *
	 * @param string $sourceUuid The object the text belongs to.
	 * @param string $anchor The anchor.
	 *
	 * @return ObjectRelation[] The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findByAnchor(string $sourceUuid, string $anchor): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_uuid', $qb->createNamedParameter($sourceUuid)))
			->andWhere($qb->expr()->eq('anchor', $qb->createNamedParameter($anchor)))
			->andWhere($qb->expr()->eq('origin', $qb->createNamedParameter(ObjectRelation::ORIGIN_PROSE)));

		return $this->findEntities(query: $qb);
	}//end findByAnchor()

	/**
	 * Create a row from an array of values.
	 *
	 * @param array<string, mixed> $data The row's values.
	 *
	 * @return ObjectRelation The persisted row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function createFromArray(array $data): ObjectRelation {
		$relation = new ObjectRelation();
		$relation->hydrate($data);

		if ($relation->getUuid() === null) {
			$relation->setUuid(Uuid::v4()->toRfc4122());
		}

		$now = new DateTime();
		if ($relation->getCreated() === null) {
			$relation->setCreated($now);
		}

		$relation->setUpdated($now);

		return $this->insert(entity: $relation);
	}//end createFromArray()

	/**
	 * Persist a row, refreshing the updated timestamp.
	 *
	 * @param ObjectRelation $relation The row.
	 *
	 * @return ObjectRelation The updated row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function save(ObjectRelation $relation): ObjectRelation {
		$relation->setUpdated(new DateTime());

		return $this->update(entity: $relation);
	}//end save()

	/**
	 * Delete every row one text anchor wrote.
	 *
	 * Deleting the text deletes exactly the rows that text wrote: the anchor
	 * and the prose origin together, never one of the two, so a row somebody
	 * added by hand on the same object survives its neighbour's text going.
	 *
	 * @param string $sourceUuid The object the text belongs to.
	 * @param string $anchor The anchor.
	 *
	 * @return int The number of deleted rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function deleteByAnchor(string $sourceUuid, string $anchor): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('source_uuid', $qb->createNamedParameter($sourceUuid)))
			->andWhere($qb->expr()->eq('anchor', $qb->createNamedParameter($anchor)))
			->andWhere($qb->expr()->eq('origin', $qb->createNamedParameter(ObjectRelation::ORIGIN_PROSE)));

		return $qb->executeStatement();
	}//end deleteByAnchor()

	/**
	 * Delete every row touching an object, in either direction.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return int The number of deleted rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function deleteByObject(string $uuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('source_uuid', $qb->createNamedParameter($uuid)),
					$qb->expr()->eq('target_uuid', $qb->createNamedParameter($uuid))
				)
			);

		return $qb->executeStatement();
	}//end deleteByObject()
}//end class
