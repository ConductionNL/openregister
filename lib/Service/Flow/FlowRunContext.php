<?php

/**
 * Which flow run, node and step is executing right now.
 *
 * A run already records what it DID, step by step ({@see \OCA\OpenRegister\Db\FlowRunStep}).
 * It did not record what it did it TO: `FlowRun::$subjectUuid` names the object
 * that TRIGGERED the run, and nothing named the objects the run went on to
 * touch. This class is the missing half — it makes the executing step
 * discoverable at the moment a write happens, so the audit row can name it.
 *
 * WHY AMBIENT RATHER THAN A PARAMETER. The point is to attribute writes made by
 * code that has never heard of flows: a leaf app a node calls into, a cascade,
 * a lifecycle hook. Threading attribution through `ObjectService` would capture
 * only what a node explicitly passed, which is the same blind spot as recording
 * touches at the node — the node cannot report what it did not know it did.
 *
 * WHY A STACK RATHER THAN A SCALAR. A sub-flow node dispatches a nested run.
 * Popping to EMPTY at the end of that nested walk would leave the parent's
 * remaining steps in the same dispatch unattributed — silently, because an
 * unattributed row is well-formed and looks exactly like an ordinary one.
 * Popping to the enclosing frame keeps both runs correctly attributed.
 *
 * 🔴 THE FAILURE MODE THIS CLASS HAS. A frame that is pushed and not popped
 * attributes every LATER write in the process to a run that has already
 * finished. `FlowRunWorker` advances several runs per process, so the damage
 * crosses runs, not just steps. It produces no error and no wrong-looking row.
 * That is why {@see \OCA\OpenRegister\Service\Flow\RegistryStepDispatcher}
 * pops in a `finally` rather than on the success path, and why the test that
 * matters asserts the LEAK direction — a non-flow write AFTER a run must be
 * unattributed. A test that only checks the happy path passes with the leak
 * present.
 *
 * This service MUST be registered as a shared instance. An auto-wired class in
 * Nextcloud's container is constructed fresh per injection point, which would
 * give the dispatcher and the audit mapper two different stacks and quietly
 * attribute nothing at all.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Holds the ambient attribution frame for the step currently being dispatched.
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */
class FlowRunContext {
	/**
	 * The context key carrying the executing run's uuid into the engine.
	 *
	 * @var string
	 */
	public const CONTEXT_RUN = 'x-openregister-attribution-run';

	/**
	 * The context key carrying the run's step-sequence base into the engine.
	 *
	 * Steps are numbered continuously across a resume, so a segment that starts
	 * after a suspension must begin where the previous one stopped rather than
	 * at zero. The base is read once before the walk and the engine adds each
	 * hop's index to it, which is what makes an attributed audit row's step
	 * number the SAME number the matching `FlowRunStep` row is given.
	 *
	 * @var string
	 */
	public const CONTEXT_BASE = 'x-openregister-attribution-base';

	/**
	 * The stack of attribution frames, innermost last.
	 *
	 * A frame is `['run' => string, 'node' => string, 'step' => int]`, or NULL
	 * for a hop that is not attributable (see {@see push()}).
	 *
	 * @var array<int, array{run: string, node: string, step: int}|null>
	 */
	private array $frames = [];

	/**
	 * Enter a step: everything written from here until the matching pop is
	 * attributed to this run, node and step.
	 *
	 * A null or empty `$runUuid` pushes an explicit NON-attributing frame
	 * rather than pushing nothing. Two reasons, both about failure modes:
	 *
	 * - It keeps push and pop unconditionally paired, so the caller cannot
	 *   unbalance the stack by taking a different branch on the way out than
	 *   it took on the way in.
	 * - It stops an unattributable inner hop from silently inheriting the
	 *   ENCLOSING run's attribution, which would file a sub-flow's writes
	 *   under its parent — wrong, and wrong in a way that reads as correct.
	 *
	 * @param string|null $runUuid  The uuid of the executing run, or null when the caller has no run (the flow tester, node unit tests).
	 * @param string      $nodeId   The id of the node being dispatched.
	 * @param integer     $sequence The step's sequence number within the run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function push(?string $runUuid, string $nodeId, int $sequence): void {
		if ($runUuid === null || trim($runUuid) === '') {
			$this->frames[] = null;
			return;
		}

		$this->frames[] = [
			'run' => $runUuid,
			'node' => $nodeId,
			'step' => $sequence,
		];
	}//end push()

	/**
	 * Leave the innermost step, restoring the enclosing one if there is one.
	 *
	 * Popping an empty stack is deliberately NOT an error. This is called from
	 * a `finally`, and a `finally` that can itself throw would replace the
	 * exception the step actually failed with — turning a diagnosable node
	 * failure into a confusing context-bookkeeping error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function pop(): void {
		array_pop($this->frames);
	}//end pop()

	/**
	 * The frame to attribute a write to, or null when nothing is executing.
	 *
	 * @return array{run: string, node: string, step: int}|null The innermost frame, or null outside any run.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function current(): ?array {
		if ($this->frames === []) {
			return null;
		}

		// The INNERMOST frame, even when it is null. A null innermost frame
		// means "the hop currently executing is not attributable", and must not
		// fall through to an enclosing run's frame.
		return $this->frames[(count($this->frames) - 1)];
	}//end current()

	/**
	 * How deep the stack is. Test and diagnostic use only.
	 *
	 * Exposed so a test can assert the stack is EMPTY after a step rather than
	 * inferring it from an attribution being absent — an assertion that would
	 * also pass if attribution were broken for some unrelated reason.
	 *
	 * @return integer The number of frames currently held.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function depth(): int {
		return count($this->frames);
	}//end depth()
}//end class
