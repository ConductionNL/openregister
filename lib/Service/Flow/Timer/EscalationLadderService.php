<?php

/**
 * The escalation ladder: which rungs a timer has, when each falls on the
 * timeline, which are due and unfired, and whom each addresses.
 *
 * A ladder is DATA (design D-1): inline `escalation_rules` on the timer, else
 * the `escalation-ladder` object named by `ladder_slug`, else the ladder
 * configured for the subject's organisation, else none. Rules are validated
 * in COMMENSURABLE units by resolving both the offset and the SLA onto the
 * timer's own timeline and comparing INSTANTS —
 * `calendar.sub(fire_at, offset, offsetUnit) >= anchor_at` — never the two raw
 * integers (design D-6). A rule without an SLA is refused.
 *
 * This service decides WHEN a rung is due and WHO it names. It claims nothing
 * and sends nothing: {@see FlowTimerService} owns the fire ledger, and the
 * recipients it resolves are DESCRIPTORS (`user:`, `group:`, `role:`) for a
 * downstream subscriber, not uids looked up in Nextcloud.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Rung resolution, timeline validation and recipient descriptors.
 */
class EscalationLadderService {

	/**
	 * The seeded default ladder, 14/7/2/0.
	 *
	 * @var string
	 */
	public const DEFAULT_LADDER = 'nl-termijn-default';

	/**
	 * Triggers.
	 */
	public const TRIGGER_PRE_BREACH = 'preBreach';

	public const TRIGGER_BREACHED = 'slaBreached';

	/**
	 * The trigger vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const TRIGGERS = [self::TRIGGER_PRE_BREACH, self::TRIGGER_BREACHED];

	/**
	 * The rung priority scale.
	 *
	 * @var array<int, string>
	 */
	public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

	/**
	 * The role a ladder uses for the subject's own performer.
	 *
	 * @var string
	 */
	public const ROLE_HANDLER = 'handler';

	/**
	 * Constructor.
	 *
	 * @param FlowTimerDefinitionStore $definitions The seeded ladder definitions.
	 * @param SlaCalculator $calculator Timeline arithmetic.
	 */
	public function __construct(
		private readonly FlowTimerDefinitionStore $definitions,
		private readonly SlaCalculator $calculator,
	) {

	}//end __construct()

	/**
	 * The rungs a timer carries, normalised, plus the role bindings that apply.
	 *
	 * @param FlowTimer $timer The timer.
	 *
	 * @return array{rungs: array<int, array<string, mixed>>, roleBindings: array<string, string>} The ladder.
	 *
	 * @throws FlowTimerValidationException When `ladder_slug` names no ladder.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function resolveLadder(FlowTimer $timer): array {
		$sla = ['value' => (int)round((float)$timer->getBudgetValue()), 'unit' => (string)$timer->getBudgetUnit()];
		$rules = $timer->getEscalationRules();
		if (is_array($rules) === true && $rules !== []) {
			return ['rungs' => $this->normaliseRules(rules: $rules, sla: $sla), 'roleBindings' => []];
		}

		$slug = trim((string)$timer->getLadderSlug());
		if ($slug === '') {
			$slug = (string)$this->organisationLadderSlug(organisation: $timer->getOrganisation());
		}

		if ($slug === '') {
			return ['rungs' => [], 'roleBindings' => []];
		}

		$ladder = $this->ladderBySlug(slug: $slug);
		$bindings = ($ladder['roleBindings'] ?? []);
		if (is_array($bindings) === false) {
			$bindings = [];
		}

		return [
			'rungs' => $this->normaliseRules(rules: ($ladder['rungs'] ?? []), sla: $sla),
			'roleBindings' => $bindings,
		];
	}//end resolveLadder()

	/**
	 * Validate and normalise escalation rules.
	 *
	 * Shape `{trigger, offset, offsetUnit, notifyRole, escalateToRole,
	 * openIncident}` plus `key`, `priority` and `message`. `offsetUnit`
	 * accepts the SAME set the SLA does, `calendarDays` included. Refused
	 * without an SLA: a warning before a breach is meaningless without the
	 * term it warns about.
	 *
	 * @param mixed $rules The declared rules.
	 * @param array{value: int, unit: string}|null $sla The SLA on the same configuration.
	 *
	 * @return array<int, array<string, mixed>> The normalised rungs, in declared order.
	 *
	 * @throws FlowTimerValidationException On any refused shape or value.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-escalation-rule-is-validated-against-its-sla-in-commensurable-units
	 */
	public function normaliseRules(mixed $rules, ?array $sla): array {
		if (is_array($rules) === false) {
			throw new FlowTimerValidationException(message: 'Escalation rules must be an array of rules.');
		}

		if ($rules === []) {
			return [];
		}

		if ($sla === null) {
			throw new FlowTimerValidationException(
				message: 'An escalation rule is refused without an SLA: a warning before a breach is meaningless without the term it warns about.'
			);
		}

		$normalised = [];
		foreach ($rules as $index => $rule) {
			if (is_array($rule) === false) {
				throw new FlowTimerValidationException(message: sprintf('Escalation rule #%d is not an object.', (int)$index));
			}

			$normalised[] = $this->normaliseRule(rule: $rule, index: (int)$index);
		}

		return $normalised;
	}//end normaliseRules()

	/**
	 * The AUTHORITATIVE preBreach check, on the timeline: every preBreach rung
	 * must resolve at or after the anchor.
	 *
	 * @param array<int, array<string, mixed>> $rungs The normalised rungs.
	 * @param DateTimeInterface $anchorAt The instant the term runs from.
	 * @param DateTimeInterface $fireAt The instant the term ends.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When an offset exceeds the SLA, naming the anchor.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-escalation-rule-is-validated-against-its-sla-in-commensurable-units
	 */
	public function validateAgainstTimeline(array $rungs, DateTimeInterface $anchorAt, DateTimeInterface $fireAt, WorkingCalendar $calendar): void {
		foreach ($rungs as $rung) {
			if ($rung['trigger'] !== self::TRIGGER_PRE_BREACH) {
				continue;
			}

			$instant = $this->rungInstant(rung: $rung, fireAt: $fireAt, calendar: $calendar);
			if ($instant < $anchorAt) {
				throw new FlowTimerValidationException(
					message: sprintf(
						"Escalation rule '%s': the preBreach offset of %d %s exceeds the SLA — it resolves to %s, before the anchor %s (calendar '%s').",
						(string)$rung['key'],
						(int)$rung['offset'],
						(string)$rung['offsetUnit'],
						$instant->format('c'),
						$anchorAt->format('c'),
						$calendar->getSlug()
					)
				);
			}
		}
	}//end validateAgainstTimeline()

	/**
	 * The instant a rung falls on: `preBreach` is offset BEFORE the fire
	 * moment, `slaBreached` is offset AFTER it.
	 *
	 * @param array<string, mixed> $rung The normalised rung.
	 * @param DateTimeInterface $fireAt The fire moment.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return DateTimeImmutable The rung's instant.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function rungInstant(array $rung, DateTimeInterface $fireAt, WorkingCalendar $calendar): DateTimeImmutable {
		$offset = (float)$rung['offset'];
		$unit = (string)$rung['offsetUnit'];
		if ($rung['trigger'] === self::TRIGGER_PRE_BREACH) {
			return $this->calculator->sub(from: $fireAt, value: $offset, unit: $unit, calendar: $calendar);
		}

		return $this->calculator->add(from: $fireAt, value: $offset, unit: $unit, calendar: $calendar);
	}//end rungInstant()

	/**
	 * The instant of the earliest UNFIRED rung — the `next_rung_at` derivation.
	 *
	 * @param array<int, array<string, mixed>> $rungs The normalised rungs.
	 * @param DateTimeInterface $fireAt The fire moment.
	 * @param array<int, string> $firedKeys Rung keys already in the ledger.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return DateTimeImmutable|null The instant, or null when every rung has fired.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function nextRungAt(array $rungs, DateTimeInterface $fireAt, array $firedKeys, WorkingCalendar $calendar): ?DateTimeImmutable {
		$earliest = null;
		foreach ($this->ordered(rungs: $rungs, fireAt: $fireAt, calendar: $calendar) as $entry) {
			if (in_array((string)$entry['rung']['key'], $firedKeys, true) === true) {
				continue;
			}

			if ($earliest === null || $entry['at'] < $earliest) {
				$earliest = $entry['at'];
			}
		}

		return $earliest;
	}//end nextRungAt()

	/**
	 * The rungs whose instant has passed and which have no fire row, in
	 * LADDER ORDER (earliest instant first), each to fire once. A gap fires
	 * every passed rung; nothing collapses them into the most severe.
	 *
	 * @param array<int, array<string, mixed>> $rungs The normalised rungs.
	 * @param DateTimeInterface $fireAt The fire moment.
	 * @param DateTimeInterface $now The sweep instant.
	 * @param array<int, string> $firedKeys Rung keys already in the ledger.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return array<int, array{rung: array<string, mixed>, at: DateTimeImmutable}> The due rungs.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function dueRungs(array $rungs, DateTimeInterface $fireAt, DateTimeInterface $now, array $firedKeys, WorkingCalendar $calendar): array {
		$due = [];
		foreach ($this->ordered(rungs: $rungs, fireAt: $fireAt, calendar: $calendar) as $entry) {
			if (in_array((string)$entry['rung']['key'], $firedKeys, true) === true || $entry['at'] > $now) {
				continue;
			}

			$due[] = $entry;
		}

		return $due;
	}//end dueRungs()

	/**
	 * Resolve a rung's roles to recipient DESCRIPTORS against the subject.
	 *
	 * `handler` is the subject's own performer: the assignee typed by the
	 * task's performer type (a group performer yields `group:<gid>`), else the
	 * candidate groups, else the candidate users. Any role with a binding in
	 * the ladder's `roleBindings` resolves to that binding. Every other role
	 * travels unresolved as `role:<name>` for the downstream subscriber. No
	 * uid is looked up here.
	 *
	 * @param array<string, mixed> $rung The normalised rung.
	 * @param Task|null $subject The subject task, when the subject is a task.
	 * @param array<string, string> $roleBindings Role → `type:id` bindings from the ladder.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The recipients, deduplicated.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function resolveRecipients(array $rung, ?Task $subject, array $roleBindings): array {
		$roles = array_values(array_unique(array_merge($rung['notifyRole'], $rung['escalateToRole'])));
		$recipients = [];
		foreach ($roles as $role) {
			foreach ($this->recipientsForRole(role: (string)$role, subject: $subject, roleBindings: $roleBindings) as $recipient) {
				$recipients[$recipient['type'] . ':' . $recipient['id']] = $recipient;
			}
		}

		return array_values($recipients);
	}//end resolveRecipients()

	/**
	 * A ladder definition by slug.
	 *
	 * @param string $slug The ladder name.
	 *
	 * @return array<string, mixed> The definition.
	 *
	 * @throws FlowTimerValidationException When no ladder carries that name.
	 */
	private function ladderBySlug(string $slug): array {
		$ladders = $this->definitions->ladders();
		if (array_key_exists($slug, $ladders) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Escalation ladder '%s' does not exist; known ladders: %s.", $slug, implode(', ', array_keys($ladders)))
			);
		}

		return $ladders[$slug];
	}//end ladderBySlug()

	/**
	 * The ladder configured as an organisation's default, when one is.
	 *
	 * @param string|null $organisation The organisation uuid.
	 *
	 * @return string|null The slug.
	 */
	private function organisationLadderSlug(?string $organisation): ?string {
		$organisation = trim((string)$organisation);
		if ($organisation === '') {
			return null;
		}

		foreach ($this->definitions->ladders() as $slug => $definition) {
			if (trim((string)($definition['organisation'] ?? '')) === $organisation) {
				return (string)$slug;
			}
		}

		return null;
	}//end organisationLadderSlug()

	/**
	 * Rungs paired with their instants, earliest first (declared order breaks ties).
	 *
	 * @param array<int, array<string, mixed>> $rungs The normalised rungs.
	 * @param DateTimeInterface $fireAt The fire moment.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return array<int, array{rung: array<string, mixed>, at: DateTimeImmutable}> The ordered rungs.
	 */
	private function ordered(array $rungs, DateTimeInterface $fireAt, WorkingCalendar $calendar): array {
		$entries = [];
		foreach (array_values($rungs) as $index => $rung) {
			$entries[] = ['rung' => $rung, 'at' => $this->rungInstant(rung: $rung, fireAt: $fireAt, calendar: $calendar), 'index' => $index];
		}

		usort(
			$entries,
			static function (array $left, array $right): int {
				if ($left['at'] == $right['at']) {
					return ($left['index'] <=> $right['index']);
				}

				return ($left['at'] <=> $right['at']);
			}
		);

		return array_map(
			static fn (array $entry): array => ['rung' => $entry['rung'], 'at' => $entry['at']],
			$entries
		);
	}//end ordered()

	/**
	 * Validate and normalise one rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param int $index Its position, for messages.
	 *
	 * @return array<string, mixed> The normalised rung.
	 *
	 * @throws FlowTimerValidationException On a refused trigger, offset, unit, priority or role list.
	 */
	private function normaliseRule(array $rule, int $index): array {
		$trigger = (string)($rule['trigger'] ?? '');
		if (in_array($trigger, self::TRIGGERS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Escalation rule #%d has trigger '%s'; use one of %s.", $index, $trigger, implode(', ', self::TRIGGERS))
			);
		}

		$offset = ($rule['offset'] ?? null);
		if (is_int($offset) === false || $offset < 0) {
			throw new FlowTimerValidationException(
				message: sprintf("Escalation rule #%d has offset '%s'; it must be an integer >= 0.", $index, var_export($offset, true))
			);
		}

		$unit = $this->calculator->validateUnit(unit: ($rule['offsetUnit'] ?? null));

		$priority = (string)($rule['priority'] ?? 'medium');
		if (in_array($priority, self::PRIORITIES, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Escalation rule #%d has priority '%s'; use one of %s.", $index, $priority, implode(', ', self::PRIORITIES))
			);
		}

		$key = trim((string)($rule['key'] ?? ''));
		if ($key === '') {
			$key = sprintf('%s:%d:%s', $trigger, $offset, $unit);
		}

		return [
			'key' => $key,
			'trigger' => $trigger,
			'offset' => $offset,
			'offsetUnit' => $unit,
			'notifyRole' => $this->roleList(value: ($rule['notifyRole'] ?? []), field: 'notifyRole', index: $index),
			'escalateToRole' => $this->roleList(value: ($rule['escalateToRole'] ?? []), field: 'escalateToRole', index: $index),
			'priority' => $priority,
			'message' => $this->stringOrNull(value: ($rule['message'] ?? null)),
			'openIncident' => (($rule['openIncident'] ?? false) === true),
		];
	}//end normaliseRule()

	/**
	 * A role list: a string or a list of strings.
	 *
	 * @param mixed $value The declared roles.
	 * @param string $field The field name, for messages.
	 * @param int $index The rule position, for messages.
	 *
	 * @return array<int, string> The roles.
	 *
	 * @throws FlowTimerValidationException On a non-string entry.
	 */
	private function roleList(mixed $value, string $field, int $index): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			throw new FlowTimerValidationException(
				message: sprintf('Escalation rule #%d: %s must be a role name or a list of role names.', $index, $field)
			);
		}

		$roles = [];
		foreach ($value as $role) {
			if (is_string($role) === false || trim($role) === '') {
				throw new FlowTimerValidationException(
					message: sprintf('Escalation rule #%d: %s contains an empty or non-string role.', $index, $field)
				);
			}

			$roles[] = trim($role);
		}

		return array_values(array_unique($roles));
	}//end roleList()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()

	/**
	 * The recipients one role resolves to.
	 *
	 * @param string $role The role.
	 * @param Task|null $subject The subject task.
	 * @param array<string, string> $roleBindings The ladder's bindings.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The recipients.
	 */
	private function recipientsForRole(string $role, ?Task $subject, array $roleBindings): array {
		$binding = ($roleBindings[$role] ?? null);
		if (is_string($binding) === true && str_contains($binding, ':') === true) {
			[$type, $id] = explode(':', $binding, 2);

			return [['type' => trim($type), 'id' => trim($id), 'role' => $role]];
		}

		if ($role === self::ROLE_HANDLER && $subject !== null) {
			$performer = $this->performerRecipients(subject: $subject, role: $role);
			if ($performer !== []) {
				return $performer;
			}
		}

		return [['type' => 'role', 'id' => $role, 'role' => $role]];
	}//end recipientsForRole()

	/**
	 * The subject task's own performer as recipients: assignee, else candidate groups, else candidate users.
	 *
	 * @param Task $subject The task.
	 * @param string $role The role being resolved.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The recipients; empty when the task names nobody.
	 */
	private function performerRecipients(Task $subject, string $role): array {
		$assignee = trim((string)$subject->getAssignee());
		if ($assignee !== '') {
			$type = 'user';
			if ((string)$subject->getPerformerType() !== Task::PERFORMER_USER) {
				$type = (string)$subject->getPerformerType();
			}

			return [['type' => $type, 'id' => $assignee, 'role' => $role]];
		}

		$recipients = [];
		foreach (($subject->getCandidateGroups() ?? []) as $gid) {
			$recipients[] = ['type' => 'group', 'id' => (string)$gid, 'role' => $role];
		}

		if ($recipients !== []) {
			return $recipients;
		}

		foreach (($subject->getCandidateUsers() ?? []) as $uid) {
			$recipients[] = ['type' => 'user', 'id' => (string)$uid, 'role' => $role];
		}

		return $recipients;
	}//end performerRecipients()
}//end class
