<?php

/**
 * The one task lifecycle, and the one mapping every legacy value goes through.
 *
 * The fleet spells task state in SIX incompatible vocabularies (`done` vs
 * `completed`, `cancelled` vs `terminated`, one in-progress state spelled
 * four ways). This class publishes the single mapping onto the six CMMN
 * plan-item states (ADR-098 D4), and every writer — migration or API —
 * resolves through it.
 *
 * Two rules make the mapping safe (design D-7):
 * - Values that COLLAPSE onto one state keep their distinction on `outcome`:
 *   `done` and `approved` both become `completed`, but their outcomes differ.
 * - An unrecognised value is REFUSED, naming itself. A coercing default
 *   would have absorbed procest writing `status:'open'` into an enum without
 *   `open` — a live defect that must surface during migration, loudly, once.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskValidationException;

/**
 * Resolves any fleet status vocabulary onto the six CMMN states.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
 */
final class TaskState {

	/**
	 * The published legacy mapping: value => [state, outcome].
	 *
	 * An outcome of null means the legacy value carried no distinction worth
	 * preserving beyond the state itself. The six canonical states map to
	 * themselves with no outcome, so canonical input passes through
	 * unchanged.
	 *
	 * @var array<string, array{0: string, 1: string|null}>
	 */
	private const LEGACY = [
		// Canonical: the six CMMN states pass through.
		Task::STATE_AVAILABLE => [Task::STATE_AVAILABLE, null],
		Task::STATE_ENABLED => [Task::STATE_ENABLED, null],
		Task::STATE_ACTIVE => [Task::STATE_ACTIVE, null],
		Task::STATE_COMPLETED => [Task::STATE_COMPLETED, null],
		Task::STATE_TERMINATED => [Task::STATE_TERMINATED, null],
		Task::STATE_DISABLED => [Task::STATE_DISABLED, null],
		// Not-yet-started spellings.
		'open' => [Task::STATE_ENABLED, null],
		'pending' => [Task::STATE_AVAILABLE, null],
		'todo' => [Task::STATE_ENABLED, null],
		'reopen' => [Task::STATE_ENABLED, 'reopened'],
		// The four spellings of in-progress (with `active` above making four).
		'in_progress' => [Task::STATE_ACTIVE, null],
		'in-progress' => [Task::STATE_ACTIVE, null],
		'in-execution' => [Task::STATE_ACTIVE, null],
		// Blocked is active work that cannot proceed; the WHY belongs on
		// `blocked_reason`, not in the state vocabulary.
		'blocked' => [Task::STATE_ACTIVE, 'blocked'],
		// Completions: one state, distinct outcomes.
		'done' => [Task::STATE_COMPLETED, 'done'],
		'resolved' => [Task::STATE_COMPLETED, 'resolved'],
		'approved' => [Task::STATE_COMPLETED, 'approved'],
		'rejected' => [Task::STATE_COMPLETED, 'rejected'],
		// Deliberate non-performance: CMMN `disabled`.
		'waived' => [Task::STATE_DISABLED, 'waived'],
		'skipped' => [Task::STATE_DISABLED, 'skipped'],
		// External terminations: one state, distinct outcomes.
		'cancelled' => [Task::STATE_TERMINATED, 'cancelled'],
		'expired' => [Task::STATE_TERMINATED, 'expired'],
		'error' => [Task::STATE_TERMINATED, 'error'],
		'dead_letter' => [Task::STATE_TERMINATED, 'dead_letter'],
	];

	/**
	 * Outcomes that reject or return the work, making a comment MANDATORY.
	 *
	 * @var array<int, string>
	 */
	public const REJECTING_OUTCOMES = ['rejected', 'returned', 'declined', 'denied'];

	/**
	 * Resolve a status value — canonical or legacy — onto a CMMN state.
	 *
	 * A migration that knows its SOURCE vocabulary passes it: a value the
	 * source's own enum does not define is then refused even when the fleet
	 * mapping happens to know the word. That is the procest defect made
	 * loud — `CreateTaskHandler.php:76` writes `status:'open'` into a schema
	 * whose enum has no `open`; imported against that declared vocabulary,
	 * the write fails naming the value instead of laundering it.
	 *
	 * @param string $value The incoming status value.
	 * @param array<int, string>|null $sourceVocabulary The source's own
	 *                                declared enum, when the caller has one;
	 *                                null accepts the full fleet mapping.
	 *
	 * @return array{state: string, outcome: string|null} The state, and the
	 *         outcome distinction the collapse preserved (null when none).
	 *
	 * @throws TaskValidationException When the value is in no known
	 *         vocabulary, or not in the declared source vocabulary. Never
	 *         coerced to a default.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	public static function normalise(string $value, ?array $sourceVocabulary = null): array {
		$trimmed = trim($value);

		if ($sourceVocabulary !== null && in_array($trimmed, $sourceVocabulary, true) === false) {
			throw new TaskValidationException(
				message: sprintf("Unmapped task status '%s': the declared source vocabulary does not define it, so it is refused, not defaulted.", $trimmed)
			);
		}

		if (array_key_exists($trimmed, self::LEGACY) === false) {
			throw new TaskValidationException(
				message: sprintf("Unmapped task status '%s': it is in no known fleet vocabulary and is refused, not defaulted.", $trimmed)
			);
		}

		[$state, $outcome] = self::LEGACY[$trimmed];

		return [
			'state' => $state,
			'outcome' => $outcome,
		];
	}//end normalise()

	/**
	 * Whether a state is terminal.
	 *
	 * @param string $state One of the six CMMN states.
	 *
	 * @return boolean True for completed, terminated and disabled.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	public static function isTerminal(string $state): bool {
		return in_array($state, Task::TERMINAL_STATES, true);
	}//end isTerminal()

	/**
	 * Whether an outcome rejects or returns the work.
	 *
	 * @param string|null $outcome The outcome, when any.
	 *
	 * @return boolean True when a non-empty comment is mandatory.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public static function isRejectingOutcome(?string $outcome): bool {
		if ($outcome === null) {
			return false;
		}

		return in_array(strtolower(trim($outcome)), self::REJECTING_OUTCOMES, true);
	}//end isRejectingOutcome()

	/**
	 * The published mapping, for documentation surfaces and tests.
	 *
	 * @return array<string, array{0: string, 1: string|null}> value => [state, outcome].
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	public static function mapping(): array {
		return self::LEGACY;
	}//end mapping()
}//end class
