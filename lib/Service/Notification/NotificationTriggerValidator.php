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

namespace OCA\OpenRegister\Service\Notification;

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
		if (is_array($trigger) === false) {
			$appEvent = null;
			if (is_string($trigger) === true) {
				$appEvent = $trigger;
			}

			return $this->validateTriggerKind(name: $name, triggerType: '', appEvent: $appEvent);
		}

		$triggerType = (string)($trigger['type'] ?? '');
		$errors      = $this->validateTriggerKind(name: $name, triggerType: $triggerType, appEvent: ($trigger['event'] ?? null));
		$typeErrors  = match ($triggerType) {
			'scheduled' => $this->validateScheduledTrigger(name: $name, trigger: $trigger),
			'threshold' => $this->validateThresholdTrigger(name: $name, trigger: $trigger),
			'calculatedChange' => $this->validateCalculatedChangeTrigger(name: $name, trigger: $trigger),
			default => [],
		};

		return array_merge($errors, $typeErrors);
	}//end validate()

	/**
	 * Errors for the trigger kind: a well-formed app event or a known type.
	 *
	 * @param string $name        The rule key.
	 * @param string $triggerType The declared `trigger.type`, empty when absent.
	 * @param mixed  $appEvent    The declared app event, null when absent.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateTriggerKind(string $name, string $triggerType, mixed $appEvent): array {
		if ($appEvent !== null) {
			return $this->validateAppEvent(ruleKey: $name, event: $appEvent);
		}

		if (in_array($triggerType, self::VALID_TRIGGERS, true) === true) {
			return [];
		}

		return [
			[
				'code' => 'notification-bad-trigger',
				'message' => sprintf(
					'Notification "%s" trigger.type must be one of [%s], or the trigger must name an '
					. 'app-defined event as a namespaced string (e.g. "booking.confirmed").',
					$name,
					implode(', ', self::VALID_TRIGGERS)
				),
			],
		];
	}//end validateTriggerKind()

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
	private function validateAppEvent(string $ruleKey, $event): array {
		if (is_string($event) === false || $event === '') {
			return [
				[
					'code' => 'notification-bad-app-event',
					'ruleKey' => $ruleKey,
					'field' => 'trigger',
					'value' => $event,
					'message' => sprintf(
						'Notification "%s" declares an app event that is not a non-empty string.',
						$ruleKey
					),
				],
			];
		}

		if (in_array($event, self::VALID_TRIGGERS, true) === true) {
			return [
				[
					'code' => 'notification-app-event-reserved',
					'ruleKey' => $ruleKey,
					'field' => 'trigger',
					'value' => $event,
					'message' => sprintf(
						'Notification "%s" names "%s" as an app event, but that is a reserved trigger type; '
						. 'use the object form {"type": "%s"} instead.',
						$ruleKey,
						$event,
						$event
					),
				],
			];
		}

		if (preg_match('/^[a-z][A-Za-z0-9-]*(\.[A-Za-z0-9-]+)+$/', $event) !== 1) {
			return [
				[
					'code' => 'notification-bad-app-event',
					'ruleKey' => $ruleKey,
					'field' => 'trigger',
					'value' => $event,
					'message' => sprintf(
						'Notification "%s" app event "%s" must be namespaced with a dot and start lowercase '
						. '(e.g. "booking.confirmed", "lifecycle.optIn"), so it can never collide with a '
						. 'reserved trigger type.',
						$ruleKey,
						$event
					),
				],
			];
		}

		return [];

	}//end validateAppEvent()

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
			$errors[] = [
				'code' => 'notification-scheduled-bad-interval',
				'message' => sprintf(
					'Notification "%s" trigger.type=scheduled requires trigger.intervalSec (integer >= 60).',
					$name
				),
			];
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
		$error = [
			'code' => 'notification-scheduled-bad-dedupe-fields',
			'ruleKey' => $name,
			'field' => 'trigger.dedupeFields',
			'value' => $dedupeFields,
			'message' => sprintf('Notification "%s" trigger.dedupeFields must be a non-empty array of strings.', $name),
		];
		if (is_array($dedupeFields) === false || $dedupeFields === []) {
			return [$error];
		}

		foreach ($dedupeFields as $idx => $field) {
			if (is_string($field) === false || $field === '') {
				$error['field']   = sprintf('trigger.dedupeFields[%d]', (int)$idx);
				$error['value']   = $field;
				$error['message'] = sprintf('Notification "%s" trigger.dedupeFields entries must be non-empty strings.', $name);
				return [$error];
			}
		}

		return [];
	}//end validateDedupeFields()

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
			$errors[] = [
				'code' => 'notification-threshold-no-aggregation',
				'message' => sprintf('Notification "%s" trigger.type=threshold requires trigger.aggregation referencing a declared aggregation.', $name),
			];
		}

		$op = (string)($trigger['op'] ?? '');
		if (in_array($op, self::THRESHOLD_OPS, true) === false) {
			$errors[] = [
				'code' => 'notification-threshold-bad-op',
				'message' => sprintf('Notification "%s" trigger.type=threshold trigger.op must be one of [gt, gte, lt, lte, eq, ne]; got "%s".', $name, $op),
			];
		}

		if (array_key_exists('value', $trigger) === false) {
			$errors[] = [
				'code' => 'notification-threshold-no-value',
				'message' => sprintf('Notification "%s" trigger.type=threshold requires trigger.value.', $name),
			];
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
		$field  = ($trigger['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			$errors[] = [
				'code' => 'notification-calculated-change-no-field',
				'message' => sprintf('Notification "%s" trigger.type=calculatedChange requires trigger.field (non-empty string).', $name),
			];
		}

		foreach (['condition', 'previously'] as $clauseKey) {
			$clause = ($trigger[$clauseKey] ?? null);
			$errors = array_merge($errors, $this->validateCalculatedChangeClause(name: $name, clauseKey: $clauseKey, clause: $clause));
		}

		return $errors;
	}//end validateCalculatedChangeTrigger()

	/**
	 * Errors for one optional `calculatedChange` operator clause.
	 *
	 * @param string $name      The rule key.
	 * @param string $clauseKey `condition` or `previously`.
	 * @param mixed  $clause    The declared clause, null when absent.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateCalculatedChangeClause(string $name, string $clauseKey, mixed $clause): array {
		if ($clause === null) {
			return [];
		}

		if (is_array($clause) === false || count($clause) === 0) {
			return [
				[
					'code' => 'notification-calculated-change-bad-clause',
					'message' => sprintf('Notification "%s" trigger.%s must be a non-empty operator map.', $name, $clauseKey),
				],
			];
		}

		$errors = [];
		foreach (array_keys($clause) as $op) {
			if (in_array((string)$op, self::CALCULATED_CHANGE_OPS, true) === false) {
				$errors[] = [
					'code' => 'notification-calculated-change-bad-op',
					'message' => sprintf(
						'Notification "%s" trigger.%s operator "%s" must be one of [%s].',
						$name,
						$clauseKey,
						(string)$op,
						implode(', ', self::CALCULATED_CHANGE_OPS)
					),
				];
			}
		}

		return $errors;
	}//end validateCalculatedChangeClause()
}//end class
