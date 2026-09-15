<?php

/**
 * Mapper for ImportPreviewRow entities.
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
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ImportPreviewRowMapper
 *
 * @template-extends QBMapper<ImportPreviewRow>
 */
class ImportPreviewRowMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_import_preview_rows', entityClass: ImportPreviewRow::class);

	}//end __construct()

	/**
	 * One page of a preview's rows, in file order.
	 *
	 * @param int $previewId The preview id.
	 * @param string|null $decision Optional decision filter.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return ImportPreviewRow[] The rows.
	 */
	public function findByPreview(int $previewId, ?string $decision = null, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('preview_id', $qb->createNamedParameter($previewId, IQueryBuilder::PARAM_INT)))
			->orderBy('row_number', 'ASC');

		if ($decision !== null) {
			$qb->andWhere($qb->expr()->eq('decision', $qb->createNamedParameter($decision)));
		}

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities(query: $qb);
	}//end findByPreview()

	/**
	 * The rows a commit still has to write: a create or an update that no
	 * earlier commit stamped. The idempotence key is `applied_at`, never the
	 * decision, so a retry writes exactly what the first run did not.
	 *
	 * @param int $previewId The preview id.
	 * @param int|null $limit Optional batch size.
	 *
	 * @return ImportPreviewRow[] The unwritten writing rows.
	 */
	public function findPendingWrites(int $previewId, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('preview_id', $qb->createNamedParameter($previewId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('applied_at'))
			->andWhere(
				$qb->expr()->in(
					'decision',
					$qb->createNamedParameter(
						[ImportPreviewRow::DECISION_CREATE, ImportPreviewRow::DECISION_UPDATE],
						IQueryBuilder::PARAM_STR_ARRAY
					)
				)
			)
			->orderBy('row_number', 'ASC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		return $this->findEntities(query: $qb);
	}//end findPendingWrites()

	/**
	 * Count a preview's rows carrying one decision.
	 *
	 * @param int $previewId The preview id.
	 * @param string $decision The decision.
	 *
	 * @return int The count.
	 */
	public function countByDecision(int $previewId, string $decision): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'row_count'))
			->from($this->getTableName())
			->where($qb->expr()->eq('preview_id', $qb->createNamedParameter($previewId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('decision', $qb->createNamedParameter($decision)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countByDecision()

	/**
	 * Persist one decision.
	 *
	 * @param ImportPreviewRow $row The row to persist.
	 *
	 * @return ImportPreviewRow The persisted row.
	 */
	public function persist(ImportPreviewRow $row): ImportPreviewRow {
		if ($row->getId() === null) {
			$row->setCreated(new DateTime());

			return $this->insert(entity: $row);
		}

		return $this->update(entity: $row);
	}//end persist()

	/**
	 * Remove every row of a preview.
	 *
	 * @param int $previewId The preview id.
	 *
	 * @return void
	 */
	public function deleteByPreview(int $previewId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('preview_id', $qb->createNamedParameter($previewId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}//end deleteByPreview()
}//end class
