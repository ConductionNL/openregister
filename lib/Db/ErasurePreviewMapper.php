<?php

/**
 * Mapper for ErasurePreview entities.
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
 * Class ErasurePreviewMapper
 *
 * @template-extends QBMapper<ErasurePreview>
 */
class ErasurePreviewMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_erasure_previews',
			entityClass: ErasurePreview::class
		);

	}//end __construct()

	/**
	 * Find a preview by its numeric id.
	 *
	 * @param int $id The preview id.
	 *
	 * @return ErasurePreview The preview.
	 *
	 * @throws DoesNotExistException When no such preview exists.
	 */
	public function find(int $id): ErasurePreview {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find a preview by its uuid.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ErasurePreview The preview.
	 *
	 * @throws DoesNotExistException When no such preview exists.
	 */
	public function findByUuid(string $uuid): ErasurePreview {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * List the previews taken for one subject, newest first.
	 *
	 * @param string      $subject The subject identifier value.
	 * @param string|null $status  Optional status filter.
	 *
	 * @return ErasurePreview[] The previews.
	 */
	public function findBySubject(string $subject, ?string $status = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('subject', $qb->createNamedParameter($subject)))
			->orderBy('id', 'DESC');

		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		return $this->findEntities(query: $qb);
	}//end findBySubject()

	/**
	 * Create a preview row, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The preview values.
	 *
	 * @return ErasurePreview The persisted preview.
	 */
	public function createFromArray(array $data): ErasurePreview {
		$preview = new ErasurePreview();
		$preview->hydrate($data);

		if ($preview->getUuid() === null) {
			$preview->setUuid(Uuid::v4()->toRfc4122());
		}

		if ($preview->getStatus() === null) {
			$preview->setStatus(ErasurePreview::STATUS_PENDING);
		}

		$now = new DateTime();
		if ($preview->getCreated() === null) {
			$preview->setCreated($now);
		}

		$preview->setUpdated($now);

		return $this->insert(entity: $preview);
	}//end createFromArray()

	/**
	 * Persist a preview, refreshing the updated timestamp.
	 *
	 * @param ErasurePreview $preview The preview to persist.
	 *
	 * @return ErasurePreview The updated preview.
	 */
	public function save(ErasurePreview $preview): ErasurePreview {
		$preview->setUpdated(new DateTime());

		return $this->update(entity: $preview);
	}//end save()
}//end class
