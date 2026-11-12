<?php

/**
 * OpenRegister AppHost: the flow one manifest schedule compiles to.
 *
 * The graph is schedule trigger, the allow-listed step, end. The trigger declares
 * the publisher as `runAs`, so the registration and the identity it fires under
 * come from the same node (ADR-099).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Value
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use DateTime;
use OCA\OpenRegister\Db\Flow;

/**
 * Compares itself with a stored flow and writes itself onto one.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
final class CompiledScheduleFlow {
	/**
	 * Constructor.
	 *
	 * @param ScheduleRelease $release The publish this flow comes from.
	 * @param ScheduleDescriptor $schedule The schedule.
	 * @param string $cron The cron expression it fires on.
	 * @param array{type: string, config: array<string, string>} $node The allow-listed step.
	 */
	public function __construct(
		public readonly ScheduleRelease $release,
		public readonly ScheduleDescriptor $schedule,
		private readonly string $cron,
		private readonly array $node,
	) {
	}//end __construct()

	/**
	 * This schedule's flow uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function uuid(): string {
		return $this->release->flowUuid(scheduleId: $this->schedule->id);
	}//end uuid()

	/**
	 * Whether a stored flow already is this flow.
	 *
	 * @param Flow $flow The stored flow.
	 *
	 * @return bool True when applying would change nothing.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function matches(Flow $flow): bool {
		return $this->graphMatches(flow: $flow)
			&& $flow->getEnabled() === $this->schedule->enabled
			&& $flow->getOwner() === $this->release->publisher
			&& $flow->getOrganisation() === $this->release->organisation()
			&& $flow->getStatus() !== Flow::STATUS_ERROR;
	}//end matches()

	/**
	 * Whether a stored flow holds this graph and cron.
	 *
	 * @param Flow $flow The stored flow.
	 *
	 * @return bool True when no new flow version is needed.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function graphMatches(Flow $flow): bool {
		return $flow->getNodes() === $this->nodes()
			&& $flow->getEdges() === $this->edges()
			&& $flow->getCron() === $this->cron;
	}//end graphMatches()

	/**
	 * Write this flow's fields onto an entity.
	 *
	 * @param Flow $flow The entity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function applyTo(Flow $flow): void {
		$flow->setName($this->release->slug() . ': ' . $this->schedule->id);
		$flow->setApp(Flow::APP_MANIFEST_SCHEDULE);
		$flow->setApplicationSlug($this->release->applicationUuid());
		$flow->setTrigger(Flow::TRIGGER_SCHEDULE);
		$flow->setCron($this->cron);
		$flow->setNodes($this->nodes());
		$flow->setEdges($this->edges());
		$flow->setEnabled($this->schedule->enabled);
		$flow->setOwner($this->release->publisher);
		$flow->setOrganisation($this->release->organisation());
		$flow->setStatus(null);
		$flow->setStatusMessage(null);
		$flow->setUpdated(new DateTime());
	}//end applyTo()

	/**
	 * The graph's nodes.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private function nodes(): array {
		$trigger = ['cron' => $this->cron, 'runAs' => $this->release->publisher];

		return [
			['id' => 'schedule', 'type' => 'openregister.trigger-schedule', 'config' => $trigger],
			['id' => 'run', 'type' => $this->node['type'], 'config' => $this->node['config']],
			['id' => 'end', 'type' => 'openregister.end', 'config' => []],
		];
	}//end nodes()

	/**
	 * The graph's edges.
	 *
	 * @return array<int, array<string, string>> The edges.
	 */
	private function edges(): array {
		return [
			['id' => 'e1', 'from' => 'schedule', 'to' => 'run'],
			['id' => 'e2', 'from' => 'run', 'to' => 'end'],
		];
	}//end edges()
}//end class
