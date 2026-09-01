<?php

/**
 * Thrown when a flow is asked to run but nothing names whose rights it uses.
 *
 * WHY A REFUSAL AND NOT A FALLBACK
 * --------------------------------
 * The tempting fallback is the flow's own `owner`, and it was the behaviour
 * until ADR-099. It is wrong because `flow.owner` answers a different question:
 * who may EDIT this definition, and which tenant it belongs to. Treating it as
 * an acting identity converts "this person authored a flow" into "this person
 * consented to unattended execution as themselves, under whatever triggers
 * anyone later adds to it" — which they never agreed to, and which nothing
 * records them agreeing to.
 *
 * The other tempting fallback is no identity at all. That one is worse, and the
 * codebase already measured it: a run queued with no owner reaches
 * `ObjectWriteNode`, which refuses with "User 'Anonymous' does not have
 * permission to 'create' objects in schema '…'". So a natively scheduled flow
 * was silently incapable of writing anything, and said so in the vocabulary of
 * a PERMISSIONS problem — sending the reader to the RBAC configuration when the
 * actual fault was that the dispatch named nobody.
 *
 * WHY IT IS RAISED AT THE QUEUE
 * -----------------------------
 * `FlowRunService::queue()` is the single choke point every dispatch path passes
 * through: manual, object trigger, schedule, MCP, the workflow-engine operation,
 * and a sub-flow invocation. Refusing here means no run row is written, so a
 * half-executed run cannot exist. Refusing later — at the first node that needs
 * an identity — means every node BEFORE it has already run, and a flow can send
 * mail and then fail to record why it did.
 *
 * CATCHING IT
 * -----------
 * The schedule sweep MUST catch this per flow, exactly as it catches
 * {@see FlowDeadEnd}. The sweep iterates every due flow; letting this propagate
 * would abort the sweep and stop every later flow from firing, so one flow
 * missing an identity would silently disable the rest — a far bigger outage than
 * the one being reported.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use RuntimeException;

/**
 * A flow was asked to run without an acting identity.
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */
class FlowUnattributed extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * The message names the TRIGGER as well as the flow, because the two point
	 * at different fixes. A `manual` dispatch with no identity means the caller
	 * lost its session; a `schedule` dispatch with no identity means the trigger
	 * node declares no `runAs`. Reporting only the flow would leave the reader
	 * to guess which.
	 *
	 * @param string $flowId  The flow that could not be attributed.
	 * @param string $trigger What started the run — `manual`, `schedule`, an
	 *                        event id, `sub-flow`, `nc-flow`.
	 */
	public function __construct(
		private readonly string $flowId,
		private readonly string $trigger,
	) {
		parent::__construct(
			message: sprintf(
				'Refusing to start flow "%s": the "%s" trigger named no acting identity. '
				. 'A run started by a person acts as that person; a run started on a schedule acts as the '
				. 'user its schedule trigger declares in "runAs". The flow\'s owner is not used as a '
				. 'fallback — authoring a flow is not consent to unattended execution as its author.',
				$flowId,
				$trigger
			)
		);
	}//end __construct()

	/**
	 * The flow that could not be attributed.
	 *
	 * @return string The flow uuid.
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function getFlowId(): string {
		return $this->flowId;
	}//end getFlowId()

	/**
	 * What started the run.
	 *
	 * @return string The trigger kind.
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function getTrigger(): string {
		return $this->trigger;
	}//end getTrigger()
}//end class
