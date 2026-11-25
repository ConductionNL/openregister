<?php

/**
 * The derived copies an anonymisation reaches beside the record.
 *
 * One place lists them, so a store added later is added here once and every
 * run reaches it: the state-history projection, the content search index and
 * the semantic search vectors. The record's own row is the search index for
 * property search, so it is reached by writing the record.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectPropertyHistoryInterface;
use OCP\IDBConnection;

/**
 * Builds the derived-copy targets of one record.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */
class AnonymisationDerivedCopies {

	/**
	 * Constructor.
	 *
	 * @param ObjectPropertyHistoryInterface $history The state-history projection.
	 * @param ChunkMapperInterface           $chunks  The content search chunks.
	 * @param IDBConnection               $db      The database, for the vectors.
	 */
	public function __construct(
		private readonly ObjectPropertyHistoryInterface $history,
		private readonly ChunkMapperInterface $chunks,
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * The derived-copy targets of one record.
	 *
	 * A record without a database id has no chunks to reach (they are keyed
	 * on it), so the content index is left out rather than reached under a
	 * guessed id.
	 *
	 * @param int|null $objectId The record's database id.
	 *
	 * @return list<AnonymisationTarget> The targets.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function targetsFor(?int $objectId): array {
		$targets = [new StateHistoryAnonymisationTarget(history: $this->history)];
		if ($objectId !== null) {
			$targets[] = new ContentIndexAnonymisationTarget(chunks: $this->chunks, objectId: $objectId);
		}

		$targets[] = new VectorAnonymisationTarget(db: $this->db, objectId: $objectId);

		return $targets;
	}//end targetsFor()
}//end class
