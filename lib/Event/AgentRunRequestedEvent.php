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
 * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

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
 * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
 */
class AgentRunRequestedEvent extends Event
{

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
     * @param string      $subjectUuid      UUID of the triggering object.
     * @param string      $subjectRegister  Register slug/id of the triggering object.
     * @param string      $subjectSchema    Schema slug/id of the triggering object.
     * @param string      $agent            The configured agent reference (UUID in v1).
     * @param string|null $skill            Optional configured skill reference (slug).
     * @param string      $prompt           The fully-rendered prompt (placeholders already
     *                                      resolved by FlowActionService's template engine).
     * @param string      $resultField      The object field the run's output is written to.
     * @param bool        $requiresApproval Whether the run must pass a human-approval gate
     *                                      before executing.
     * @param string      $mode             Dispatch mode — `"async"` only in v1.
     * @param string      $flowName         The owning flow's name (diagnostics/audit).
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function __construct(
        private readonly string $subjectUuid,
        private readonly string $subjectRegister,
        private readonly string $subjectSchema,
        private readonly string $agent,
        private readonly ?string $skill,
        private readonly string $prompt,
        private readonly string $resultField,
        private readonly bool $requiresApproval,
        private readonly string $mode,
        private readonly string $flowName,
    ) {
        parent::__construct();
        $this->correlationId = Uuid::v4()->toRfc4122();
    }//end __construct()

    /**
     * Get the triggering object's UUID.
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getSubjectUuid(): string
    {
        return $this->subjectUuid;
    }//end getSubjectUuid()

    /**
     * Get the triggering object's register slug/id.
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getSubjectRegister(): string
    {
        return $this->subjectRegister;
    }//end getSubjectRegister()

    /**
     * Get the triggering object's schema slug/id.
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getSubjectSchema(): string
    {
        return $this->subjectSchema;
    }//end getSubjectSchema()

    /**
     * Get the configured agent reference (UUID).
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getAgent(): string
    {
        return $this->agent;
    }//end getAgent()

    /**
     * Get the optional configured skill reference (slug).
     *
     * @return string|null
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getSkill(): ?string
    {
        return $this->skill;
    }//end getSkill()

    /**
     * Get the fully-rendered prompt.
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getPrompt(): string
    {
        return $this->prompt;
    }//end getPrompt()

    /**
     * Get the object field the run's output is written to.
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getResultField(): string
    {
        return $this->resultField;
    }//end getResultField()

    /**
     * Whether the run must pass a human-approval gate before executing.
     *
     * @return bool
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function isRequiresApproval(): bool
    {
        return $this->requiresApproval;
    }//end isRequiresApproval()

    /**
     * Get the dispatch mode (`"async"` only in v1).
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getMode(): string
    {
        return $this->mode;
    }//end getMode()

    /**
     * Get the owning flow's name.
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getFlowName(): string
    {
        return $this->flowName;
    }//end getFlowName()

    /**
     * Get the generated correlation id for this dispatch.
     *
     * @return string
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getCorrelationId(): string
    {
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
     * @spec openspec/changes/flow-agent-action/tasks.md#task-1-1
     */
    public function getPayload(): array
    {
        return [
            'subjectUuid'      => $this->subjectUuid,
            'subjectRegister'  => $this->subjectRegister,
            'subjectSchema'    => $this->subjectSchema,
            'agent'            => $this->agent,
            'skill'            => $this->skill,
            'prompt'           => $this->prompt,
            'resultField'      => $this->resultField,
            'requiresApproval' => $this->requiresApproval,
            'mode'             => $this->mode,
            'flowName'         => $this->flowName,
            'correlationId'    => $this->correlationId,
        ];
    }//end getPayload()
}//end class
