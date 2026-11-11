<?php

/**
 * Mapper for Sequence entities (declarative running-number counters).
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Service\SequenceScope;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class SequenceMapper
 *
 * @template-extends QBMapper<Sequence>
 */
class SequenceMapper extends QBMapper implements SequenceMapperInterface {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_sequences', entityClass: Sequence::class);
	}//end __construct()

	/**
	 * Find the sequence row for a scope, or null when absent.
	 *
	 * @param SequenceScope $scope The register, schema and scope key of the counter.
	 *
	 * @return Sequence|null The row, or null when absent.
	 */
	public function findForScope(SequenceScope $scope): ?Sequence {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->whereScope(qb: $qb, scope: $scope);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findForScope()

	/**
	 * Atomically increment the scope row's `next_value` by one.
	 *
	 * Issues a single `UPDATE … SET next_value = next_value + 1 WHERE …`. The
	 * UPDATE acquires a row lock that both Postgres and MySQL/InnoDB hold for
	 * the duration of the surrounding transaction, so a concurrent reservation
	 * on the same scope blocks here until this transaction commits — which is
	 * what serialises the hand-out and prevents two callers ever reading the
	 * same post-increment value.
	 *
	 * @param SequenceScope $scope The register, schema and scope key of the counter.
	 *
	 * @return int The number of affected rows (1 when the scope row exists, 0 otherwise).
	 */
	public function incrementScope(SequenceScope $scope): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('next_value', $qb->createFunction('next_value + 1'));
		$this->whereScope(qb: $qb, scope: $scope);

		return $qb->executeStatement();
	}//end incrementScope()

	/**
	 * Raise a scope row's `next_value` so it is at least the given number.
	 *
	 * Conditional on purpose: the `next_value < ?` clause is what makes this safe
	 * to run beside a concurrent reservation. A plain SET would let an import
	 * that supplied an OLD number push the counter backwards, and the next create
	 * would then re-issue a number already printed on a record.
	 *
	 * @param SequenceScope $scope The register, schema and scope key of the counter.
	 * @param int $minNextValue The value `next_value` must reach.
	 *
	 * @return int Affected rows: 1 when the row moved, 0 when it was already high enough or absent.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function raiseTo(SequenceScope $scope, int $minNextValue): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('next_value', $qb->createNamedParameter($minNextValue, IQueryBuilder::PARAM_INT));
		$this->whereScope(qb: $qb, scope: $scope)
			->andWhere($qb->expr()->lt('next_value', $qb->createNamedParameter($minNextValue, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end raiseTo()

	/**
	 * Narrow a query to the one row of a counter scope.
	 *
	 * @param IQueryBuilder $qb    The query.
	 * @param SequenceScope $scope The register, schema and scope key of the counter.
	 *
	 * @return IQueryBuilder The same query.
	 */
	private function whereScope(IQueryBuilder $qb, SequenceScope $scope): IQueryBuilder {
		return $qb->where($qb->expr()->eq('register_id', $qb->createNamedParameter($scope->registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('schema_id', $qb->createNamedParameter($scope->schemaId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('scope_key', $qb->createNamedParameter($scope->scopeKey)));
	}//end whereScope()
}//end class
