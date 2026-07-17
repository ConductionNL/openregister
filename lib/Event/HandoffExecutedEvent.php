<?php

/**
 * OpenRegister Handoff Executed Event
 *
 * Typed ADR-041 event dispatched AFTER a semantic-object handoff commits
 * (never inside the engine's transaction), so the app providing the target
 * kind can run its intake logic (case numbering, triage defaults, …) in its
 * own DI context. Carries full provenance plus the created target's
 * identifiers and a correlation id consumers echo back through the existing
 * ADR-041 conclusion-event pattern for terminal-state feedback. The
 * integration registry is NOT a transport for this (ADR-041 / gate-27).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Event dispatched after each successful (or deferred-drained) handoff.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: ADR-041 event emission on handoff execution)
 */
class HandoffExecutedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string      $sourceApp          The app owning the emitting schema ('' when undeclared).
     * @param int         $sourceRegister     Register id of the source object.
     * @param int         $sourceSchema       Schema id of the source object.
     * @param string      $sourceObjectUuid   UUID of the source object.
     * @param string|null $subjectLabel       Human-readable label of the source object.
     * @param string      $targetSemanticType The canonical kind URI that was handed off to.
     * @param int         $targetRegister     Register id of the created target object.
     * @param int         $targetSchema       Schema id of the created target object.
     * @param string      $targetObjectUuid   UUID of the created target object.
     * @param string      $handoffId          The declared handoff entry id.
     * @param string      $correlationId      Correlation id minted for this execution.
     * @param bool        $deferred           True when this execution drained a parked queue entry.
     */
    public function __construct(
        private readonly string $sourceApp,
        private readonly int $sourceRegister,
        private readonly int $sourceSchema,
        private readonly string $sourceObjectUuid,
        private readonly ?string $subjectLabel,
        private readonly string $targetSemanticType,
        private readonly int $targetRegister,
        private readonly int $targetSchema,
        private readonly string $targetObjectUuid,
        private readonly string $handoffId,
        private readonly string $correlationId,
        private readonly bool $deferred=false,
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * The app owning the emitting (source) schema.
     *
     * @return string App id, '' when the source schema declares no owning app.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getSourceApp(): string
    {
        return $this->sourceApp;

    }//end getSourceApp()

    /**
     * Register id of the source object.
     *
     * @return int The source register id.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getSourceRegister(): int
    {
        return $this->sourceRegister;

    }//end getSourceRegister()

    /**
     * Schema id of the source object.
     *
     * @return int The source schema id.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getSourceSchema(): int
    {
        return $this->sourceSchema;

    }//end getSourceSchema()

    /**
     * UUID of the source object.
     *
     * @return string The source object UUID.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getSourceObjectUuid(): string
    {
        return $this->sourceObjectUuid;

    }//end getSourceObjectUuid()

    /**
     * Human-readable label of the source object (provenance subject).
     *
     * @return string|null The subject label, when the source object has one.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getSubjectLabel(): ?string
    {
        return $this->subjectLabel;

    }//end getSubjectLabel()

    /**
     * The canonical semantic kind URI the object was handed off to.
     *
     * @return string The kind URI.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getTargetSemanticType(): string
    {
        return $this->targetSemanticType;

    }//end getTargetSemanticType()

    /**
     * Register id of the created target object.
     *
     * @return int The target register id.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getTargetRegister(): int
    {
        return $this->targetRegister;

    }//end getTargetRegister()

    /**
     * Schema id of the created target object.
     *
     * @return int The target schema id.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getTargetSchema(): int
    {
        return $this->targetSchema;

    }//end getTargetSchema()

    /**
     * UUID of the created target object.
     *
     * @return string The target object UUID.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getTargetObjectUuid(): string
    {
        return $this->targetObjectUuid;

    }//end getTargetObjectUuid()

    /**
     * The declared handoff entry id (dialect `id`).
     *
     * @return string The handoff id.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getHandoffId(): string
    {
        return $this->handoffId;

    }//end getHandoffId()

    /**
     * Correlation id minted per execution — also stamped on both audit rows
     * (and the queue entry for deferred executions) so consumers can echo it
     * back through the ADR-041 conclusion-event pattern.
     *
     * @return string The correlation id.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: ADR-041 event emission on handoff execution)
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId;

    }//end getCorrelationId()

    /**
     * Whether this execution drained a parked queue-mode entry.
     *
     * @return bool True for deferred (drained) executions.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Scenario: No provider installed, queue mode)
     */
    public function isDeferred(): bool
    {
        return $this->deferred;

    }//end isDeferred()
}//end class
