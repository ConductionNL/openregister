<?php

/**
 * Reads and writes flow definitions.
 *
 * Every read is organisation-scoped. Flows write objects and run agents, so a
 * flow leaking across a tenant boundary is not a listing bug — it is an
 * execution-privilege bug, and the scoping therefore lives here rather than
 * being left to each caller to remember.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes flow definitions.
 *
 * @template-extends QBMapper<Flow>
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class FlowMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flows', entityClass: Flow::class);

	}//end __construct()

	/**
	 * Find a flow by its public uuid.
	 *
	 * Deliberately NOT organisation-scoped: a caller that already holds a uuid
	 * needs to be able to tell "no such flow" from "not yours", and collapsing
	 * those two into one lookup would make every authorisation check downstream
	 * indistinguishable from a typo. Callers scope with `belongsTo()`.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow The flow.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such flow exists.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function findByUuid(string $uuid): Flow {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * List flows, newest first.
	 *
	 * `$app` is the per-app scoping key. Passing null returns every app's flows,
	 * which is what OpenRegister's own index wants; passing an app id is what
	 * every leaf app's index wants.
	 *
	 * `$applicationSlug` is narrower still and independent of `$app`: one
	 * Nextcloud app can host several OpenBuild virtual apps, each with its own
	 * flows. Passing both composes as an AND, not an OR.
	 *
	 * The predicate below reads the raw column `application_slug`, not the
	 * parameter's own camelCase spelling — the migration follows this table's
	 * existing snake_case convention (`trigger_register`, `execution_mode`),
	 * and `Entity`'s camelCase<->snake_case conversion only applies to
	 * property access, never to a query builder's raw column literal.
	 *
	 * @param string|null $app Restrict to one owning app id.
	 * @param string|null $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 * @param string|null $organisation Restrict to one organisation uuid.
	 * @param boolean|null $enabled Restrict to enabled or disabled flows.
	 * @param integer $limit Page size.
	 * @param integer $offset Page offset.
	 *
	 * @return array<int, Flow> The flows.
	 *
	 * @spec openspec/changes/flow-application-slug/specs/flow-engine/spec.md
	 */
	public function findAllFlows(
		?string $app = null,
		?string $applicationSlug = null,
		?string $organisation = null,
		?bool $enabled = null,
		int $limit = 100,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		if ($app !== null && $app !== '') {
			$qb->andWhere($qb->expr()->eq('app', $qb->createNamedParameter($app)));
		}

		if ($applicationSlug !== null && $applicationSlug !== '') {
			$qb->andWhere($qb->expr()->eq('application_slug', $qb->createNamedParameter($applicationSlug)));
		}

		if ($organisation !== null && $organisation !== '') {
			$qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)));
		}

		if ($enabled !== null) {
			$qb->andWhere(
				$qb->expr()->eq(
					'enabled',
					$qb->createNamedParameter($enabled, IQueryBuilder::PARAM_BOOL)
				)
			);
		}

		return $this->findEntities(query: $qb);
	}//end findAllFlows()

	/**
	 * Count flows matching the same scoping as `findAllFlows()`.
	 *
	 * @param string|null $app Restrict to one owning app id.
	 * @param string|null $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 * @param string|null $organisation Restrict to one organisation uuid.
	 *
	 * @return integer The number of matching flows.
	 *
	 * @spec openspec/changes/flow-application-slug/specs/flow-engine/spec.md
	 */
	public function countFlows(
		?string $app = null,
		?string $applicationSlug = null,
		?string $organisation = null,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from($this->getTableName());

		if ($app !== null && $app !== '') {
			$qb->andWhere($qb->expr()->eq('app', $qb->createNamedParameter($app)));
		}

		if ($applicationSlug !== null && $applicationSlug !== '') {
			$qb->andWhere($qb->expr()->eq('application_slug', $qb->createNamedParameter($applicationSlug)));
		}

		if ($organisation !== null && $organisation !== '') {
			$qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['total'] ?? 0);
	}//end countFlows()

	/**
	 * The enabled flows wired to one trigger.
	 *
	 * Only `enabled` is filtered in SQL. Ownership is NOT: an ownerless flow
	 * must be visible to the caller so it can be recorded as refused, rather
	 * than vanishing from the query and looking like "no flow was interested".
	 * A silently-empty result is exactly how a broken trigger looks.
	 *
	 * @param string $trigger The catalog trigger id.
	 * @param string|null $register The subject's register slug, when object-scoped.
	 * @param string|null $schema The subject's schema slug, when object-scoped.
	 * @param string|null $organisation Restrict to one organisation uuid.
	 *
	 * @return array<int, Flow> The candidate flows.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function findByTrigger(
		string $trigger,
		?string $register = null,
		?string $schema = null,
		?string $organisation = null,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('trigger', $qb->createNamedParameter($trigger)))
			->andWhere(
				$qb->expr()->eq(
					'enabled',
					$qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)
				)
			);

		// An empty trigger_register/trigger_schema means "any", so a row matches
		// when its restriction is null/empty OR equals the subject's. Writing
		// this as a plain equality would silently drop every unrestricted flow.
		if ($register !== null && $register !== '') {
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('trigger_register'),
					$qb->expr()->eq('trigger_register', $qb->createNamedParameter('')),
					$qb->expr()->eq('trigger_register', $qb->createNamedParameter($register))
				)
			);
		}

		if ($schema !== null && $schema !== '') {
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('trigger_schema'),
					$qb->expr()->eq('trigger_schema', $qb->createNamedParameter('')),
					$qb->expr()->eq('trigger_schema', $qb->createNamedParameter($schema))
				)
			);
		}

		if ($organisation !== null && $organisation !== '') {
			$qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)));
		}

		return $this->findEntities(query: $qb);
	}//end findByTrigger()

	/**
	 * The enabled flows that run on a cron schedule.
	 *
	 * @param string|null $organisation Restrict to one organisation uuid.
	 *
	 * @return array<int, Flow> The scheduled flows.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function findScheduled(?string $organisation = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('trigger', $qb->createNamedParameter(Flow::TRIGGER_SCHEDULE)))
			->andWhere(
				$qb->expr()->eq(
					'enabled',
					$qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)
				)
			)
			->andWhere($qb->expr()->isNotNull('cron'))
			->andWhere($qb->expr()->neq('cron', $qb->createNamedParameter('')));

		if ($organisation !== null && $organisation !== '') {
			$qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)));
		}

		return $this->findEntities(query: $qb);
	}//end findScheduled()

	/**
	 * The ids of the flows one user owns.
	 *
	 * Feeds the run-history visibility rule: a caller sees the runs they
	 * triggered, PLUS the runs of flows they own. The second disjunct is not
	 * optional — `triggered_by` is NULL for cron- and trigger-fired runs, so a
	 * "only runs you triggered" rule would hide every automated run from the
	 * one person who most needs to see it, the flow's own owner.
	 *
	 * @param string $uid The owner's Nextcloud uid.
	 *
	 * @return array<int, string> The flow uuids that user owns.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function findIdsOwnedBy(string $uid): array {
		if (trim($uid) === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)));

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (string)$row['uuid'];
		}

		$result->closeCursor();

		return $ids;
	}//end findIdsOwnedBy()

	/**
	 * Every flow that declares its own retention override.
	 *
	 * The retention sweep needs these separately: they are the flows the
	 * instance-wide cutoff must NOT be applied to.
	 *
	 * @return array<int, Flow> The flows carrying a retention override.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function findWithRetentionOverride(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('retention_days'))
			->andWhere($qb->expr()->gt('retention_days', $qb->createNamedParameter(0)));

		return $this->findEntities(query: $qb);
	}//end findWithRetentionOverride()
}//end class
