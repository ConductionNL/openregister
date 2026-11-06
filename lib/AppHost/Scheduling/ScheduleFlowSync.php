<?php

/**
 * OpenRegister AppHost: applies a published manifest's schedules as scheduled flows.
 *
 * Each schedule becomes or updates one flow, a schedule gone from the manifest
 * loses its flow, and a repeat apply writes nothing. It runs in the publisher's
 * session. Without a publisher who is a member of the application's organisation
 * nothing is written: a write with no session would count as code-initiated and
 * skip the delegation check.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
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

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowTenantMembership;
use Throwable;

/**
 * Brings one application's compiled flows in line with its production manifest.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ScheduleManifest::fromManifest is its named constructor.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
class ScheduleFlowSync {
	/**
	 * Constructor.
	 *
	 * @param ScheduleFlowStore $store The compiled flows.
	 * @param FlowTenantMembership $membership Answers whether the publisher is in the organisation.
	 * @param ScheduleAuditTrail $audit Records identity changes.
	 * @param ScheduleRefusals $refusals Logs a refusal and keeps it for the response.
	 */
	public function __construct(
		private readonly ScheduleFlowStore $store,
		private readonly FlowTenantMembership $membership,
		private readonly ScheduleAuditTrail $audit,
		private readonly ScheduleRefusals $refusals,
	) {
	}//end __construct()

	/**
	 * Apply one published manifest.
	 *
	 * @param ScheduleRelease $release The publish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function apply(ScheduleRelease $release): void {
		if ($release->refusal !== null) {
			$this->refusals->refuse(release: $release, reason: $release->refusal);
			return;
		}

		$schedules = $this->schedulesOf(release: $release);
		if ($schedules !== [] && $this->mayPublish(release: $release) === false) {
			return;
		}

		$stored = $this->store->ofApplication(application: $release->applicationUuid());
		foreach ($schedules as $schedule) {
			$this->applySchedule(compiled: $this->compile(schedule: $schedule, release: $release), stored: $stored);
			unset($stored[$release->flowUuid(scheduleId: $schedule->id)]);
		}

		foreach ($stored as $flow) {
			$this->store->remove(flow: $flow);
		}
	}//end apply()

	/**
	 * The manifest's valid schedules, logging the entries that are not.
	 *
	 * @param ScheduleRelease $release The publish.
	 *
	 * @return ScheduleDescriptor[] The schedules.
	 */
	private function schedulesOf(ScheduleRelease $release): array {
		$manifest = ScheduleManifest::fromManifest(
			applicationId: $release->slug(),
			manifest: $release->manifest,
			cron: new CronScheduleEvaluator()
		);
		foreach ($manifest->diagnostics as $diagnostic) {
			$this->refusals->refuse(release: $release, reason: $diagnostic);
		}

		return $manifest->schedules;
	}//end schedulesOf()

	/**
	 * Whether this publish may set a schedule's identity and tenant.
	 *
	 * @param ScheduleRelease $release The publish.
	 *
	 * @return bool False after logging why not.
	 */
	private function mayPublish(ScheduleRelease $release): bool {
		$publisher = $release->publisher;
		$organisation = $release->organisation();
		if ($publisher === null) {
			$this->refusals->refuse(release: $release, reason: 'Nobody signed in published it.');
			return false;
		}

		if ($organisation === null) {
			$this->refusals->refuse(release: $release, reason: 'The application has no organisation.');
			return false;
		}

		if ($this->membership->isMember(uid: $publisher, organisation: $organisation) === false) {
			$this->refusals->refuse(release: $release, reason: 'The publisher is not a member of its organisation.');
			return false;
		}

		return true;
	}//end mayPublish()

	/**
	 * The flow of a schedule, or null after logging why it may not run.
	 *
	 * @param ScheduleDescriptor $schedule The schedule.
	 * @param ScheduleRelease $release The publish.
	 *
	 * @return CompiledScheduleFlow|null The flow.
	 */
	private function compile(ScheduleDescriptor $schedule, ScheduleRelease $release): ?CompiledScheduleFlow {
		$cron = $schedule->cronExpression();
		if ($cron === null) {
			$this->refusals->refuse(release: $release, reason: 'Schedule "' . $schedule->id . '": no exact cron for its interval.');
			return null;
		}

		$node = (new ScheduleActionNodes())->flowNode(schedule: $schedule);
		if ($node === null) {
			$this->refusals->refuse(release: $release, reason: 'Schedule "' . $schedule->id . '": action or argument not allowed.');
			return null;
		}

		return new CompiledScheduleFlow(release: $release, schedule: $schedule, cron: $cron, node: $node);
	}//end compile()

	/**
	 * Create or update one schedule's flow, when it differs from what is stored.
	 *
	 * One schedule failing must not stop the others of the manifest.
	 *
	 * @param CompiledScheduleFlow|null $compiled The flow, or null when refused.
	 * @param array<string, Flow> $stored The application's stored flows.
	 *
	 * @return void
	 */
	private function applySchedule(?CompiledScheduleFlow $compiled, array $stored): void {
		if ($compiled === null) {
			return;
		}

		$flow = ($stored[$compiled->uuid()] ?? null);
		if ($flow !== null && $compiled->matches(flow: $flow) === true) {
			return;
		}

		try {
			$this->recordIdentity(compiled: $compiled, previous: $flow?->scheduledRunAs());
			$this->store->write(compiled: $compiled, stored: $flow);
		} catch (Throwable $e) {
			$this->refusals->refuse(release: $compiled->release, reason: $e->getMessage());
		}
	}//end applySchedule()

	/**
	 * Audit a changed identity, before the write: no identity changes unaudited.
	 *
	 * @param CompiledScheduleFlow $compiled The flow.
	 * @param string|null $previous The stored identity.
	 *
	 * @return void
	 */
	private function recordIdentity(CompiledScheduleFlow $compiled, ?string $previous): void {
		if ($previous !== $compiled->release->publisher) {
			$this->audit->recordRunAs(compiled: $compiled, previous: $previous);
		}
	}//end recordIdentity()
}//end class
