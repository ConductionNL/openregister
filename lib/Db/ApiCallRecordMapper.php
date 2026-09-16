<?php

/**
 * Reads and increments the caller record.
 *
 * 🔑 THE INCREMENT IS AN UPDATE, NOT A READ-MODIFY-WRITE. Two concurrent calls
 * from the same leverancier on the same route would both read the same count
 * and both write count+1, losing one. `SET call_count = call_count + 1` lets
 * the database do the arithmetic, so the number an administrator reads is the
 * number of calls that happened rather than the number that did not race.
 *
 * 🔴 A FAILED RECORD MUST NEVER FAIL THE CALL. Every write here is wrapped by
 * its caller and swallowed. This table exists so a deprecation can be a
 * conversation; a gemeente's API going down because its usage log had a
 * deadlock would be an outage caused by an ornament.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Mapper for {@see ApiCallRecord}.
 *
 * @template-extends QBMapper<ApiCallRecord>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @psalm-suppress UnusedClass Registered through the container and used by ApiCallRecorder.
 */
class ApiCallRecordMapper extends QBMapper {

	/**
	 * The table this mapper owns.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_api_calls';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, ApiCallRecord::class);

	}//end __construct()

	/**
	 * Count one call against a caller, route, method and version.
	 *
	 * Tries the increment first and inserts only when nothing was updated, so
	 * the common path (a caller that has called before) is one statement. A
	 * concurrent insert of the same combination loses the unique index race and
	 * is retried as an increment, which is why the insert failure is not simply
	 * swallowed.
	 *
	 * @param string $principal The caller, or the empty string for anonymous.
	 * @param string $route The route pattern.
	 * @param string $method The HTTP method.
	 * @param string $apiVersion The contract version that served it.
	 * @param DateTime $at When the call happened.
	 *
	 * @return bool True when the call was counted.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function count(
		string $principal,
		string $route,
		string $method,
		string $apiVersion,
		DateTime $at,
	): bool {
		if ($this->increment(principal: $principal, route: $route, method: $method, apiVersion: $apiVersion, at: $at) > 0) {
			return true;
		}

		try {
			$record = new ApiCallRecord();
			$record->setPrincipal($principal);
			$record->setRoute($route);
			$record->setMethod($method);
			$record->setApiVersion($apiVersion);
			$record->setCallCount(1);
			$record->setFirstSeen($at);
			$record->setLastSeen($at);
			$this->insert($record);

			return true;
		} catch (Throwable) {
			// Another request inserted the same combination between our update
			// and our insert. The row exists now, so the increment that failed
			// to find it a moment ago will find it.
			return ($this->increment(
				principal: $principal,
				route: $route,
				method: $method,
				apiVersion: $apiVersion,
				at: $at
			) > 0);
		}//end try

	}//end count()

	/**
	 * The records whose last call falls in a period.
	 *
	 * @param DateTime $from The start of the period.
	 * @param DateTime $to The end of the period.
	 * @param string|null $apiVersion Narrow to one contract version, or null for all.
	 * @param int $limit The most rows to return.
	 *
	 * @return array<int, ApiCallRecord> The records, busiest first.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function findInPeriod(DateTime $from, DateTime $to, ?string $apiVersion = null, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->gte('last_seen', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('last_seen', $qb->createNamedParameter($to, IQueryBuilder::PARAM_DATE)))
			->orderBy('call_count', 'DESC')
			->setMaxResults(max(1, $limit));

		if ($apiVersion !== null && $apiVersion !== '') {
			$qb->andWhere($qb->expr()->eq('api_version', $qb->createNamedParameter($apiVersion)));
		}

		return $this->findEntities($qb);

	}//end findInPeriod()

	/**
	 * Drop records nothing has called since a moment.
	 *
	 * The record is an operational aid with no evidential value, so it does not
	 * accumulate forever. A row for a route nobody has called in a year answers
	 * no question an administrator is asking.
	 *
	 * @param DateTime $before Drop records last seen before this.
	 *
	 * @return int The number of records dropped.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function pruneBefore(DateTime $before): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('last_seen', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)));

		return (int)$qb->executeStatement();

	}//end pruneBefore()

	/**
	 * Increment an existing row, if there is one.
	 *
	 * @param string $principal The caller.
	 * @param string $route The route pattern.
	 * @param string $method The HTTP method.
	 * @param string $apiVersion The contract version.
	 * @param DateTime $at When the call happened.
	 *
	 * @return int The number of rows updated: one, or none.
	 */
	private function increment(
		string $principal,
		string $route,
		string $method,
		string $apiVersion,
		DateTime $at,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('call_count', $qb->createFunction($qb->getColumnName('call_count') . ' + 1'))
			->set('last_seen', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('principal', $qb->createNamedParameter($principal)))
			->andWhere($qb->expr()->eq('route', $qb->createNamedParameter($route)))
			->andWhere($qb->expr()->eq('method', $qb->createNamedParameter($method)))
			->andWhere($qb->expr()->eq('api_version', $qb->createNamedParameter($apiVersion)));

		return (int)$qb->executeStatement();

	}//end increment()
}//end class
