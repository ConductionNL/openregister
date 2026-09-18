<?php

/**
 * The lifecycle-state history projection.
 *
 * Writes one interval per (object, declared property, value) and answers the
 * two history predicates over it: "was ever at this value" and "changed
 * between these moments".
 *
 * 🔴 THIS MAPPER ANSWERS WITH UUIDs, NEVER WITH ROWS THE CALLER THEN SHOWS.
 * The projection carries no access control of its own, so a uuid it returns is
 * a CANDIDATE. The caller narrows the ordinary, access-filtered object query
 * with that candidate set, which can only ever remove objects the caller could
 * already see. Returning intervals for rendering would walk straight around
 * RBAC, tenant isolation and property-level redaction in one step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTimeInterface;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the state-history projection.
 *
 * @template-extends QBMapper<StateHistory>
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
class StateHistoryMapper extends QBMapper {

	/**
	 * Largest candidate set a single predicate answers with.
	 *
	 * A history predicate narrows an ordinary list query, so the candidate set
	 * travels into that query's `IN` list. Databases refuse very long ones, and
	 * a predicate matching most of a register is a browse, not a filter. The
	 * bound is the same order as the page sizes this app already enforces.
	 *
	 * @var int
	 */
	public const CANDIDATE_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_state_history',
			entityClass: StateHistory::class
		);
	}//end __construct()

	/**
	 * Close the interval an object is currently in, for one declared property.
	 *
	 * Idempotent: with no open interval it updates nothing, which is the
	 * correct answer for the first transition an object ever makes.
	 *
	 * @param string            $objectUuid The object.
	 * @param string            $property   The declared lifecycle property.
	 * @param DateTimeInterface $leftAt     The moment it left.
	 *
	 * @return int Rows closed.
	 */
	public function closeOpenInterval(string $objectUuid, string $property, DateTimeInterface $leftAt): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('left_at', $qb->createNamedParameter($leftAt, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('property', $qb->createNamedParameter($property)))
			->andWhere($qb->expr()->isNull('left_at'));

		return (int)$qb->executeStatement();
	}//end closeOpenInterval()

	/**
	 * The objects whose declared property ever held this value.
	 *
	 * An open interval counts: "was ever in bezwaar" is true of a case sitting
	 * in bezwaar right now, and making the current state a separate case is how
	 * a filter comes to disagree with the list beside it.
	 *
	 * @param string $property The declared lifecycle property.
	 * @param string $value    The value it must have held.
	 *
	 * @return string[] Candidate object uuids.
	 *
	 * @psalm-return list<string>
	 */
	public function findObjectUuidsEverAt(string $property, string $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('property', $qb->createNamedParameter($property)))
			->andWhere($qb->expr()->eq('value', $qb->createNamedParameter($value)))
			->setMaxResults(self::CANDIDATE_LIMIT);

		return $this->collectUuids(queryBuilder: $qb);
	}//end findObjectUuidsEverAt()

	/**
	 * The objects whose declared property changed inside a period.
	 *
	 * A change is an interval BEGINNING: the moment the property took a new
	 * value. Counting interval ends as well would answer every change twice,
	 * once at each side of the same moment.
	 *
	 * @param string            $property The declared lifecycle property.
	 * @param DateTimeInterface $after    Start of the period, inclusive.
	 * @param DateTimeInterface $before   End of the period, inclusive.
	 *
	 * @return string[] Candidate object uuids.
	 *
	 * @psalm-return list<string>
	 */
	public function findObjectUuidsChangedBetween(
		string $property,
		DateTimeInterface $after,
		DateTimeInterface $before,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('property', $qb->createNamedParameter($property)))
			->andWhere($qb->expr()->gte('entered_at', $qb->createNamedParameter($after, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('entered_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)))
			->setMaxResults(self::CANDIDATE_LIMIT);

		return $this->collectUuids(queryBuilder: $qb);
	}//end findObjectUuidsChangedBetween()

	/**
	 * Drop every interval of one object.
	 *
	 * Used by the rebuild, which replaces an object's line rather than adding
	 * to it: a second pass that appended would double every interval and make
	 * "was ever" true twice.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return int Rows removed.
	 */
	public function deleteForObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return (int)$qb->executeStatement();
	}//end deleteForObject()

	/**
	 * Drop the closed intervals of one object that ended at or before a moment.
	 *
	 * 🔴 ONLY CLOSED INTERVALS. The open one describes the state the object is
	 * in now, which the object itself still asserts; it is not derived from the
	 * purged payload and removing it would make a case sitting in bezwaar for
	 * ten years invisible to "was ever in bezwaar" the day its oldest audit row
	 * expired.
	 *
	 * @param string            $objectUuid The object.
	 * @param DateTimeInterface $horizon    The newest purged moment.
	 *
	 * @return int Rows removed.
	 */
	public function pruneClosedIntervalsBefore(string $objectUuid, DateTimeInterface $horizon): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->isNotNull('left_at'))
			->andWhere($qb->expr()->lte('left_at', $qb->createNamedParameter($horizon, IQueryBuilder::PARAM_DATE)));

		return (int)$qb->executeStatement();
	}//end pruneClosedIntervalsBefore()

	/**
	 * Run a uuid query and flatten it.
	 *
	 * @param IQueryBuilder $queryBuilder The prepared query.
	 *
	 * @return string[] The uuids.
	 *
	 * @psalm-return list<string>
	 */
	private function collectUuids(IQueryBuilder $queryBuilder): array {
		$result = $queryBuilder->executeQuery();
		$uuids = [];
		while (($row = $result->fetch()) !== false) {
			$uuid = ($row['object_uuid'] ?? null);
			if ($uuid !== null) {
				$uuids[] = (string)$uuid;
			}
		}

		$result->closeCursor();

		return $uuids;
	}//end collectUuids()
}//end class
