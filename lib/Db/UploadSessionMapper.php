<?php

/**
 * UploadSessionMapper: the upload sessions table.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes upload sessions.
 *
 * @extends QBMapper<UploadSession>
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 */
class UploadSessionMapper extends QBMapper {

	/**
	 * The table.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_upload_sessions';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: UploadSession::class);
	}//end __construct()

	/**
	 * The session with this upload id, or null.
	 *
	 * @param string $uploadId The upload id.
	 *
	 * @return UploadSession|null
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function findByUploadId(string $uploadId): ?UploadSession {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
	}//end findByUploadId()

	/**
	 * The sessions that expired before this moment.
	 *
	 * @param DateTime $now   The moment.
	 * @param int      $limit The most to return.
	 *
	 * @return list<UploadSession>
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-3-2
	 */
	public function findExpired(DateTime $now, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findExpired()
}//end class
