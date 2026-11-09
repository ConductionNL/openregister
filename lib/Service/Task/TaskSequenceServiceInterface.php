<?php

/**
 * Provisions and progresses an approval task sequence, as the listeners outside lib/Service drive it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;

/**
 * Provisions and progresses an approval task sequence, as the listeners outside lib/Service drive it.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see TaskSequenceService} in ContractBindings.
 */
interface TaskSequenceServiceInterface {

	/**
	 * Provision a sequence from a compiled template.
	 *
	 * Creates every position and enables ONLY the first. The template
	 * snapshot and the resolved tier are FROZEN onto the sequence row, so a
	 * schema edit or an amount edit cannot re-shape or re-route an approval
	 * that is already running (design D-3).
	 *
	 * @param ResolvedChain  $chain  The compiled template
	 *                               ({@see \OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller::compile()})
	 *                               and the resolved amount tier, or null for every declared position.
	 * @param SequenceAnchor $anchor The object the approval is about, who attempted the gated transition,
	 *                               the anchor's register, and optional flow-run and node provenance.
	 *
	 * @return TaskSequence The provisioned, running sequence.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function provision(ResolvedChain $chain, SequenceAnchor $anchor): TaskSequence;

	/**
	 * The approval chain a sequence walks: a compiled template and its resolved tier.
	 *
	 * Built here, so callers outside this component never name the chain class.
	 *
	 * @param array<string, mixed>                  $template      The compiled template.
	 * @param array<int, array<string, mixed>>|null $tierPositions The resolved amount tier, or null for every declared position.
	 *
	 * @return ResolvedChain The chain, to hand to {@see provision()}.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function chain(array $template, ?array $tierPositions = null): ResolvedChain;

	/**
	 * What a sequence is about: the object, the requester and the register.
	 *
	 * Built here, so callers outside this component never name the anchor class.
	 *
	 * @param string      $objectUuid  The object the approval is about.
	 * @param string|null $requesterId Who attempted the gated transition.
	 * @param int|null    $registerId  The anchor's register.
	 *
	 * @return SequenceAnchor The anchor, to hand to {@see provision()}.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function anchorOf(string $objectUuid, ?string $requesterId, ?int $registerId = null): SequenceAnchor;

	/**
	 * Progress a sequence after one of its tasks reached a terminal state.
	 *
	 * Called in the SAME request as the completing decision, after the
	 * task's own transaction committed. Three paths:
	 *
	 * - an approving completion enables the next position, or completes the
	 *   sequence and dispatches {@see \OCA\OpenRegister\Event\TaskSequenceCompletedEvent}
	 * - a rejecting completion closes the sequence as rejected (KEPT, never
	 *   deleted; design D-5) and terminates every remaining task with a
	 *   reason naming the rejecting position
	 * - a completion that neither approves nor rejects closes the sequence as
	 *   undecided, terminates the remainder and dispatches
	 *   {@see \OCA\OpenRegister\Event\TaskSequenceUndecidedEvent}
	 * - any other terminal end (cancelled, terminated, disabled) closes the
	 *   sequence as terminated and terminates the remainder
	 *
	 * An outcome approves only when it is in the frozen template's
	 * `approvingOutcomes`, or in {@see TaskState::APPROVING_OUTCOMES} when the
	 * template declares none.
	 *
	 * Idempotent: a terminal sequence absorbs every further report, which is
	 * exactly what the remainder-termination fan-out produces.
	 *
	 * @param Task $task The sequence task as persisted in a terminal state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-rejection-terminates-the-sequence-and-every-task-it-still-owns
	 * @spec openspec/changes/a-sequence-advances-only-on-an-approving-outcome/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
	 */
	public function onTaskTerminal(Task $task): void;
}//end interface
