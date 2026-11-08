<?php

/**
 * TaskStateRules: the task lifecycle rules other components ask about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Exception\TaskValidationException;

/**
 * The two TaskState rules controllers, commands and notifications use.
 *
 * TaskState is the one place the lifecycle vocabulary lives; it stays inside
 * lib/Service. Callers in other components ask through this class, which
 * answers with TaskState's own rule, unchanged.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Each rule is TaskState's static rule.
 */
final class TaskStateRules {

	/**
	 * Map an incoming status value onto the one lifecycle.
	 *
	 * @param string                  $value            The incoming status value.
	 * @param array<int, string>|null $sourceVocabulary The source's own declared enum, or null for the full mapping.
	 *
	 * @return array{state: string, outcome: string|null} The state, and the outcome distinction the collapse preserved.
	 *
	 * @throws TaskValidationException When the value is in no known vocabulary, or not in the declared one.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	public static function normalise(string $value, ?array $sourceVocabulary = null): array {
		return TaskState::normalise(value: $value, sourceVocabulary: $sourceVocabulary);
	}//end normalise()

	/**
	 * Whether this outcome rejects (and so requires a comment).
	 *
	 * @param string|null $outcome The outcome, when any.
	 *
	 * @return boolean True when a non-empty comment is mandatory.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public static function isRejectingOutcome(?string $outcome): bool {
		return TaskState::isRejectingOutcome(outcome: $outcome);
	}//end isRejectingOutcome()
}//end class
