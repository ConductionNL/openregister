<?php

/**
 * The per-hop audit row: one entry on the audit trail per step row of a run.
 *
 * A step row is written on two paths, {@see FlowStepHistory::record()} after a
 * walk and {@see FlowRunCommit::commitFiring()} inside a firing's transaction.
 * Both hand the finished step row here, so a hop is audited from what was
 * recorded about it and neither path has a shape of its own.
 *
 * Called OUTSIDE the firing's transaction: the commit's critical section holds
 * no I/O, an audit row ships to the external sink, and on PostgreSQL a failed
 * insert would abort the transaction it sits in. So an audit failure never
 * touches the firing; it is logged at error level, and the step row stands.
 *
 * The row is deliberately not filed under the run's subject: `object_uuid`
 * rows are read back as the object's change history and as revert points, and
 * a hop is neither. The subject is named in `changed`, and the row is found
 * through `flow_run`.
 *
 * `changed` carries counts and identifiers only. A step's error text and items
 * can hold values from the run, and the audit trail is append-only; they stay
 * on the step row, which retention can prune.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\AuditFlowAttributionInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Writes a run's hops to the audit trail when its flow asks for that.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the id generator every audit writer uses.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class FlowHopAudit {
	/**
	 * The app whose configuration holds the instance default.
	 */
	private const APP_ID = 'openregister';

	/**
	 * The administrator's instance default.
	 */
	public const CONFIG_KEY = 'flow_audit_enabled';

	/**
	 * The answer per flow id, for the length of this process: a walk asks once
	 * per hop, and the answer costs a query.
	 *
	 * @var array<string, bool>
	 */
	private array $decisions = [];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $audit     Writes the row.
	 * @param FlowMapperInterface                $flows     Reads the flow's own flag.
	 * @param IAppConfig                $appConfig Reads the instance default.
	 * @param LoggerInterface           $logger    Records a row that could not be written.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $audit,
		private readonly FlowMapperInterface $flows,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Audit one hop, if this run's flow audits its hops. Never throws.
	 *
	 * @param FlowRun     $run  The run the hop belongs to.
	 * @param FlowRunStep $step The hop's step row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function record(FlowRun $run, FlowRunStep $step): void {
		try {
			if ($this->auditsHops(flowId: (string)$run->getFlowId()) === true) {
				$this->audit->insertAuditTrails(entries: [$this->rowFor(run: $run, step: $step)]);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowHopAudit] A hop ran but was not audited: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $run->getUuid()]
			);
		}
	}//end record()

	/**
	 * Whether a flow's hops are audited, remembered per flow id.
	 *
	 * @param string $flowId The run's flow id.
	 *
	 * @return boolean True when each hop gets an audit row.
	 */
	private function auditsHops(string $flowId): bool {
		if (isset($this->decisions[$flowId]) === false) {
			$this->decisions[$flowId] = $this->decide(flowId: $flowId);
		}

		return $this->decisions[$flowId];
	}//end auditsHops()

	/**
	 * Ask the flow, with the administrator's default as what it inherits.
	 *
	 * A run can outlive its flow row, and then only the default is left. A flow
	 * row that cannot be READ is a different case: its flag is unknown, and a
	 * spare audit row costs less than a missing one.
	 *
	 * @param string $flowId The run's flow id.
	 *
	 * @return boolean True when each hop gets an audit row.
	 */
	private function decide(string $flowId): bool {
		$default = $this->appConfig->getValueBool(self::APP_ID, self::CONFIG_KEY, false);

		try {
			return $this->flows->findByUuid(uuid: $flowId)->auditsEachHop(instanceDefault: $default);
		} catch (DoesNotExistException) {
			return $default;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowHopAudit] Could not read flow ' . $flowId . ', auditing its hops: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return true;
		}
	}//end decide()

	/**
	 * The audit row for a hop.
	 *
	 * `insertAuditTrails()` persists a pre-built row as it is, so the attribution
	 * set here survives even under the ambient frame of a parent run's step.
	 *
	 * @param FlowRun     $run  The run.
	 * @param FlowRunStep $step The hop's step row.
	 *
	 * @return AuditTrail The unsaved row.
	 */
	private function rowFor(FlowRun $run, FlowRunStep $step): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setAction(AuditFlowAttributionInterface::ACTION_HOP);
		$row->setUser(($run->getRunAs() ?? $run->getTriggeredBy() ?? 'system'));
		$row->setUserName(($run->getRunAs() ?? $run->getTriggeredBy() ?? 'System'));
		$row->setChanged($this->summary(run: $run, step: $step));
		$row->setFlowRun($run->getUuid());
		$row->setFlowNode($step->getNodeId());
		$row->setFlowStep($step->getSequence());
		$row->setCreated(new DateTime());

		return $row;
	}//end rowFor()

	/**
	 * What the row says about the hop: identifiers and counts, no run values.
	 *
	 * @param FlowRun     $run  The run.
	 * @param FlowRunStep $step The hop's step row.
	 *
	 * @return array<string, mixed> The `changed` payload.
	 */
	private function summary(FlowRun $run, FlowRunStep $step): array {
		return array_filter(
			array_merge(
				[
					'flow' => $run->getFlowId(),
					'organisation' => $run->getOrganisation(),
					'subject' => $run->getSubjectUuid(),
				],
				self::stepFacts(step: $step)
			),
			static fn ($value): bool => $value !== null
		);
	}//end summary()

	/**
	 * What the hop's step says about itself: its node type, status, stream, duration, failure and counts.
	 *
	 * @param FlowRunStep $step The step.
	 *
	 * @return array<string, mixed> The facts, nulls included.
	 */
	private static function stepFacts(FlowRunStep $step): array {
		$output = (array)($step->getOutput() ?? []);

		return [
			'nodeType' => $step->getNodeType(),
			'status' => $step->getStatus(),
			'streamId' => $step->getStreamId(),
			'durationMs' => $step->getDurationMs(),
			'failed' => ($step->getError() !== null),
			'itemsIn' => ($output['itemsIn'] ?? null),
			'itemsOut' => ($output['itemsOut'] ?? null),
			'checkId' => ($output['checkId'] ?? null),
		];
	}//end stepFacts()
}//end class
