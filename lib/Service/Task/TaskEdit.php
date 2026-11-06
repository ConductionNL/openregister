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
	 * Fields are checked in a fixed order, title, description, priority and
	 * dueAt, whatever order the edit lists them in, so the first refusal is
	 * always the same one.
	 *
	 * @param Task $task The task, for its expiry.
	 * @param array<string, mixed> $changes The new values by field.
	 *
	 * @return array<string, mixed> The values to set by field.
	 *
	 * @throws TaskValidationException When a field is unknown or invalid.
	 */
	private function validated(Task $task, array $changes): array {
		self::refuseUnknownFields(changes: $changes);

		$validators = [
			'title' => fn (mixed $value): string => $this->title(value: $value),
			'description' => fn (mixed $value): ?string => $this->text(value: $value),
			'priority' => static fn (mixed $value): string => TaskPriority::normalise(value: $value),
			'dueAt' => fn (mixed $value): ?DateTime => $this->dueAt(task: $task, value: $value),
		];
		$next       = [];
		foreach (array_intersect_key($validators, $changes) as $field => $validate) {
			$next[$field] = $validate($changes[$field]);
		}

		return $next;
	}//end validated()

	/**
	 * Refuse an empty edit, or one naming a field an edit may not change.
	 *
	 * @param array<string, mixed> $changes The new values by field.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When the edit is empty or names an unknown field.
	 */
	private static function refuseUnknownFields(array $changes): void {
		$unknown = array_diff(array_keys($changes), self::FIELDS);
		if ($changes !== [] && $unknown === []) {
			return;
		}

		$refused = 'nothing to change';
		if ($unknown !== []) {
			$refused = implode(', ', $unknown);
		}

		throw new TaskValidationException(
			message: sprintf('An edit changes one or more of %s; refused: %s.', implode(', ', self::FIELDS), $refused)
		);
	}//end refuseUnknownFields()

	/**
	 * A new title: trimmed text that may not be emptied.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return string The title.
	 *
	 * @throws TaskValidationException When the value is not text or is blank.
	 */
	private function title(mixed $value): string {
		$title = $this->text(value: $value);
		if ($title === null) {
			throw new TaskValidationException(message: "Field 'title' may not be emptied.");
		}

		return $title;
	}//end title()

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

		$due = self::isoDate(value: $value);
		if ($due === null) {
			throw new TaskValidationException(message: "Field 'dueAt' does not parse as a date.");
		}

		self::refuseAfterExpiry(due: $due, expiresAt: $task->getExpiresAt());

		return $due;
	}//end dueAt()

	/**
	 * The date a string starting with an ISO `YYYY-MM-DD` spells, or null.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return DateTime|null The date.
	 */
	private static function isoDate(mixed $value): ?DateTime {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable) {
			return null;
		}
	}//end isoDate()

	/**
	 * Refuse a due date after the task's expiry: a task that dies before it is due.
	 *
	 * @param DateTime      $due       The due date.
	 * @param DateTime|null $expiresAt The task's expiry, or null.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When the due date lies after the expiry.
	 */
	private static function refuseAfterExpiry(DateTime $due, ?DateTime $expiresAt): void {
		if ($expiresAt === null || $expiresAt >= $due) {
			return;
		}

		throw new TaskValidationException(
			message: sprintf(
				"dueAt '%s' lies after expiresAt '%s': a task that dies before it is due is a configuration error.",
				$due->format('c'),
				$expiresAt->format('c')
			)
		);
	}//end refuseAfterExpiry()

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
