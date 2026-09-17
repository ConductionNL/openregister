<?php

/**
 * Mapper for SubjectExport entities.
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
 * Class SubjectExportMapper
 *
 * @template-extends QBMapper<SubjectExport>
 */
class SubjectExportMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_subject_exports',
			entityClass: SubjectExport::class
		);

	}//end __construct()

	/**
	 * Find an export by its numeric id.
	 *
	 * @param int $id The export id.
	 *
	 * @return SubjectExport The export.
	 *
	 * @throws DoesNotExistException When no such export exists.
	 */
	public function find(int $id): SubjectExport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find an export by its uuid.
	 *
	 * @param string $uuid The export uuid.
	 *
	 * @return SubjectExport The export.
	 *
	 * @throws DoesNotExistException When no such export exists.
	 */
	public function findByUuid(string $uuid): SubjectExport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * List the exports one account asked for, newest first.
	 *
	 * @param string      $requestedBy The requester's uid.
	 * @param string|null $status      Optional status filter.
	 *
	 * @return SubjectExport[] The exports.
	 */
	public function findByRequester(string $requestedBy, ?string $status = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('requested_by', $qb->createNamedParameter($requestedBy)))
			->orderBy('id', 'DESC');

		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		return $this->findEntities(query: $qb);
	}//end findByRequester()

	/**
	 * Create an export request, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The request values.
	 *
	 * @return SubjectExport The persisted request.
	 */
	public function createFromArray(array $data): SubjectExport {
		$export = new SubjectExport();
		$export->hydrate($data);

		if ($export->getUuid() === null) {
			$export->setUuid(Uuid::v4()->toRfc4122());
		}

		if ($export->getStatus() === null) {
			$export->setStatus(SubjectExport::STATUS_PENDING);
		}

		$now = new DateTime();
		if ($export->getCreated() === null) {
			$export->setCreated($now);
		}

		$export->setUpdated($now);

		return $this->insert(entity: $export);
	}//end createFromArray()

	/**
	 * Persist an export, refreshing the updated timestamp.
	 *
	 * @param SubjectExport $export The export to persist.
	 *
	 * @return SubjectExport The updated export.
	 */
	public function save(SubjectExport $export): SubjectExport {
		$export->setUpdated(new DateTime());

		return $this->update(entity: $export);
	}//end save()
}//end class
