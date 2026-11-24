<?php

/**
 * The semantic search vectors of one record, as a store an anonymisation reaches.
 *
 * A record's embedding is stored with a copy of the text it was made from
 * (chunk_text), and semantic search returns the record for a query near it.
 * Both were made before the record was anonymised. The vectors are dropped;
 * the next vectorisation embeds the anonymised record.
 *
 * The vectors are keyed on entity_id, which the vectorisation strategy fills
 * from the record's id as serialised; both the uuid and the database id are
 * matched so neither spelling survives.
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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Drops the semantic search vectors of one record.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */
final class VectorAnonymisationTarget implements AnonymisationTarget {

	/**
	 * The vector table.
	 */
	private const TABLE = 'openregister_vectors';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db       The database.
	 * @param int|null      $objectId The record's database id, when known.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ?int $objectId = null,
	) {
	}//end __construct()

	/**
	 * What this target is.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function name(): string {
		return 'the semantic search vectors';
	}//end name()

	/**
	 * Reach the vectors without writing.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function prepare(AnonymisationPlan $plan): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'vectors'))->from(self::TABLE);
		$this->whereRecord(qb: $qb, plan: $plan);

		$result = $qb->executeQuery();
		$result->fetchOne();
		$result->closeCursor();
	}//end prepare()

	/**
	 * Drop the vectors.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function apply(AnonymisationPlan $plan): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE);
		$this->whereRecord(qb: $qb, plan: $plan);
		$qb->executeStatement();
	}//end apply()

	/**
	 * Narrow a query to the record's vectors.
	 *
	 * @param IQueryBuilder     $qb   The query.
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 */
	private function whereRecord(IQueryBuilder $qb, AnonymisationPlan $plan): void {
		$ids = [$plan->objectUuid];
		if ($this->objectId !== null) {
			$ids[] = (string)$this->objectId;
		}

		$qb->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('object')))
			->andWhere($qb->expr()->in('entity_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
	}//end whereRecord()
}//end class
