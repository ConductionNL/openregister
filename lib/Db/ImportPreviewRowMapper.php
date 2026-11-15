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

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ImportPreviewRowMapper
 *
 * @template-extends QBMapper<ImportPreviewRow>
 */
class ImportPreviewRowMapper extends QBMapper implements ImportPreviewRowMapperInterface {
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
	 * @param int       $previewId The preview id.
	 * @param ListQuery $query     The page (unbounded for every row) and an optional `decision` filter.
	 *
	 * @return ImportPreviewRow[] The rows.
	 */
	public function findByPreview(int $previewId, ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('preview_id', $qb->createNamedParameter($previewId, IQueryBuilder::PARAM_INT)))
			->orderBy('source_row', 'ASC');

		if (($query->filters['decision'] ?? null) !== null) {
			$qb->andWhere($qb->expr()->eq('decision', $qb->createNamedParameter($query->filters['decision'])));
		}

		(new QueryPage(limit: $query->limitOrNull(), offset: $query->offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findByPreview()

	/**
	 * One page of a run's failed rows, in file order: a skip, a refusal, or a
	 * create or update whose write failed.
	 *
	 * @param int       $previewId The run's id.
	 * @param ListQuery $query     The page (unbounded for every row).
	 *
	 * @return ImportPreviewRow[] The rows.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md#requirement-the-failed-rows-of-an-import-are-exportable-and-retryable-req-rcn-006
	 */
	public function findFailed(int $previewId, ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('preview_id', $qb->createNamedParameter($previewId, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->in(
						'decision',
						$qb->createNamedParameter(
							[ImportPreviewRow::DECISION_SKIP, ImportPreviewRow::DECISION_REFUSE],
							IQueryBuilder::PARAM_STR_ARRAY
						)
					),
					$qb->expr()->eq('outcome', $qb->createNamedParameter(ImportPreviewRow::OUTCOME_FAILED))
				)
			)
			->orderBy('source_row', 'ASC');

		(new QueryPage(limit: $query->limitOrNull(), offset: $query->offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findFailed()

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
			->orderBy('source_row', 'ASC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		return $this->findEntities(query: $qb);
	}//end findPendingWrites()

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
