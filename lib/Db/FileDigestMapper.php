<?php

/**
 * Mapper for FileDigest entities (`openregister_file_digests`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class FileDigestMapper
 *
 * @method FileDigest insert(\OCP\AppFramework\Db\Entity $entity)
 * @method FileDigest update(\OCP\AppFramework\Db\Entity $entity)
 * @method FileDigest delete(\OCP\AppFramework\Db\Entity $entity)
 *
 * @template-extends QBMapper<FileDigest>
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */
class FileDigestMapper extends QBMapper {

	/**
	 * The table this mapper holds.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_file_digests';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: FileDigest::class);

	}//end __construct()

	/**
	 * Close every open record of a file but one.
	 *
	 * Publishing again opens a new record; the ones before it stop being valid
	 * at the same moment. Unpublishing closes them all (no record is kept open).
	 *
	 * @param int      $fileId The Nextcloud file id.
	 * @param DateTime $at     The moment the records stop being valid.
	 * @param int|null $keepId The id of the record to leave open, or null to close all.
	 *
	 * @return int The number of records closed.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
	 */
	public function closeOpenForFile(int $fileId, DateTime $at, ?int $keepId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('unpublished_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('unpublished_at'));

		if ($keepId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($keepId, IQueryBuilder::PARAM_INT)));
		}

		return $qb->executeStatement();

	}//end closeOpenForFile()
}//end class
