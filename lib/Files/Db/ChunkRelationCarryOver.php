<?php

/**
 * Chunk Relation Carry-Over: entity relations follow a source's chunks when they are replaced.
 *
 * Re-extracting a source deletes its chunks and writes new ones with new ids.
 * The entity relations of the old chunks were left behind: still on the file,
 * pointing at chunks that no longer exist, and detection added a fresh set on
 * the new chunks. Every extract of the same document therefore added another
 * copy of every finding, and a reviewer's decisions sat on the stale copies.
 *
 * This moves the relations of an old chunk onto the new chunk with the same
 * index and the same text (so their positions still hold, decisions and all),
 * and removes the relations of an old chunk without such a successor unless a
 * person decided on them or added them by hand.
 *
 * @category  Files
 * @package   OCA\OpenRegister\Files\Db
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Carries entity relations from replaced chunks to their successors.
 */
final class ChunkRelationCarryOver {

	/**
	 * The relations table.
	 */
	private const RELATIONS = 'openregister_entity_relations';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Move or drop the relations of the replaced chunks.
	 *
	 * @param Chunk[] $previous The chunks as they were before the replace.
	 * @param Chunk[] $current  The chunks written in their place, with ids.
	 *
	 * @return array{moved: int, dropped: int} How many relations moved and how many went.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function carry(array $previous, array $current): array {
		$none = ['moved' => 0, 'dropped' => 0];
		if ($previous === []) {
			return $none;
		}

		$successors = [];
		foreach ($current as $chunk) {
			if ((int) $chunk->getId() > 0) {
				$successors[self::identity(chunk: $chunk)] = (int) $chunk->getId();
			}
		}

		$moved   = 0;
		$dropped = 0;
		foreach ($previous as $chunk) {
			$oldId = (int) $chunk->getId();
			$newId = ($successors[self::identity(chunk: $chunk)] ?? null);
			if ($newId !== null && $newId !== $oldId) {
				$moved += $this->move(from: $oldId, to: $newId);
				continue;
			}

			if ($newId === null) {
				$dropped += $this->dropUndecided(chunkId: $oldId);
			}
		}

		return ['moved' => $moved, 'dropped' => $dropped];
	}//end carry()

	/**
	 * What makes a new chunk the same place as an old one: its index and its text.
	 *
	 * @param Chunk $chunk The chunk.
	 *
	 * @return string
	 */
	private static function identity(Chunk $chunk): string {
		$text = (string) $chunk->getChecksum();
		if ($text === '') {
			$text = hash('sha256', (string) $chunk->getTextContent());
		}

		return (int) $chunk->getChunkIndex() . ':' . $text;
	}//end identity()

	/**
	 * Point an old chunk's relations at its successor.
	 *
	 * @param int $from The old chunk id.
	 * @param int $to   The new chunk id.
	 *
	 * @return int The relations moved.
	 */
	private function move(int $from, int $to): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::RELATIONS)
			->set('chunk_id', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('chunk_id', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)));

		return (int) $qb->executeStatement();
	}//end move()

	/**
	 * Remove an old chunk's relations nobody decided on and nobody added by hand.
	 *
	 * A relation that was anonymised, skipped, given bases, linked to a
	 * decision or had its capacity confirmed stays, as does a manual one: those
	 * are a person's work, and a changed text is no reason to lose it.
	 *
	 * @param int $chunkId The old chunk id.
	 *
	 * @return int The relations removed.
	 */
	private function dropUndecided(int $chunkId): int {
		$qb   = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->delete(self::RELATIONS)
			->where($expr->eq('chunk_id', $qb->createNamedParameter($chunkId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('anonymized', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($expr->eq('skip_anonymization', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($expr->isNull('decision_uuid'))
			->andWhere($expr->isNull('capacity_confirmed_by'))
			->andWhere(
				$expr->orX(
					$expr->isNull('bases'),
					$expr->in('bases', $qb->createNamedParameter(['', '[]'], IQueryBuilder::PARAM_STR_ARRAY))
				)
			)
			->andWhere($expr->neq('detection_method', $qb->createNamedParameter(DetectionMethod::MANUAL)));

		return (int) $qb->executeStatement();
	}//end dropUndecided()
}//end class
