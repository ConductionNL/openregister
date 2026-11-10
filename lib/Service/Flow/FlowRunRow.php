<?php

/**
 * Builds the queued row for a dispatch that has already cleared every guard.
 *
 * 🔑 IT PERFORMS NO CHECKS, DELIBERATELY. Every guard — dead-end, published
 * version, attribution, delegation — runs in `FlowRunService::queue()` BEFORE
 * this is reached, so a check here would be a second copy of a rule that is
 * already enforced, and the two would drift. This class only writes down what
 * those decisions concluded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;

/**
 * The unsaved run a dispatch produces.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
class FlowRunRow {
	/**
	 * Name whose rights the run uses and which graph it is pinned to.
	 *
	 * Built per dispatch by queue(), once the attribution is resolved and the
	 * version is known; both are settled before the row is written.
	 *
	 * @param array        $attribution The resolved user/organisation/declaredBy.
	 * @param integer|null $version     The published version this run is pinned to,
	 *                                  or null for an interactive test run of a draft.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function __construct(
		private readonly array $attribution,
		private readonly ?int $version,
	) {
	}//end __construct()

	/**
	 * Build the queued row for a dispatch that has already cleared every guard.
	 *
	 * Split out of queue() only because that method reached the length limit;
	 * it has no callers of its own and must stay private. Every guard —
	 * dead-end, attribution, delegation — runs BEFORE this, so this method
	 * deliberately performs no checks and assumes an attributed dispatch.
	 *
	 * @param string              $flowId  The flow being dispatched.
	 * @param FlowRunQueueOptions $options The subject (when the trigger names one), what caused the dispatch and the
	 *                                     starting context; its user is not read here.
	 *
	 * @return FlowRun The unsaved run.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function build(string $flowId, FlowRunQueueOptions $options): FlowRun {
		$run = $this->queued(flowId: $flowId, trigger: $options->trigger);
		$run->setContext($options->context);
		$this->placeSubject(run: $run, subject: $options->subject);

		return $this->attributedAndPinned(run: $run);
	}//end build()

	/**
	 * A fresh queued run row for this flow and trigger, with an empty log.
	 *
	 * @param string $flowId  The flow being run.
	 * @param string $trigger What caused the run.
	 *
	 * @return FlowRun The row.
	 */
	private function queued(string $flowId, string $trigger): FlowRun {
		$run = new FlowRun();
		$run->setUuid($this->newUuid());
		$run->setFlowId($flowId);
		$run->setStatus(FlowRun::STATUS_QUEUED);
		$run->setTrigger($trigger);
		$run->setLog([]);

		return $run;
	}//end queued()

	/**
	 * Record the object the run is about, when there is one.
	 *
	 * @param FlowRun              $run     The row.
	 * @param array<string, mixed> $subject The subject's uuid, register and schema.
	 *
	 * @return void
	 */
	private function placeSubject(FlowRun $run, array $subject): void {
		$run->setSubjectUuid(($subject['uuid'] ?? null));
		$run->setSubjectRegister(($subject['register'] ?? null));
		$run->setSubjectSchema(($subject['schema'] ?? null));
	}//end placeSubject()

	/**
	 * Stamp who caused and who runs the run, its organisation, its times and its pinned version.
	 *
	 * @param FlowRun $run The row.
	 *
	 * @return FlowRun The row.
	 */
	private function attributedAndPinned(FlowRun $run): FlowRun {
		// Both, deliberately. `triggeredBy` is PROVENANCE and keeps recording who
		// caused the run; `runAs` is AUTHORIZATION and is what every access
		// decision reads from here on. They are equal at this point for a
		// caller-driven dispatch and differ for a scheduled one, where the cause
		// is a schedule and the acting identity is the user its trigger names.
		$run->setTriggeredBy($this->attribution['user']);
		$run->setRunAs($this->attribution['user']);
		$run->setOrganisation($this->attribution['organisation']);
		$run->setCreated(new DateTime());
		$run->setUpdated(new DateTime());

		// 🔴 PIN HERE, at queue time, and nowhere later. This is the only
		// moment the definition the caller MEANT to run is unambiguous: from
		// here the run may sit suspended on a human step for days while its
		// author drafts and publishes new versions. Authorization is NOT
		// pinned — see FlowDefinitionPin — because a revoked grant must stop
		// mattering immediately, while the graph must not move at all.
		$run->setFlowVersion($this->version);

		return $run;
	}//end attributedAndPinned()

	/**
	 * A v4 UUID for a new run.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	private function newUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end newUuid()
}//end class
