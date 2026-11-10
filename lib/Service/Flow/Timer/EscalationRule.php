<?php

/**
 * One declared escalation rule, read and VALIDATED into a normalised rung.
 *
 * Shape `{trigger, offset, offsetUnit, notifyRole, escalateToRole,
 * openIncident}` plus `key`, `priority`, `message` and `consequence`. Each
 * malformed field is refused with a message naming the rule's position.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-escalation-rule-is-validated-against-its-sla-in-commensurable-units
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Validates one escalation rule, in a fixed order: trigger, offset, unit,
 * priority, notify roles, escalate roles.
 */
final class EscalationRule {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $rule  The declared rule.
	 * @param int                  $index Its position, for messages.
	 */
	public function __construct(
		private readonly array $rule,
		private readonly int $index,
	) {

	}//end __construct()

	/**
	 * The normalised rung.
	 *
	 * @return array<string, mixed> The rung.
	 *
	 * @throws FlowTimerValidationException On a refused trigger, offset, unit, priority or role list.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-escalation-rule-is-validated-against-its-sla-in-commensurable-units
	 */
	public function toRung(): array {
		$timing = $this->timing();
		$priority = $this->priority();

		return $timing + $this->roles() + ['priority' => $priority] + $this->texts();
	}//end toRung()

	/**
	 * Key, trigger, offset and unit.
	 *
	 * @return array{key: string, trigger: string, offset: int, offsetUnit: string} The timing part.
	 *
	 * @throws FlowTimerValidationException On a refused trigger, offset or unit.
	 */
	private function timing(): array {
		$trigger = $this->trigger();
		$offset = $this->offset();
		$unit = $this->unit();
		$key = trim((string)($this->rule['key'] ?? ''));
		if ($key === '') {
			$key = sprintf('%s:%d:%s', $trigger, $offset, $unit);
		}

		return ['key' => $key, 'trigger' => $trigger, 'offset' => $offset, 'offsetUnit' => $unit];
	}//end timing()

	/**
	 * The trigger.
	 *
	 * @return string One of the trigger vocabulary.
	 *
	 * @throws FlowTimerValidationException On an unknown trigger.
	 */
	private function trigger(): string {
		$trigger = (string)($this->rule['trigger'] ?? '');
		if (in_array($trigger, EscalationLadderService::TRIGGERS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Escalation rule #%d has trigger '%s'; use one of %s.", $this->index, $trigger, implode(', ', EscalationLadderService::TRIGGERS))
			);
		}

		return $trigger;
	}//end trigger()

	/**
	 * The offset.
	 *
	 * @return integer An integer >= 0.
	 *
	 * @throws FlowTimerValidationException When it is not an integer >= 0.
	 */
	private function offset(): int {
		$offset = ($this->rule['offset'] ?? null);
		if (is_int($offset) === false || $offset < 0) {
			throw new FlowTimerValidationException(
				message: sprintf("Escalation rule #%d has offset '%s'; it must be an integer >= 0.", $this->index, var_export($offset, true))
			);
		}

		return $offset;
	}//end offset()

	/**
	 * The offset unit: the same set the SLA accepts.
	 *
	 * @return string The unit.
	 *
	 * @throws FlowTimerValidationException On an unknown unit.
	 */
	private function unit(): string {
		$unit = ($this->rule['offsetUnit'] ?? null);
		if (is_string($unit) === false || in_array($unit, SlaCalculatorInterface::UNITS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"Escalation rule #%d has offsetUnit '%s'; use one of %s (the same units the SLA accepts).",
					$this->index,
					var_export($unit, true),
					implode(', ', SlaCalculatorInterface::UNITS)
				)
			);
		}

		return $unit;
	}//end unit()

	/**
	 * The priority, `medium` when absent.
	 *
	 * @return string One of the priority scale.
	 *
	 * @throws FlowTimerValidationException On an unknown priority.
	 */
	private function priority(): string {
		$priority = (string)($this->rule['priority'] ?? 'medium');
		if (in_array($priority, EscalationLadderService::PRIORITIES, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"Escalation rule #%d has priority '%s'; use one of %s.",
					$this->index,
					$priority,
					implode(', ', EscalationLadderService::PRIORITIES)
				)
			);
		}

		return $priority;
	}//end priority()

	/**
	 * The notify and escalate role lists.
	 *
	 * @return array{notifyRole: array<int, string>, escalateToRole: array<int, string>} The roles.
	 *
	 * @throws FlowTimerValidationException On a malformed list.
	 */
	private function roles(): array {
		return [
			'notifyRole' => $this->roleList(field: 'notifyRole'),
			'escalateToRole' => $this->roleList(field: 'escalateToRole'),
		];
	}//end roles()

	/**
	 * A role list: a string or a list of strings.
	 *
	 * @param string $field The field name.
	 *
	 * @return array<int, string> The roles, trimmed and deduplicated.
	 *
	 * @throws FlowTimerValidationException On a non-list or a non-string entry.
	 */
	private function roleList(string $field): array {
		$value = ($this->rule[$field] ?? []);
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			throw new FlowTimerValidationException(
				message: sprintf('Escalation rule #%d: %s must be a role name or a list of role names.', $this->index, $field)
			);
		}

		$roles = array_map(fn (mixed $role): string => $this->role(role: $role, field: $field), $value);

		return array_values(array_unique($roles));
	}//end roleList()

	/**
	 * One role name.
	 *
	 * @param mixed  $role  The declared role.
	 * @param string $field The field name, for the message.
	 *
	 * @return string The trimmed role.
	 *
	 * @throws FlowTimerValidationException On an empty or non-string role.
	 */
	private function role(mixed $role, string $field): string {
		if (is_string($role) === false || trim($role) === '') {
			throw new FlowTimerValidationException(
				message: sprintf('Escalation rule #%d: %s contains an empty or non-string role.', $this->index, $field)
			);
		}

		return trim($role);
	}//end role()

	/**
	 * Message, consequence and the incident flag.
	 *
	 * @return array{message: string|null, consequence: string|null, openIncident: bool} The texts.
	 */
	private function texts(): array {
		return [
			'message' => self::stringOrNull(value: ($this->rule['message'] ?? null)),
			'consequence' => self::stringOrNull(value: ($this->rule['consequence'] ?? null)),
			'openIncident' => (($this->rule['openIncident'] ?? false) === true),
		];
	}//end texts()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The trimmed string.
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()
}//end class
