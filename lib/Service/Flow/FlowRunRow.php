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
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;

/**
 * The unsaved run a dispatch produces.
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowRunRow {
	/**
	 * Build the queued row for a dispatch that has already cleared every guard.
	 *
	 * Split out of queue() only because that method reached the length limit;
	 * it has no callers of its own and must stay private. Every guard —
	 * dead-end, attribution, delegation — runs BEFORE this, so this method
	 * deliberately performs no checks and assumes an attributed dispatch.
	 *
	 * @param string  $flowId      The flow being dispatched.
	 * @param array   $subject     The subject object, when the trigger names one.
	 * @param string  $trigger     What caused the dispatch.
	 * @param array   $context     The starting context.
	 * @param array   $attribution The resolved user/organisation/declaredBy.
	 * @param integer|null $version The published version this run is pinned to,
	 *                              or null for an interactive test run of a draft.
	 *
	 * @return FlowRun The unsaved run.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function build(
		string $flowId,
		array $subject,
		string $trigger,
		array $context,
		array $attribution,
		?int $version,
	): FlowRun {
		$run = new FlowRun();
		$run->setUuid($this->newUuid());
		$run->setFlowId($flowId);
		$run->setStatus(FlowRun::STATUS_QUEUED);
		$run->setTrigger($trigger);
		$run->setContext($context);
		$run->setLog([]);
		$run->setSubjectUuid(($subject['uuid'] ?? null));
		$run->setSubjectRegister(($subject['register'] ?? null));
		$run->setSubjectSchema(($subject['schema'] ?? null));
		// Both, deliberately. `triggeredBy` is PROVENANCE and keeps recording who
		// caused the run; `runAs` is AUTHORIZATION and is what every access
		// decision reads from here on. They are equal at this point for a
		// caller-driven dispatch and differ for a scheduled one, where the cause
		// is a schedule and the acting identity is the user its trigger names.
		$run->setTriggeredBy($attribution['user']);
		$run->setRunAs($attribution['user']);
		$run->setOrganisation($attribution['organisation']);
		$run->setCreated(new DateTime());
		$run->setUpdated(new DateTime());

		// 🔴 PIN HERE, at queue time, and nowhere later. This is the only
		// moment the definition the caller MEANT to run is unambiguous: from
		// here the run may sit suspended on a human step for days while its
		// author drafts and publishes new versions. Authorization is NOT
		// pinned — see FlowDefinitionPin — because a revoked grant must stop
		// mattering immediately, while the graph must not move at all.
		$run->setFlowVersion($version);

		return $run;

	}//end build()

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
