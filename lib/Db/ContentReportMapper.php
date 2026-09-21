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
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
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
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class ContentReportMapper extends QBMapper {
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
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
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
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
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
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
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
	 * @param string|null $status Optional review filter.
	 * @param string|null $organisationId Optional organisation filter.
	 * @param int|null $limit Optional page size.
	 *
	 * @return ContentReport[] The matching reports.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function findAll(?string $status = null, ?string $organisationId = null, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC');

		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		if ($organisationId !== null && $organisationId !== '') {
			$qb->andWhere($qb->expr()->eq('organisation_id', $qb->createNamedParameter($organisationId)));
		}

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
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
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
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function update($entity): ContentReport {
		$entity->setUpdated(new DateTime());

		return parent::update(entity: $entity);
	}//end update()
}//end class
