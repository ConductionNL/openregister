<?php

/**
 * Schema-save validation of a notification rule's `trigger`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Validates the `trigger` of one `x-openregister-notifications` rule.
 */
final class NotificationTriggerValidator {

	/**
	 * The reserved trigger types.
	 *
	 * @var array<int, string>
	 */
	private const VALID_TRIGGERS = ['created', 'updated', 'transition', 'scheduled', 'threshold', 'calculatedChange'];

	/**
	 * Comparison operators a `threshold` trigger may use.
	 *
	 * @var array<int, string>
	 */
	private const THRESHOLD_OPS = ['gt', 'gte', 'lt', 'lte', 'eq', 'ne'];

	/**
	 * Comparison operators a `calculatedChange` clause may use.
	 *
	 * @var array<int, string>
	 */
	private const CALCULATED_CHANGE_OPS = ['lt', 'lte', 'gt', 'gte', 'eq', 'ne'];

	/**
	 * The app-event name shape: lowercase start, namespaced with at least one dot.
	 */
	private const APP_EVENT_PATTERN = '/^[a-z][A-Za-z0-9-]*(\.[A-Za-z0-9-]+)+$/';

	/**
	 * Diagnostics reported as code and message: fault => [error code, sprintf format].
	 */
	private const FAULTS = [
		'bad-trigger'           => [
			'notification-bad-trigger',
			'Notification "%s" trigger.type must be one of [%s], or the trigger must name an '
			.'app-defined event as a namespaced string (e.g. "booking.confirmed").',
		],
		'bad-interval'          => [
			'notification-scheduled-bad-interval',
			'Notification "%s" trigger.type=scheduled requires trigger.intervalSec (integer >= 60).',
		],
		'threshold-aggregation' => [
			'notification-threshold-no-aggregation',
			'Notification "%s" trigger.type=threshold requires trigger.aggregation referencing a declared aggregation.',
		],
		'threshold-op'          => [
			'notification-threshold-bad-op',
			'Notification "%s" trigger.type=threshold trigger.op must be one of [gt, gte, lt, lte, eq, ne]; got "%s".',
		],
		'threshold-value'       => ['notification-threshold-no-value', 'Notification "%s" trigger.type=threshold requires trigger.value.'],
		'calculated-field'      => [
			'notification-calculated-change-no-field',
			'Notification "%s" trigger.type=calculatedChange requires trigger.field (non-empty string).',
		],
		'calculated-clause'     => ['notification-calculated-change-bad-clause', '%s must be a non-empty operator map.'],
		'calculated-op'         => ['notification-calculated-change-bad-op', '%s operator "%s" must be one of [%s].'],
	];

	/**
	 * App-event diagnostics, formatted with the rule key and the event (twice).
	 */
	private const APP_EVENT_FAULTS = [
		'not-a-string' => [
			'notification-bad-app-event',
			'Notification "%s" declares an app event that is not a non-empty string.',
		],
		'reserved'     => [
			'notification-app-event-reserved',
			'Notification "%s" names "%s" as an app event, but that is a reserved trigger type; '
			.'use the object form {"type": "%s"} instead.',
		],
		'shape'        => [
			'notification-bad-app-event',
			'Notification "%s" app event "%s" must be namespaced with a dot and start lowercase '
			.'(e.g. "booking.confirmed", "lifecycle.optIn"), so it can never collide with a '
			.'reserved trigger type.',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ScheduledFilterParser $filterParser Parser for scheduled filters; the sole authority on what one may say.
	 */
	public function __construct(
		private readonly ScheduledFilterParser $filterParser,
	) {
	}//end __construct()

	/**
	 * Errors for a rule's `trigger`: its kind, then the checks of its type.
	 *
	 * A bare string, or an object with `event`, names an app-defined event;
	 * otherwise `type` must be a reserved trigger type.
	 *
	 * @param string $name    The rule key.
	 * @param mixed  $trigger The declared trigger.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function validate(string $name, mixed $trigger): array {
		$declared = new DeclaredTrigger(trigger: $trigger);

		return array_merge(
			$this->validateTriggerKind(name: $name, trigger: $declared),
			$this->validateTriggerType(name: $name, trigger: $declared)
		);
	}//end validate()

	/**
	 * One code-and-message error.
	 *
	 * @param string            $fault A key of self::FAULTS.
	 * @param array<int, mixed> $args  The values its format interpolates.
	 *
	 * @return array{code: string, message: string}
	 */
	private function fault(string $fault, array $args): array {
		[$code, $format] = self::FAULTS[$fault];

		return ['code' => $code, 'message' => sprintf($format, ...$args)];
	}//end fault()

	/**
	 * Errors for the trigger kind: a well-formed app event or a known type.
	 *
	 * @param string          $name    The rule key.
	 * @param DeclaredTrigger $trigger The declared trigger.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateTriggerKind(string $name, DeclaredTrigger $trigger): array {
		if ($trigger->appEvent !== null) {
			return $this->validateAppEvent(ruleKey: $name, event: $trigger->appEvent);
		}

		if (in_array($trigger->type, self::VALID_TRIGGERS, true) === true) {
			return [];
		}

		return [$this->fault(fault: 'bad-trigger', args: [$name, implode(', ', self::VALID_TRIGGERS)])];
	}//end validateTriggerKind()

	/**
	 * Errors for the checks of an object trigger's type; a bare trigger has none.
	 *
	 * @param string          $name    The rule key.
	 * @param DeclaredTrigger $trigger The declared trigger.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateTriggerType(string $name, DeclaredTrigger $trigger): array {
		if ($trigger->spec === null) {
			return [];
		}

		return match ($trigger->type) {
			'scheduled' => $this->validateScheduledTrigger(name: $name, trigger: $trigger->spec),
			'threshold' => $this->validateThresholdTrigger(name: $name, trigger: $trigger->spec),
			'calculatedChange' => $this->validateCalculatedChangeTrigger(name: $name, trigger: $trigger->spec),
			default => [],
		};
	}//end validateTriggerType()

	/**
	 * Validate an app-defined trigger event name.
	 *
	 * An app event is namespaced with a dot — `booking.confirmed`,
	 * `generation.draft`, `lifecycle.optIn`. The dot is the whole safeguard, and
	 * the only part of the name this rule polices: it is required so an app event
	 * can never
	 * collide with a reserved trigger type, present or future: `created` is
	 * always the engine's, `booking.created` is always the app's. Without that
	 * rule, adding a trigger type later would silently capture somebody's
	 * event.
	 *
	 * @param string $ruleKey The notification rule key, for diagnostics.
	 * @param mixed  $event   The declared event name.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the name is well-formed.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateAppEvent(string $ruleKey, mixed $event): array {
		$error = $this->appEventError(ruleKey: $ruleKey, event: $event);
		if ($error === null) {
			return [];
		}

		return [$error];
	}//end validateAppEvent()

	/**
	 * The error for an app event name, null when it is well-formed.
	 *
	 * @param string $ruleKey The notification rule key, for diagnostics.
	 * @param mixed  $event   The declared event name.
	 *
	 * @return array<string, mixed>|null
	 */
	private function appEventError(string $ruleKey, mixed $event): ?array {
		$fault = $this->appEventFault(event: $event);
		if ($fault === null) {
			return null;
		}

		[$code, $format] = self::APP_EVENT_FAULTS[$fault];

		return [
			'code' => $code,
			'ruleKey' => $ruleKey,
			'field' => 'trigger',
			'value' => $event,
			'message' => sprintf($format, $ruleKey, $event, $event),
		];
	}//end appEventError()

	/**
	 * What is wrong with an app event name, as a key of self::APP_EVENT_FAULTS; null when nothing.
	 *
	 * @param mixed $event The declared event name.
	 *
	 * @return string|null
	 */
	private function appEventFault(mixed $event): ?string {
		if (is_string($event) === false || $event === '') {
			return 'not-a-string';
		}

		if (in_array($event, self::VALID_TRIGGERS, true) === true) {
			return 'reserved';
		}

		if (preg_match(self::APP_EVENT_PATTERN, $event) !== 1) {
			return 'shape';
		}

		return null;
	}//end appEventFault()

	/**
	 * Errors for a `scheduled` trigger: interval, filter and dedupe fields.
	 *
	 * @param string               $name    The rule key.
	 * @param array<string, mixed> $trigger The declared trigger.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateScheduledTrigger(string $name, array $trigger): array {
		$errors      = [];
		$intervalSec = ($trigger['intervalSec'] ?? null);
		if (is_int($intervalSec) === false || $intervalSec < 60) {
			$errors[] = $this->fault(fault: 'bad-interval', args: [$name]);
		}

		if (isset($trigger['filter']) === true) {
			$errors = array_merge($errors, $this->validateScheduledFilter(name: $name, filter: $trigger['filter']));
		}

		if (isset($trigger['dedupeFields']) === true) {
			$errors = array_merge($errors, $this->validateDedupeFields(name: $name, dedupeFields: $trigger['dedupeFields']));
		}

		return $errors;
	}//end validateScheduledTrigger()

	/**
	 * Errors for a scheduled `trigger.filter`.
	 *
	 * The filter is parsed with the same parser the evaluator runs, once for
	 * the whole map, so the shapes accepted here are exactly the shapes the
	 * evaluator executes. This class enumerates no operators of its own.
	 *
	 * @param string $name   The rule key.
	 * @param mixed  $filter The declared filter.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateScheduledFilter(string $name, mixed $filter): array {
		if (is_array($filter) === true) {
			return array_values($this->filterParser->parse(filter: $filter, ruleKey: $name)['errors']);
		}

		return [
			[
				'code' => 'notification-scheduled-bad-filter',
				'ruleKey' => $name,
				'field' => 'trigger.filter',
				'value' => $filter,
				'message' => sprintf('Notification "%s" trigger.filter must be an object/map.', $name),
			],
		];
	}//end validateScheduledFilter()

	/**
	 * Errors for a scheduled `trigger.dedupeFields`; reports the first bad entry only.
	 *
	 * @param string $name         The rule key.
	 * @param mixed  $dedupeFields The declared dedupe fields.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateDedupeFields(string $name, mixed $dedupeFields): array {
		if (is_array($dedupeFields) === false || $dedupeFields === []) {
			return [$this->dedupeFault(name: $name, detail: ['trigger.dedupeFields', $dedupeFields, 'must be a non-empty array of strings'])];
		}

		foreach ($dedupeFields as $idx => $field) {
			if ($this->isBlankString(value: $field) === true) {
				$where = sprintf('trigger.dedupeFields[%d]', (int)$idx);
				return [$this->dedupeFault(name: $name, detail: [$where, $field, 'entries must be non-empty strings'])];
			}
		}

		return [];
	}//end validateDedupeFields()

	/**
	 * A `notification-scheduled-bad-dedupe-fields` error.
	 *
	 * @param string                       $name   The rule key.
	 * @param array{string, mixed, string} $detail The offending field path, its value, and what is wrong.
	 *
	 * @return array<string, mixed>
	 */
	private function dedupeFault(string $name, array $detail): array {
		[$field, $value, $problem] = $detail;

		return [
			'code' => 'notification-scheduled-bad-dedupe-fields',
			'ruleKey' => $name,
			'field' => $field,
			'value' => $value,
			'message' => sprintf('Notification "%s" trigger.dedupeFields %s.', $name, $problem),
		];
	}//end dedupeFault()

	/**
	 * Whether a value is not a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isBlankString(mixed $value): bool {
		return is_string($value) === false || $value === '';
	}//end isBlankString()

	/**
	 * Errors for a `threshold` trigger: aggregation, operator and value.
	 *
	 * @param string               $name    The rule key.
	 * @param array<string, mixed> $trigger The declared trigger.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateThresholdTrigger(string $name, array $trigger): array {
		$errors = [];
		if ((string)($trigger['aggregation'] ?? '') === '') {
			$errors[] = $this->fault(fault: 'threshold-aggregation', args: [$name]);
		}

		$op = (string)($trigger['op'] ?? '');
		if (in_array($op, self::THRESHOLD_OPS, true) === false) {
			$errors[] = $this->fault(fault: 'threshold-op', args: [$name, $op]);
		}

		if (array_key_exists('value', $trigger) === false) {
			$errors[] = $this->fault(fault: 'threshold-value', args: [$name]);
		}

		return $errors;
	}//end validateThresholdTrigger()

	/**
	 * Errors for a `calculatedChange` trigger: field and both operator clauses.
	 *
	 * @param string               $name    The rule key.
	 * @param array<string, mixed> $trigger The declared trigger.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateCalculatedChangeTrigger(string $name, array $trigger): array {
		$errors = [];
		if ($this->isBlankString(value: ($trigger['field'] ?? null)) === true) {
			$errors[] = $this->fault(fault: 'calculated-field', args: [$name]);
		}

		foreach (['condition', 'previously'] as $clauseKey) {
			$where  = sprintf('Notification "%s" trigger.%s', $name, $clauseKey);
			$errors = array_merge($errors, $this->validateCalculatedChangeClause(where: $where, clause: ($trigger[$clauseKey] ?? null)));
		}

		return $errors;
	}//end validateCalculatedChangeTrigger()

	/**
	 * Errors for one optional `calculatedChange` operator clause.
	 *
	 * @param string $where  `Notification "<name>" trigger.<clause>`, the diagnostics prefix.
	 * @param mixed  $clause The declared clause, null when absent.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateCalculatedChangeClause(string $where, mixed $clause): array {
		if ($clause === null) {
			return [];
		}

		if (is_array($clause) === false || count($clause) === 0) {
			return [$this->fault(fault: 'calculated-clause', args: [$where])];
		}

		return $this->clauseOperatorErrors(where: $where, clause: $clause);
	}//end validateCalculatedChangeClause()

	/**
	 * Errors for the operators of a non-empty `calculatedChange` clause that are not known.
	 *
	 * @param string              $where  `Notification "<name>" trigger.<clause>`, the diagnostics prefix.
	 * @param array<mixed, mixed> $clause The declared clause.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function clauseOperatorErrors(string $where, array $clause): array {
		$errors = [];
		foreach (array_keys($clause) as $op) {
			if (in_array((string)$op, self::CALCULATED_CHANGE_OPS, true) === false) {
				$errors[] = $this->fault(fault: 'calculated-op', args: [$where, (string)$op, implode(', ', self::CALCULATED_CHANGE_OPS)]);
			}
		}

		return $errors;
	}//end clauseOperatorErrors()
}//end class
