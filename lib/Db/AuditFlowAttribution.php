<?php

/**
 * Flow attribution on audit rows: writing it, and reading it back.
 *
 * Split out of {@see AuditTrailMapper}, which does not have to know what a flow
 * is. The direction of the dependency is the point: an audit row records who
 * caused a write, and "a flow run caused it" is one more kind of cause — like
 * an import job or an MCP tool — rather than something the persistence layer
 * should reach into the flow engine to discover.
 *
 * Both halves live here because they are one fact read from two ends. The
 * stamp decides what a row claims; the query trusts that claim. Keeping them
 * together is what stops the column set they agree on from drifting apart.
 *
 * WHY THE VALUES ARE AMBIENT. The point of the attribution is to catch writes
 * made by code that has never heard of flows: a leaf app a node calls into, a
 * cascade, a lifecycle hook. Passing them as arguments would capture only what
 * a node explicitly declared, which is the same blind spot as recording touches
 * at the node — a node cannot report what it did not know it did.
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
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Applies the ambient flow attribution to an audit row, and reads it back.
 *
 * @template-extends QBMapper<AuditTrail>
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */
class AuditFlowAttribution extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection      $db        The database.
	 * @param ContainerInterface $container Resolves the shared run context.
	 */
	public function __construct(
		IDBConnection $db,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(db: $db, tableName: 'openregister_audit_trails', entityClass: AuditTrail::class);
	}//end __construct()

	/**
	 * Stamp the executing run, node and step onto a row.
	 *
	 * MUST be called before the row is written: these three fields are part of
	 * the canonical JSON the hash covers, exactly like `expires`, so setting
	 * them afterwards would put them outside the hash the row is later given.
	 *
	 * Fail-soft. If the context cannot be resolved the row is written
	 * unattributed — an audit row is evidence and must survive a bookkeeping
	 * problem, and an unattributed row is honest about what it does not know.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function apply(AuditTrail $auditTrail): void {
		try {
			$context = $this->container->get(FlowRunContext::class);
		} catch (Throwable $e) {
			return;
		}

		if (($context instanceof FlowRunContext) === false) {
			return;
		}

		$frame = $context->current();
		if ($frame === null) {
			return;
		}

		$auditTrail->setFlowRun($frame['run']);
		$auditTrail->setFlowNode($frame['node']);
		$auditTrail->setFlowStep($frame['step']);
	}//end apply()

	/**
	 * Every audit row attributed to one flow run, oldest first.
	 *
	 * Ordered by id rather than by `flow_step` so a run that visited the same
	 * node twice — a loop — reads in the order the writes actually happened
	 * rather than collapsing both visits into one position.
	 *
	 * @param string  $runUuid The flow run's uuid.
	 * @param integer $limit   Maximum rows to return.
	 *
	 * @return AuditTrail[] The rows this run caused.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function findByRun(string $runUuid, int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_audit_trails')
			->where($qb->expr()->eq('flow_run', $qb->createNamedParameter($runUuid)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findByRun()
}//end class
