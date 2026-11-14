<?php

/**
 * OpenRegister MailHeaderMapper
 *
 * Stores the thread headers of collected `.eml` files, one row per file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes the mail header rows.
 *
 * @method MailHeader insert(Entity $entity)
 * @method list<MailHeader> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<MailHeader>
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */
class MailHeaderMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_mail_headers', entityClass: MailHeader::class);
	}//end __construct()

	/**
	 * Store a file's header row, replacing the one an earlier extraction wrote.
	 *
	 * @param MailHeader $row The row; its file id names the file.
	 *
	 * @return MailHeader The stored row.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
	 */
	public function replaceForFile(MailHeader $row): MailHeader {
		$this->db->beginTransaction();
		try {
			$this->deleteByFileId(fileId: $row->getFileId());
			$stored = $this->insert(entity: $row);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $stored;
	}//end replaceForFile()

	/**
	 * Delete a file's header row, if it has one.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return int The number of rows deleted.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
	 */
	public function deleteByFileId(int $fileId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByFileId()

	/**
	 * The header rows of these files, in file id order.
	 *
	 * @param array<int, int> $fileIds The Nextcloud file ids.
	 *
	 * @return list<MailHeader> The rows; a file without one is absent.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
	 */
	public function findByFileIds(array $fileIds): array {
		$fileIds = array_values(array_unique(array_map('intval', $fileIds)));
		if ($fileIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('file_id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByFileIds()
}//end class
