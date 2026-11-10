<?php

/**
 * Mapper for reported content and the copies taken of it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
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
 * Reads and writes content reports.
 *
 * @template-extends QBMapper<ContentReport>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
class ContentReportMapper extends QBMapper implements ContentReportMapperInterface {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_content_reports',
			entityClass: ContentReport::class
		);
	}//end __construct()

	/**
	 * Find by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return ContentReport The report.
	 *
	 * @throws DoesNotExistException When no row matches the id.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function find(int $id): ContentReport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find by uuid.
	 *
	 * @param string $uuid The report uuid.
	 *
	 * @return ContentReport|null Null when no row matches.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function findByUuid(string $uuid): ?ContentReport {
		if ($uuid === '') {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByUuid()

	/**
	 * Every report filed against one object, newest first.
	 *
	 * Looked up by uuid rather than by row id on purpose: the object is gone by
	 * the time this matters most, and its row id is gone with it.
	 *
	 * @param string $objectUuid The reported object's uuid.
	 *
	 * @return ContentReport[] The reports.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function findByObjectUuid(string $objectUuid): array {
		if ($objectUuid === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * List reports, newest first, optionally filtered.
	 *
	 * @param ListQuery $query Optional `status` and `organisation_id` filters (empty is no filter) and the page size
	 *                         (unbounded or not positive is no limit).
	 *
	 * @return ContentReport[] The matching reports.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function findAll(ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC');

		foreach (['status', 'organisation_id'] as $column) {
			$value = ($query->filters[$column] ?? null);
			if ($value !== null && $value !== '') {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
			}
		}

		$limit = $query->limitOrNull();
		if ($limit !== null && $limit > 0) {
			$qb->setMaxResults($limit);
		}

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * Insert a report, filling the uuid and the timestamps.
	 *
	 * @param ContentReport $entity The report to insert.
	 *
	 * @return ContentReport The persisted report.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function insert($entity): ContentReport {
		if ($entity->getUuid() === null || $entity->getUuid() === '') {
			$entity->setUuid((string)Uuid::v4());
		}

		$now = new DateTime();
		if ($entity->getCreated() === null) {
			$entity->setCreated($now);
		}

		$entity->setUpdated($now);

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Update a report, moving its change time.
	 *
	 * @param ContentReport $entity The report to update.
	 *
	 * @return ContentReport The persisted report.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function update($entity): ContentReport {
		$entity->setUpdated(new DateTime());

		return parent::update(entity: $entity);
	}//end update()
}//end class
