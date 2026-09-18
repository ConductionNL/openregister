<?php

/**
 * OpenRegister NoteVersion Mapper
 *
 * Mapper for {@see NoteVersion} rows: the texts a note carried before an edit
 * replaced them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
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
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * NoteVersionMapper handles database operations for NoteVersion rows.
 *
 * @method NoteVersion insert(NoteVersion $entity)
 * @method NoteVersion update(Entity $entity)
 * @method NoteVersion delete(Entity $entity)
 * @method NoteVersion findEntity(IQueryBuilder $query)
 * @method list<NoteVersion> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<NoteVersion>
 */
class NoteVersionMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_note_versions', entityClass: NoteVersion::class);
	}//end __construct()

	/**
	 * List the versions of one note, newest first.
	 *
	 * @param int $commentId The Nextcloud comment id of the note.
	 *
	 * @return NoteVersion[] The versions, newest first.
	 *
	 * @psalm-return list<NoteVersion>
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function findByComment(int $commentId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->orderBy('edited_at', 'DESC')
			->addOrderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByComment()

	/**
	 * Read the edit summary of several notes in one query.
	 *
	 * A note list renders up to fifty notes, and asking per note would be
	 * fifty round trips for a marker. The rows come back oldest first per
	 * note, so folding them keeps the LAST edit as the one reported.
	 *
	 * @param int[] $commentIds The comment ids to summarise.
	 *
	 * @return array<int, array{editedAt: string|null, editedBy: string|null, versionCount: int}>
	 *         Keyed by comment id; ids with no versions are absent.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function summariesFor(array $commentIds): array {
		$ids = array_values(array_unique(array_map('intval', $commentIds)));
		if (count($ids) === 0) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('comment_id', 'edited_by', 'edited_at')
			->from($this->getTableName())
			->where($qb->expr()->in('comment_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('edited_at', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$summaries = [];
		while (($row = $result->fetch()) !== false) {
			$commentId = (int)$row['comment_id'];
			if (isset($summaries[$commentId]) === false) {
				$summaries[$commentId] = [
					'editedAt' => null,
					'editedBy' => null,
					'versionCount' => 0,
				];
			}

			$summaries[$commentId]['versionCount']++;
			$summaries[$commentId]['editedBy'] = $row['edited_by'];
			$summaries[$commentId]['editedAt'] = $this->formatMoment(value: $row['edited_at']);
		}

		$result->closeCursor();

		return $summaries;
	}//end summariesFor()

	/**
	 * Delete every version of the given notes.
	 *
	 * Called when a note or its whole object goes: a version outliving the
	 * note it belongs to is a prior text nobody can reach and nobody erased.
	 *
	 * @param int[] $commentIds The comment ids whose versions are dropped.
	 *
	 * @return int The number of rows deleted.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function deleteByComments(array $commentIds): int {
		$ids = array_values(array_unique(array_map('intval', $commentIds)));
		if (count($ids) === 0) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('comment_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return (int)$qb->executeStatement();
	}//end deleteByComments()

	/**
	 * Render a stored moment as an ISO-8601 string.
	 *
	 * The driver hands back a string for a DATETIME column, and an unparsable
	 * one reads as no edit rather than as today.
	 *
	 * @param mixed $value The raw column value.
	 *
	 * @return string|null The formatted moment, or null when there is none.
	 */
	private function formatMoment(mixed $value): ?string {
		if ($value instanceof \DateTimeInterface) {
			return $value->format('c');
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTime($value))->format('c');
		} catch (\Exception $e) {
			return null;
		}
	}//end formatMoment()
}//end class
