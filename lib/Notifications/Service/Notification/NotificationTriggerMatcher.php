<?php

/**
 * NotificationTriggerMatcher: does a declared trigger match an event.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Decides whether a notification's `trigger` block matches one active event.
 *
 * For `calculatedChange` triggers, both `condition` (new value) and
 * `previously` (old value) must be satisfied for the rule to fire: this is
 * the boundary-crossing / debounce check.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class NotificationTriggerMatcher {

	/**
	 * Constructor.
	 *
	 * @param string               $trigger The active event type, or the app event being raised.
	 * @param array<string, mixed> $context Per-event context (e.g. `action`).
	 */
	public function __construct(
		private readonly string $trigger,
		private readonly array $context,
	) {
	}//end __construct()

	/**
	 * Whether a declared trigger matches the event.
	 *
	 * A bare string names an app-defined event (`"booking.confirmed"`): the name
	 * is the whole contract, because the app decided the event was worth raising
	 * before it called dispatch(). The trigger is untrusted annotation data
	 * straight off a schema, so any other shape that is not an array fails closed.
	 *
	 * @param mixed $triggerSpec The declared `trigger` sub-document or event name.
	 *
	 * @return bool True when the rule should fire for this event.
	 */
	public function matches(mixed $triggerSpec): bool {
		if (is_array($triggerSpec) === false) {
			return $triggerSpec === $this->trigger;
		}

		// Object form may also name an app event explicitly.
		$declaredEvent = ($triggerSpec['event'] ?? null);
		if (is_string($declaredEvent) === true && $declaredEvent !== '') {
			return $declaredEvent === $this->trigger;
		}

		if ((string) ($triggerSpec['type'] ?? '') !== $this->trigger) {
			return false;
		}

		return $this->typeMatches(triggerSpec: $triggerSpec);
	}//end matches()

	/**
	 * Apply the filter of the trigger's own type.
	 *
	 * @param array<string, mixed> $triggerSpec The declared `trigger` block.
	 *
	 * @return bool
	 */
	private function typeMatches(array $triggerSpec): bool {
		return match ($this->trigger) {
			'transition' => $this->transitionActionMatches(triggerSpec: $triggerSpec),
			'created' => $this->createdTriggerMatches(triggerSpec: $triggerSpec),
			'updated' => $this->updatedTriggerMatches(triggerSpec: $triggerSpec),
			'calculatedChange' => $this->calculatedChangeMatches(triggerSpec: $triggerSpec),
			default => true,
		};
	}//end typeMatches()

	/**
	 * Apply a `transition` trigger's optional `action` filter: one action or a list of them.
	 *
	 * @param array<string, mixed> $triggerSpec The declared `trigger` block.
	 *
	 * @return bool True when no action is declared or the event's action is among them.
	 */
	private function transitionActionMatches(array $triggerSpec): bool {
		if (isset($triggerSpec['action']) === false) {
			return true;
		}

		$expected = $triggerSpec['action'];
		$actual = ($this->context['action'] ?? null);
		if (is_array($expected) === true) {
			return in_array($actual, $expected, true);
		}

		return (string) $expected === (string) $actual;
	}//end transitionActionMatches()

	/**
	 * Apply a `created` trigger's optional field `filter`.
	 *
	 * The rule fires only when the created object's fields, forwarded as
	 * `_data`, satisfy the filter (e.g. `channel == "telefoon"`). A rule
	 * without a filter matches on type alone. Fail-closed when the filter is
	 * malformed or the data is absent.
	 *
	 * @param array<string, mixed> $triggerSpec The declared `trigger` block.
	 *
	 * @return bool
	 */
	private function createdTriggerMatches(array $triggerSpec): bool {
		if (isset($triggerSpec['filter']) === false) {
			return true;
		}

		$filter = $triggerSpec['filter'];
		$data = ($this->context['_data'] ?? null);
		if (is_array($filter) === false || is_array($data) === false) {
			return false;
		}

		return self::createdFilterMatches(filter: $filter, data: $data);
	}//end createdTriggerMatches()

	/**
	 * Apply an `updated` trigger's optional non-numeric field-change `condition`.
	 *
	 * A rule without a condition matches on type alone. Reads the old and new
	 * object data the listener forwards; fail-closed when the condition is
	 * malformed or either side is absent.
	 *
	 * @param array<string, mixed> $triggerSpec The declared `trigger` block.
	 *
	 * @return bool
	 */
	private function updatedTriggerMatches(array $triggerSpec): bool {
		if (isset($triggerSpec['condition']) === false) {
			return true;
		}

		$condition = $triggerSpec['condition'];
		$change = $this->changedObjectData();
		if (is_array($condition) === false || $change === null) {
			return false;
		}

		return self::fieldChangeConditionMatches(condition: $condition, change: $change);
	}//end updatedTriggerMatches()

	/**
	 * Apply a `calculatedChange` trigger's boundary-crossing check.
	 *
	 * `field` names the calculated property to monitor; the NEW value must
	 * satisfy the `condition` operators and the OLD value the `previously`
	 * operators. A side that is not declared is open. Fail-closed when the
	 * field is missing or the old or new data is absent.
	 *
	 * @param array<string, mixed> $triggerSpec The declared `trigger` block.
	 *
	 * @return bool
	 */
	private function calculatedChangeMatches(array $triggerSpec): bool {
		$field = (string) ($triggerSpec['field'] ?? '');
		$change = $this->changedObjectData();
		if ($field === '' || $change === null) {
			return false;
		}

		return self::sideHolds(operators: ($triggerSpec['condition'] ?? null), value: ($change['new'][$field] ?? null))
			&& self::sideHolds(operators: ($triggerSpec['previously'] ?? null), value: ($change['old'][$field] ?? null));
	}//end calculatedChangeMatches()

	/**
	 * Whether one side of a boundary crossing holds; an undeclared side is open.
	 *
	 * @param mixed $operators The declared operators.
	 * @param mixed $value     The field value of that side.
	 *
	 * @return bool
	 */
	private static function sideHolds(mixed $operators, mixed $value): bool {
		return is_array($operators) === false
			|| (new ThresholdOperators())->allHold(value: $value, operators: $operators) === true;
	}//end sideHolds()

	/**
	 * The old and new object data an update event forwards, or null when either is absent.
	 *
	 * @return array{old: array, new: array}|null
	 */
	private function changedObjectData(): ?array {
		$newData = ($this->context['_newData'] ?? null);
		$oldData = ($this->context['_oldData'] ?? null);
		if (is_array($newData) === false || is_array($oldData) === false) {
			return null;
		}

		return ['old' => $oldData, 'new' => $newData];
	}//end changedObjectData()

	/**
	 * Evaluate a `created`-trigger field filter against the created object's data.
	 *
	 * Supports the contract's single-field shape `{ field, operator, value|values }`
	 * with operators `equals` (default), `in`, and `notIn`. Scalar comparison is
	 * string-cast. A filter naming an unknown field fails closed.
	 *
	 * @param array<string, mixed> $filter The `trigger.filter` sub-document.
	 * @param array<string, mixed> $data   The created object's data.
	 *
	 * @return bool True when the object satisfies the filter.
	 */
	private static function createdFilterMatches(array $filter, array $data): bool {
		$field = (string) ($filter['field'] ?? '');
		if ($field === '') {
			return false;
		}

		$operator = (string) ($filter['operator'] ?? 'equals');
		$actual = self::scalarText(value: ($data[$field] ?? null));
		if ($operator === 'in' || $operator === 'notIn') {
			return self::listFilterMatches(filter: $filter, actual: $actual);
		}

		return $actual === (string) ($filter['value'] ?? '');
	}//end createdFilterMatches()

	/**
	 * Evaluate an `in` or `notIn` filter; a `values` that is no list fails closed.
	 *
	 * @param array<string, mixed> $filter The filter.
	 * @param string               $actual The field value as text.
	 *
	 * @return bool
	 */
	private static function listFilterMatches(array $filter, string $actual): bool {
		$values = ($filter['values'] ?? []);
		if (is_array($values) === false) {
			return false;
		}

		$present = in_array($actual, array_map(self::scalarText(...), $values), true);

		return $present === ($filter['operator'] === 'in');
	}//end listFilterMatches()

	/**
	 * Evaluate a non-numeric field-change condition for an `updated` trigger.
	 *
	 * Compares one field's value between the old and new object data:
	 *   - `changed`: matches when old != new.
	 *   - `equals` (+ `value`): matches when new == value; when optional `from`
	 *     is present, also requires old == from (a specific `from` -> `value`
	 *     transition).
	 *
	 * Comparison is string-normalised, consistent with `eq`/`ne`. An
	 * empty/missing `field` returns false.
	 *
	 * @param array<string, mixed>          $condition The `condition` sub-document (`field`, `operator`, `value`, `from`).
	 * @param array{old: array, new: array} $change    The object's data before and after the update.
	 *
	 * @return bool True when the declared change occurred.
	 */
	private static function fieldChangeConditionMatches(array $condition, array $change): bool {
		$field = (string) ($condition['field'] ?? '');
		if ($field === '') {
			return false;
		}

		$oldText = self::scalarText(value: ($change['old'][$field] ?? null));
		$newText = self::scalarText(value: ($change['new'][$field] ?? null));

		return match ((string) ($condition['operator'] ?? 'changed')) {
			'changed' => $oldText !== $newText,
			'equals' => self::equalsConditionMatches(condition: $condition, change: ['old' => $oldText, 'new' => $newText]),
			default => false,
		};
	}//end fieldChangeConditionMatches()

	/**
	 * Evaluate the `equals` operator of a field-change condition.
	 *
	 * @param array<string, mixed>            $condition The condition.
	 * @param array{old: string, new: string} $change    The old and new field value as text.
	 *
	 * @return bool
	 */
	private static function equalsConditionMatches(array $condition, array $change): bool {
		if ($change['new'] !== (string) ($condition['value'] ?? '')) {
			return false;
		}

		return isset($condition['from']) === false || $change['old'] === (string) $condition['from'];
	}//end equalsConditionMatches()

	/**
	 * A scalar as text; anything else as the empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function scalarText(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string) $value;
		}

		return '';
	}//end scalarText()
}//end class
