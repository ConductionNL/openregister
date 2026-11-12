<?php

/**
 * OpenRegister AgentRunRequestedEvent
 *
 * Cross-app command event (ADR-041): dispatched by FlowActionService when a
 * declarative `x-openregister-flows` action of `type: "agent"` fires. OpenRegister
 * does NOT invoke an agent runtime itself and does NOT call Hermiq directly
 * (direct cross-app RPC is gate-27-forbidden) — it only carries the provenance and
 * the requested-run payload. A consuming app (Hermiq) registers an IEventListener
 * for this event and performs the governed run through its own services.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\EventDispatcher\Event;
use Symfony\Component\Uid\Uuid;

/**
 * Event dispatched to request a governed agent run against a triggering object.
 *
 * Carries provenance (the triggering object's uuid/register/schema) plus the
 * requested-run payload (agent/skill refs, the rendered prompt, the field to write
 * the result to, whether human approval is required, and the dispatch mode).
 * `mode` is `"async"` only in v1 — a consumer MUST treat any other mode as
 * unsupported and skip the run rather than execute it inline.
 *
 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
 */
class AgentRunRequestedEvent extends Event {

	/**
	 * A generated correlation id, unique per dispatch — lets a consumer de-duplicate
	 * a gated (pending-approval) run without a second identity scheme.
	 *
	 * @var string
	 */
	private string $correlationId;

	/**
	 * Constructor.
	 *
	 * @param ObjectRef           $subject     Register, schema and UUID of the triggering object.
	 * @param AgentRunInstruction $instruction The agent, skill, rendered prompt and result field.
	 * @param AgentRunDispatch    $dispatch    The approval gate, dispatch mode and owning flow.
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function __construct(
		private readonly ObjectRef $subject,
		private readonly AgentRunInstruction $instruction,
		private readonly AgentRunDispatch $dispatch,
	) {
		parent::__construct();
		$this->correlationId = Uuid::v4()->toRfc4122();
	}//end __construct()

	/**
	 * Get the triggering object's UUID.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getSubjectUuid(): string {
		return $this->subject->id;
	}//end getSubjectUuid()

	/**
	 * Get the triggering object's register slug/id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getSubjectRegister(): string {
		return (string)$this->subject->register;
	}//end getSubjectRegister()

	/**
	 * Get the triggering object's schema slug/id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getSubjectSchema(): string {
		return (string)$this->subject->schema;
	}//end getSubjectSchema()

	/**
	 * Get the configured agent reference (UUID).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getAgent(): string {
		return $this->instruction->agent;
	}//end getAgent()

	/**
	 * Get the optional configured skill reference (slug).
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getSkill(): ?string {
		return $this->instruction->skill;
	}//end getSkill()

	/**
	 * Get the fully-rendered prompt.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getPrompt(): string {
		return $this->instruction->prompt;
	}//end getPrompt()

	/**
	 * Get the object field the run's output is written to.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getResultField(): string {
		return $this->instruction->resultField;
	}//end getResultField()

	/**
	 * Whether the run must pass a human-approval gate before executing.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function isRequiresApproval(): bool {
		return $this->dispatch->requiresApproval;
	}//end isRequiresApproval()

	/**
	 * Get the dispatch mode (`"async"` only in v1).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getMode(): string {
		return $this->dispatch->mode;
	}//end getMode()

	/**
	 * Get the owning flow's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getFlowName(): string {
		return $this->dispatch->flowName;
	}//end getFlowName()

	/**
	 * Get the generated correlation id for this dispatch.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Flatten the event into a plain, JSON-serialisable payload.
	 *
	 * Consumers that hand this off to a background job (the async contract — a
	 * job argument must be a scalar-only array) use this instead of passing the
	 * event object across the job boundary.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
	 */
	public function getPayload(): array {
		return [
			'subjectUuid' => $this->subject->id,
			'subjectRegister' => (string)$this->subject->register,
			'subjectSchema' => (string)$this->subject->schema,
			'agent' => $this->instruction->agent,
			'skill' => $this->instruction->skill,
			'prompt' => $this->instruction->prompt,
			'resultField' => $this->instruction->resultField,
			'requiresApproval' => $this->dispatch->requiresApproval,
			'mode' => $this->dispatch->mode,
			'flowName' => $this->dispatch->flowName,
			'correlationId' => $this->correlationId,
		];
	}//end getPayload()
}//end class
