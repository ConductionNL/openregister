<?php

/**
 * Mapper for TextSignature rows (`openregister_text_signatures`).
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class TextSignatureMapper
 *
 * @method TextSignature insert(\OCP\AppFramework\Db\Entity $entity)
 * @method TextSignature update(\OCP\AppFramework\Db\Entity $entity)
 * @method TextSignature delete(\OCP\AppFramework\Db\Entity $entity)
 *
 * @template-extends QBMapper<TextSignature>
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */
class TextSignatureMapper extends QBMapper {

	/**
	 * The table this mapper holds.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_text_signatures';

	/**
	 * The most file ids bound into one IN list.
	 *
	 * @var int
	 */
	private const CHUNK = 1000;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: TextSignature::class);

	}//end __construct()

	/**
	 * The signatures of the given files, keyed by file id; a file without a row is absent.
	 *
	 * @param list<int> $fileIds The file ids.
	 *
	 * @return array<int, TextSignature> The rows.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function findByFileIds(array $fileIds): array {
		$rows = [];
		foreach (array_chunk(array_values(array_unique($fileIds)), self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			foreach ($this->findEntities(query: $qb) as $row) {
				$rows[(int)$row->getFileId()] = $row;
			}
		}

		return $rows;
	}//end findByFileIds()

	/**
	 * Delete the signature of a file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return int The number of rows deleted.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function deleteByFileId(int $fileId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByFileId()

	/**
	 * Store a file's signature in place of any earlier one, so a file holds exactly one.
	 *
	 * @param TextSignature $row The new row.
	 *
	 * @return TextSignature The stored row.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function replaceForFile(TextSignature $row): TextSignature {
		$this->deleteByFileId(fileId: (int)$row->getFileId());

		return $this->insert(entity: $row);
	}//end replaceForFile()
}//end class
