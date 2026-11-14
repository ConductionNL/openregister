<?php

/**
 * NotificationReminderValidator: the `reminder` block of a notification rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;

/**
 * Validates a reminder declared on a notification rule, at schema save.
 *
 * A reminder counts from a date property of the object (`anchor`) by an
 * `offset` in the timers' `{value, unit}` shape, may repeat at an `interval`
 * up to a `max`, and may stop itself on a `stopWhen` condition in the
 * scheduled trigger's filter grammar:
 *
 * ```json
 * "reminder": {
 *   "anchor": "hearingDate",
 *   "offset": {"value": -3, "unit": "businessDays"},
 *   "repeat": {"interval": {"value": 7, "unit": "calendarDays"}, "max": 3},
 *   "stopWhen": {"status": {"operator": "equals", "value": "closed"}}
 * }
 * ```
 *
 * A repeat without a maximum is refused: a reminder that cannot end is a
 * mailing list nobody can leave.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
final class NotificationReminderValidator {

	/**
	 * JSON-schema formats that hold a date.
	 */
	private const DATE_FORMATS = ['date', 'date-time'];

	/**
	 * Property types that hold a date on their own.
	 */
	private const DATE_TYPES = ['date', 'datetime', 'date-time'];

	/**
	 * Constructor.
	 *
	 * @param ScheduledFilterParser $filterParser Parses the stop condition.
	 */
	public function __construct(
		private readonly ScheduledFilterParser $filterParser,
	) {
	}//end __construct()

	/**
	 * The errors of a rule's reminder; none when the rule declares no reminder.
	 *
	 * @param NotificationRuleContext $rule The rule and the schema it is declared on.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function validate(NotificationRuleContext $rule): array {
		if (array_key_exists('reminder', $rule->spec) === false) {
			return [];
		}

		$reminder = $rule->spec['reminder'];
		if (is_array($reminder) === false || $reminder === [] || array_is_list($reminder) === true) {
			return [$this->error(rule: $rule, field: 'reminder', value: $reminder, message: 'reminder must be an object')];
		}

		return array_merge(
			$this->anchorErrors(rule: $rule, anchor: ($reminder['anchor'] ?? null)),
			$this->amountErrors(rule: $rule, field: 'reminder.offset', amount: ($reminder['offset'] ?? null), positive: false),
			$this->repeatErrors(rule: $rule, reminder: $reminder),
			$this->stopErrors(rule: $rule, reminder: $reminder)
		);
	}//end validate()

	/**
	 * The anchor must name a date property the schema declares.
	 *
	 * @param NotificationRuleContext $rule   The rule.
	 * @param mixed                   $anchor The declared anchor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function anchorErrors(NotificationRuleContext $rule, mixed $anchor): array {
		if (is_string($anchor) === false || $anchor === '') {
			return [$this->error(rule: $rule, field: 'reminder.anchor', value: $anchor, message: 'reminder.anchor must name a date property')];
		}

		$problem = null;
		if (array_key_exists($anchor, $rule->schema->properties) === false) {
			$problem = 'reminder.anchor "%s" is not a property of this schema';
		} else if (self::isDate(property: $rule->schema->properties[$anchor]) === false) {
			$problem = 'reminder.anchor "%s" is not a date property';
		}

		if ($problem === null) {
			return [];
		}

		return [$this->error(rule: $rule, field: 'reminder.anchor', value: $anchor, message: sprintf($problem, $anchor))];
	}//end anchorErrors()

	/**
	 * An amount must be `{value, unit}` with a number and a timer unit; an interval must also be positive.
	 *
	 * @param NotificationRuleContext $rule     The rule.
	 * @param string                  $field    The field path, for the error.
	 * @param mixed                   $amount   The declared amount.
	 * @param bool                    $positive Whether the value must be above zero.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function amountErrors(NotificationRuleContext $rule, string $field, mixed $amount, bool $positive): array {
		$value = null;
		$unit  = null;
		if (is_array($amount) === true) {
			$value = ($amount['value'] ?? null);
			$unit  = ($amount['unit'] ?? null);
		}

		if (is_int($value) === false && is_float($value) === false) {
			return [$this->error(rule: $rule, field: $field, value: $amount, message: sprintf('%s must be {"value": <number>, "unit": <unit>}', $field))];
		}

		if ($positive === true && $value <= 0) {
			return [$this->error(rule: $rule, field: $field, value: $amount, message: sprintf('%s.value must be above zero', $field))];
		}

		if (in_array($unit, SlaCalculatorInterface::UNITS, true) === false) {
			return [
				$this->error(
					rule: $rule,
					field: $field,
					value: $amount,
					message: sprintf('%s.unit must be one of [%s]', $field, implode(', ', SlaCalculatorInterface::UNITS))
				),
			];
		}

		return [];
	}//end amountErrors()

	/**
	 * A repeat declares a positive interval and a maximum of at least one.
	 *
	 * @param NotificationRuleContext $rule     The rule.
	 * @param array<string, mixed>    $reminder The reminder block.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function repeatErrors(NotificationRuleContext $rule, array $reminder): array {
		if (array_key_exists('repeat', $reminder) === false) {
			return [];
		}

		$repeat = $reminder['repeat'];
		if (is_array($repeat) === false) {
			return [$this->error(rule: $rule, field: 'reminder.repeat', value: $repeat, message: 'reminder.repeat must be an object with interval and max')];
		}

		$errors = $this->amountErrors(rule: $rule, field: 'reminder.repeat.interval', amount: ($repeat['interval'] ?? null), positive: true);
		$max    = ($repeat['max'] ?? null);
		if (is_int($max) === false || $max < 1) {
			$errors[] = $this->error(
				rule: $rule,
				field: 'reminder.repeat.max',
				value: $max,
				message: 'reminder.repeat needs a max of at least 1: a repeating reminder must be able to end'
			);
		}

		return $errors;
	}//end repeatErrors()

	/**
	 * A stop condition is a filter in the scheduled trigger's grammar.
	 *
	 * @param NotificationRuleContext $rule     The rule.
	 * @param array<string, mixed>    $reminder The reminder block.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stopErrors(NotificationRuleContext $rule, array $reminder): array {
		if (array_key_exists('stopWhen', $reminder) === false) {
			return [];
		}

		$stopWhen = $reminder['stopWhen'];
		if (is_array($stopWhen) === false || $stopWhen === []) {
			return [$this->error(rule: $rule, field: 'reminder.stopWhen', value: $stopWhen, message: 'reminder.stopWhen must be a non-empty filter')];
		}

		// The parser names the paths of a trigger filter; these are the stop
		// condition's, so the field and the message say so.
		$errors = [];
		foreach ($this->filterParser->parse(filter: $stopWhen, ruleKey: $rule->name)['errors'] as $error) {
			foreach ($error as $key => $part) {
				if (is_string($part) === true) {
					$error[$key] = str_replace('trigger.filter', 'reminder.stopWhen', $part);
				}
			}

			$errors[] = $error;
		}

		return $errors;
	}//end stopErrors()

	/**
	 * Whether a property declaration holds a date.
	 *
	 * @param mixed $property The property declaration.
	 *
	 * @return bool
	 */
	private static function isDate(mixed $property): bool {
		if (is_array($property) === false) {
			return false;
		}

		$format = $property['format'] ?? null;
		$type   = $property['type'] ?? null;

		return (is_string($format) === true && in_array($format, self::DATE_FORMATS, true) === true)
			|| (is_string($type) === true && in_array($type, self::DATE_TYPES, true) === true);
	}//end isDate()

	/**
	 * One error in the shape the annotation validator answers.
	 *
	 * @param NotificationRuleContext $rule    The rule.
	 * @param string                  $field   The field path.
	 * @param mixed                   $value   The refused value.
	 * @param string                  $message What is wrong, without the rule name.
	 *
	 * @return array<string, mixed>
	 */
	private function error(NotificationRuleContext $rule, string $field, mixed $value, string $message): array {
		return [
			'code' => 'notification-reminder-invalid',
			'ruleKey' => $rule->name,
			'field' => $field,
			'value' => $value,
			'message' => sprintf('Notification "%s": %s.', $rule->name, $message),
		];
	}//end error()
}//end class
