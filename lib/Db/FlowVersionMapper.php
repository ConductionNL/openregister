<?php

/**
 * Reads and writes flow versions.
 *
 * The one hot read here is {@see findPublished}: every dispatch path asks it
 * before queueing a run. It is a single seek on `or_flowver_status` and must
 * stay that way — a dispatch is on the object-write path, so making this
 * question expensive makes saving an object expensive.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for flow versions.
 *
 * @template-extends QBMapper<FlowVersion>
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowVersionMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_versions', entityClass: FlowVersion::class);

	}//end __construct()

	/**
	 * Find one exact version of one flow.
	 *
	 * @param string  $flowUuid The flow.
	 * @param integer $version  The version number.
	 *
	 * @return FlowVersion|null The version, or null when there is no such row.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function find(string $flowUuid, int $version): ?FlowVersion {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end find()

	/**
	 * Find the single published version of a flow.
	 *
	 * 🔑 ORDERED BY VERSION DESCENDING AND CAPPED AT ONE, even though the
	 * service guarantees there is at most one published row. The guarantee is
	 * enforced in a transaction; this read runs on the object-write path and
	 * must give a DETERMINISTIC answer even if that invariant were ever
	 * broken by a bad migration or a manual edit. An unordered `LIMIT 1` over
	 * two published rows would hand different workers different graphs and the
	 * symptom would be intermittent.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return FlowVersion|null The published version, or null when none is published.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function findPublished(string $flowUuid): ?FlowVersion {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(FlowVersion::STATUS_PUBLISHED)))
			->orderBy('version', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findPublished()

	/**
	 * List every version of a flow, newest first.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return FlowVersion[] The versions.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function findAllForFlow(string $flowUuid): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)))
			->orderBy('version', 'DESC');

		return $this->findEntities(query: $qb);

	}//end findAllForFlow()

	/**
	 * The highest version number a flow has, or 0 when it has none.
	 *
	 * Read from the version rows rather than from the flow's own `version`
	 * column, deliberately: the flow column is a convenience mirror for the
	 * UI, and deriving the next number from a mirror is how two drafts end up
	 * claiming the same number. The unique index would catch that, but only
	 * after the transaction had done its other work.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return integer The highest version number, or 0 for a flow with no versions.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function highestVersion(string $flowUuid): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select('version')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)))
			->orderBy('version', 'DESC')
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false || isset($row['version']) === false) {
			return 0;
		}

		return (int)$row['version'];

	}//end highestVersion()

	/**
	 * Delete every version row of one flow.
	 *
	 * Called when the flow itself is deleted, AFTER its runs are gone. A
	 * version row exists so an in-flight run can resolve the graph it was
	 * pinned to; once no run of this flow remains, nothing can reach these rows
	 * through any read path the app has — every version read is by flow.
	 *
	 * @param string $flowUuid The flow being deleted.
	 *
	 * @return integer The number of version rows removed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function deleteByFlow(string $flowUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)));

		return (int)$qb->executeStatement();

	}//end deleteByFlow()

	/**
	 * Delete version rows whose flow no longer exists.
	 *
	 * 🔑 A ONE-OFF SWEEP FOR INSTANCES UPGRADED MID-FLIGHT. `FlowService::delete()`
	 * now cascades to this table, so no NEW orphan can appear — but any
	 * instance that deleted a flow between the version table landing and that
	 * cascade landing kept the rows. Measured on the dev instance: 38, growing
	 * with every flow anyone removed.
	 *
	 * Safe on the same terms as the cascade: `delete()` removes a flow's RUNS
	 * as well, so a version row whose flow is gone has no run left that could
	 * be pinned to it.
	 *
	 * @return integer The number of orphaned rows removed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function deleteOrphaned(): int {
		$qb = $this->db->getQueryBuilder();
		$sub = $this->db->getQueryBuilder();

		$sub->select('uuid')->from('openregister_flows');

		$qb->delete($this->getTableName())
			->where($qb->expr()->notIn('flow_uuid', $qb->createFunction('(' . $sub->getSQL() . ')')));

		return (int)$qb->executeStatement();

	}//end deleteOrphaned()
}//end class
