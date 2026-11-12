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

use OCA\OpenRegister\Service\Handoff\HandoffRequest;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched after each successful (or deferred-drained) handoff.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: ADR-041 event emission on handoff execution)
 */
class HandoffExecutedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param HandoffExecutedSource $source  The owning app, the source object and its label.
	 * @param HandoffExecutedTarget $target  The kind handed off to and the created target object.
	 * @param HandoffRequest        $request The handoff id, correlation id and deferred flag.
	 */
	public function __construct(
		private readonly HandoffExecutedSource $source,
		private readonly HandoffExecutedTarget $target,
		private readonly HandoffRequest $request,
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
	public function getSourceApp(): string {
		return $this->source->app;
	}//end getSourceApp()

	/**
	 * Register id of the source object.
	 *
	 * @return int The source register id.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getSourceRegister(): int {
		return (int)$this->source->object->register;
	}//end getSourceRegister()

	/**
	 * Schema id of the source object.
	 *
	 * @return int The source schema id.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getSourceSchema(): int {
		return (int)$this->source->object->schema;
	}//end getSourceSchema()

	/**
	 * UUID of the source object.
	 *
	 * @return string The source object UUID.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getSourceObjectUuid(): string {
		return $this->source->object->id;
	}//end getSourceObjectUuid()

	/**
	 * Human-readable label of the source object (provenance subject).
	 *
	 * @return string|null The subject label, when the source object has one.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getSubjectLabel(): ?string {
		return $this->source->label;
	}//end getSubjectLabel()

	/**
	 * The canonical semantic kind URI the object was handed off to.
	 *
	 * @return string The kind URI.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getTargetSemanticType(): string {
		return $this->target->semanticType;
	}//end getTargetSemanticType()

	/**
	 * Register id of the created target object.
	 *
	 * @return int The target register id.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getTargetRegister(): int {
		return (int)$this->target->object->register;
	}//end getTargetRegister()

	/**
	 * Schema id of the created target object.
	 *
	 * @return int The target schema id.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getTargetSchema(): int {
		return (int)$this->target->object->schema;
	}//end getTargetSchema()

	/**
	 * UUID of the created target object.
	 *
	 * @return string The target object UUID.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getTargetObjectUuid(): string {
		return $this->target->object->id;
	}//end getTargetObjectUuid()

	/**
	 * The declared handoff entry id (dialect `id`).
	 *
	 * @return string The handoff id.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: ADR-041 event emission on handoff execution)
	 */
	public function getHandoffId(): string {
		return $this->request->handoffId;
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
	public function getCorrelationId(): string {
		return $this->request->correlationId;
	}//end getCorrelationId()

	/**
	 * Whether this execution drained a parked queue-mode entry.
	 *
	 * @return bool True for deferred (drained) executions.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	public function isDeferred(): bool {
		return $this->request->deferred;
	}//end isDeferred()
}//end class
