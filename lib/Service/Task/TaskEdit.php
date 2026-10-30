<?php

/**
 * The fields of a task the people working it may change after creation.
 *
 * Title, description, due date and priority, validated as on creation: the
 * title may not be emptied, the due date is an ISO date (or null) that may
 * not lie after the expiry, the priority lands on the one scale. Nothing is
 * applied unless every field is valid.
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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-s-own-fields-can-be-edited-by-the-people-working-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskValidationException;
use Throwable;

/**
 * Validates and applies an edit of a task's own fields.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) TaskPriority is the one normaliser
 * every caller uses, as in TaskBuilder.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-s-own-fields-can-be-edited-by-the-people-working-it
 */
class TaskEdit {

	/**
	 * The fields an edit may change.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['title', 'description', 'dueAt', 'priority'];

	/**
	 * Apply an edit to a task.
	 *
	 * @param Task $task The task to change.
	 * @param array<string, mixed> $changes The new values by field.
	 *
	 * @return array<int, string> The fields that changed.
	 *
	 * @throws TaskValidationException When a field is unknown or invalid.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-s-own-fields-can-be-edited-by-the-people-working-it
	 */
	public function apply(Task $task, array $changes): array {
		$next = $this->validated(task: $task, changes: $changes);

		$changed = [];
		foreach ($next as $field => $value) {
			if ($this->same(current: $task->{'get' . ucfirst($field)}(), next: $value) === true) {
				continue;
			}

			$task->{'set' . ucfirst($field)}($value);
			$changed[] = $field;
		}

		return $changed;
	}//end apply()

	/**
	 * The new values, every one valid, or a refusal.
	 *
	 * @param Task $task The task, for its expiry.
	 * @param array<string, mixed> $changes The new values by field.
	 *
	 * @return array<string, mixed> The values to set by field.
	 *
	 * @throws TaskValidationException When a field is unknown or invalid.
	 */
	private function validated(Task $task, array $changes): array {
		$unknown = array_diff(array_keys($changes), self::FIELDS);
		if ($changes === [] || $unknown !== []) {
			$refused = 'nothing to change';
			if ($unknown !== []) {
				$refused = implode(', ', $unknown);
			}

			throw new TaskValidationException(
				message: sprintf('An edit changes one or more of %s; refused: %s.', implode(', ', self::FIELDS), $refused)
			);
		}

		$next = [];
		if (array_key_exists('title', $changes) === true) {
			$next['title'] = $this->text(value: $changes['title']);
			if ($next['title'] === null) {
				throw new TaskValidationException(message: "Field 'title' may not be emptied.");
			}
		}

		if (array_key_exists('description', $changes) === true) {
			$next['description'] = $this->text(value: $changes['description']);
		}

		if (array_key_exists('priority', $changes) === true) {
			$next['priority'] = TaskPriority::normalise(value: $changes['priority']);
		}

		if (array_key_exists('dueAt', $changes) === true) {
			$next['dueAt'] = $this->dueAt(task: $task, value: $changes['dueAt']);
		}

		return $next;
	}//end validated()

	/**
	 * A due date: an ISO date or null, not after the task's expiry.
	 *
	 * @param Task $task The task, for its expiry.
	 * @param mixed $value The incoming value.
	 *
	 * @return DateTime|null The date, or null to clear it.
	 *
	 * @throws TaskValidationException When it does not parse or lies after the expiry.
	 */
	private function dueAt(Task $task, mixed $value): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		$due = null;
		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
			try {
				$due = new DateTime($value);
			} catch (Throwable) {
				$due = null;
			}
		}

		if ($due === null) {
			throw new TaskValidationException(message: "Field 'dueAt' does not parse as a date.");
		}

		$expiresAt = $task->getExpiresAt();
		if ($expiresAt !== null && $expiresAt < $due) {
			throw new TaskValidationException(
				message: sprintf(
					"dueAt '%s' lies after expiresAt '%s': a task that dies before it is due is a configuration error.",
					$due->format('c'),
					$expiresAt->format('c')
				)
			);
		}

		return $due;
	}//end dueAt()

	/**
	 * Trimmed text, or null for absent or blank.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return string|null The text.
	 *
	 * @throws TaskValidationException When the value is not text.
	 */
	private function text(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (is_scalar($value) === false) {
			throw new TaskValidationException(message: 'A text field takes text.');
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()

	/**
	 * Whether a field keeps its value.
	 *
	 * @param mixed $current The stored value.
	 * @param mixed $next The new value.
	 *
	 * @return boolean True when unchanged.
	 */
	private function same(mixed $current, mixed $next): bool {
		if ($current instanceof DateTime && $next instanceof DateTime) {
			return $current->format('c') === $next->format('c');
		}

		return $current === $next;
	}//end same()
}//end class
