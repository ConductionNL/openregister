<?php

/**
 * Mapper for ImportPreview entities.
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
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
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
 * Class ImportPreviewMapper
 *
 * @template-extends QBMapper<ImportPreview>
 */
class ImportPreviewMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_import_previews', entityClass: ImportPreview::class);

	}//end __construct()

	/**
	 * Find a preview by its numeric id.
	 *
	 * @param int $id The preview id.
	 *
	 * @return ImportPreview The preview.
	 *
	 * @throws DoesNotExistException When no such preview exists.
	 */
	public function find(int $id): ImportPreview {
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
	 * @return ImportPreview The preview.
	 *
	 * @throws DoesNotExistException When no such preview exists.
	 */
	public function findByUuid(string $uuid): ImportPreview {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * List the previews of one actor, newest first.
	 *
	 * @param string $createdBy The actor's uid.
	 * @param string|null $state Optional state filter.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return ImportPreview[] The actor's previews.
	 */
	public function findByActor(string $createdBy, ?string $state = null, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($createdBy)))
			->orderBy('id', 'DESC');

		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities(query: $qb);
	}//end findByActor()

	/**
	 * Create a preview from an array, stamping the uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The preview fields.
	 *
	 * @return ImportPreview The persisted preview.
	 */
	public function createFromArray(array $data): ImportPreview {
		$preview = new ImportPreview();

		foreach ($data as $key => $value) {
			$method = 'set'.ucfirst($key);
			if (method_exists($preview, $method) === true) {
				$preview->$method($value);
			}
		}

		if ($preview->getUuid() === null) {
			$preview->setUuid(Uuid::v4()->toRfc4122());
		}

		$now = new DateTime();
		$preview->setCreated($now);
		$preview->setUpdated($now);

		return $this->insert(entity: $preview);
	}//end createFromArray()

	/**
	 * Persist a preview, moving its updated timestamp.
	 *
	 * @param ImportPreview $preview The preview to persist.
	 *
	 * @return ImportPreview The persisted preview.
	 */
	public function persist(ImportPreview $preview): ImportPreview {
		$preview->setUpdated(new DateTime());

		return $this->update(entity: $preview);
	}//end persist()
}//end class
