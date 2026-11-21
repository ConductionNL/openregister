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
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Rung resolution, timeline validation and recipient descriptors.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of the rule
 * validator's refusal branches (trigger, offset, unit, priority, role lists,
 * each named in its own message) and the three recipient resolution paths.
 * Each method is small; the class total is the vocabulary it guards.
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
	 * A rung after the deadline addressed to the party: an overdue notice, not
	 * an inward escalation (#4166). It falls at the same instant a slaBreached
	 * rung with the same offset would.
	 */
	public const TRIGGER_POST_BREACH = 'postBreach';

	/**
	 * The trigger vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const TRIGGERS = [self::TRIGGER_PRE_BREACH, self::TRIGGER_BREACHED, self::TRIGGER_POST_BREACH];

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
	 * @param SlaCalculatorInterface $calculator Timeline arithmetic.
	 */
	public function __construct(
		private readonly FlowTimerDefinitionStore $definitions,
		private readonly SlaCalculatorInterface $calculator,
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
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function resolveLadder(FlowTimer $timer): array {
		$sla = ['value' => (int)round((float)$timer->getBudgetValue()), 'unit' => (string)$timer->getBudgetUnit()];
		$rules = $timer->getEscalationRules();
		if (is_array($rules) === true && $rules !== []) {
			return ['rungs' => $this->normaliseRules(rules: $rules, sla: $sla), 'roleBindings' => []];
		}

		$slug = $this->ladderSlugFor(timer: $timer);
		if ($slug === '') {
			return ['rungs' => [], 'roleBindings' => []];
		}

		$ladder = $this->ladderBySlug(slug: $slug);

		return [
			'rungs' => $this->normaliseRules(rules: ($ladder['rungs'] ?? []), sla: $sla),
			'roleBindings' => self::bindingsOf(ladder: $ladder),
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
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-an-escalation-rule-is-validated-against-its-sla-in-commensurable-units
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

		return array_map(fn (mixed $rule, int|string $index): array => $this->normaliseRule(rule: $rule, index: (int)$index), $rules, array_keys($rules));
	}//end normaliseRules()

	/**
	 * The AUTHORITATIVE preBreach check, on the timeline: every preBreach rung
	 * must resolve at or after the anchor. Refuses the first one that does not.
	 *
	 * @param RungTimeline      $timeline The rungs, the fire moment and the calendar.
	 * @param DateTimeInterface $anchorAt The instant the term runs from.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When an offset exceeds the SLA, naming the anchor.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-an-escalation-rule-is-validated-against-its-sla-in-commensurable-units
	 */
	public function validateAgainstTimeline(RungTimeline $timeline, DateTimeInterface $anchorAt): void {
		$calendar = $timeline->calendar;
		foreach ($timeline->rungs as $rung) {
			if ($rung['trigger'] !== self::TRIGGER_PRE_BREACH) {
				continue;
			}

			$instant = $this->rungInstant(rung: $rung, timeline: $timeline);
			if ($instant < $anchorAt) {
				$where = sprintf("it resolves to %s, before the anchor %s (calendar '%s')", $instant->format('c'), $anchorAt->format('c'), $calendar->getSlug());
				throw new FlowTimerValidationException(message: self::offsetLabel(rung: $rung) . ' exceeds the SLA — ' . $where . '.');
			}
		}
	}//end validateAgainstTimeline()

	/**
	 * The instant a rung falls on: `preBreach` is offset BEFORE the fire
	 * moment, `slaBreached` is offset AFTER it.
	 *
	 * @param array<string, mixed> $rung     The normalised rung.
	 * @param RungTimeline         $timeline The fire moment and the calendar the offset is measured in.
	 *
	 * @return DateTimeImmutable The rung's instant.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function rungInstant(array $rung, RungTimeline $timeline): DateTimeImmutable {
		$offset = (float)$rung['offset'];
		$unit = (string)$rung['offsetUnit'];
		if ($rung['trigger'] === self::TRIGGER_PRE_BREACH) {
			return $this->calculator->sub(from: $timeline->fireAt, value: $offset, unit: $unit, calendar: $timeline->calendar);
		}

		return $this->calculator->add(
			addition: new SlaAddition(
				start: DateTimeImmutable::createFromInterface($timeline->fireAt),
				value: $offset,
				unit: $unit,
				calendar: $timeline->calendar
			)
		);
	}//end rungInstant()

	/**
	 * The instant of the earliest UNFIRED rung on a timeline: the `next_rung_at` derivation.
	 *
	 * @param RungTimeline $timeline The rungs, the fire moment, the fired keys and the calendar.
	 *
	 * @return DateTimeImmutable|null The instant, or null when every rung has fired.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function nextRungAt(RungTimeline $timeline): ?DateTimeImmutable {
		$earliest = null;
		foreach ($this->unfired(timeline: $timeline) as $entry) {
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
	 * @param RungTimeline      $timeline The rungs, the fire moment, the fired keys and the calendar.
	 * @param DateTimeInterface $now      The sweep instant.
	 *
	 * @return array<int, array{rung: array<string, mixed>, at: DateTimeImmutable}> The due rungs.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function dueRungs(RungTimeline $timeline, DateTimeInterface $now): array {
		$due = [];
		foreach ($this->unfired(timeline: $timeline) as $entry) {
			if ($entry['at'] > $now) {
				continue;
			}

			$due[] = $entry;
		}

		return $due;
	}//end dueRungs()

	/**
	 * The rungs placed on the timeline of one fire moment, in ladder order, minus those already fired.
	 *
	 * @param RungTimeline $timeline The rungs, the fire moment, the fired keys and the calendar.
	 *
	 * @return list<array{rung: array<string, mixed>, at: DateTimeImmutable}> The unfired entries.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	private function unfired(RungTimeline $timeline): array {
		$entries = [];
		$instantOf = $this->instantOf(timeline: $timeline);
		foreach ($this->ordered(rungs: $timeline->rungs, instantOf: $instantOf) as $entry) {
			if (in_array((string)$entry['rung']['key'], $timeline->firedKeys, true) === false) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end unfired()

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
	 * @param array<string, mixed> $rung     The normalised rung.
	 * @param EscalationAudience   $audience The subject task, when the subject is a task, and the ladder's role → `type:id` bindings.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The recipients, deduplicated.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function resolveRecipients(array $rung, EscalationAudience $audience): array {
		$roles = array_values(array_unique(array_merge($rung['notifyRole'], $rung['escalateToRole'])));
		$recipients = [];
		foreach ($roles as $role) {
			foreach ($this->recipientsForRole(role: (string)$role, audience: $audience) as $recipient) {
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

		return $this->ladderSlugOwnedBy(organisation: $organisation);
	}//end organisationLadderSlug()

	/**
	 * The slug of the first defined ladder an organisation owns, or null.
	 *
	 * @param string $organisation The trimmed, non-empty organisation.
	 *
	 * @return string|null The slug.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	private function ladderSlugOwnedBy(string $organisation): ?string {
		foreach ($this->definitions->ladders() as $slug => $definition) {
			if (trim((string)($definition['organisation'] ?? '')) === $organisation) {
				return (string)$slug;
			}
		}

		return null;
	}//end ladderSlugOwnedBy()

	/**
	 * Rungs paired with their instants, earliest first (declared order breaks ties).
	 *
	 * @param array<int, array<string, mixed>>                          $rungs     The normalised rungs.
	 * @param Closure(array<string, mixed>): DateTimeImmutable $instantOf The instant a rung falls on.
	 *
	 * @return array<int, array{rung: array<string, mixed>, at: DateTimeImmutable}> The ordered rungs.
	 */
	private function ordered(array $rungs, Closure $instantOf): array {
		$entries = [];
		foreach (array_values($rungs) as $index => $rung) {
			$entries[] = ['rung' => $rung, 'at' => $instantOf($rung), 'index' => $index];
		}

		usort($entries, self::byInstantThenIndex(...));

		return array_map(
			static fn (array $entry): array => ['rung' => $entry['rung'], 'at' => $entry['at']],
			$entries
		);
	}//end ordered()

	/**
	 * The function that places a rung on the timeline of one fire moment.
	 *
	 * @param RungTimeline $timeline The fire moment and the calendar.
	 *
	 * @return Closure(array<string, mixed>): DateTimeImmutable The rung's instant.
	 */
	private function instantOf(RungTimeline $timeline): Closure {
		return fn (array $rung): DateTimeImmutable => $this->rungInstant(rung: $rung, timeline: $timeline);
	}//end instantOf()

	/**
	 * Order two entries by instant, declared position breaking a tie.
	 *
	 * @param array{at: DateTimeImmutable, index: int} $left  One entry.
	 * @param array{at: DateTimeImmutable, index: int} $right The other.
	 *
	 * @return integer The comparison.
	 */
	private static function byInstantThenIndex(array $left, array $right): int {
		if ($left['at']->getTimestamp() === $right['at']->getTimestamp()) {
			return ($left['index'] <=> $right['index']);
		}

		return ($left['at'] <=> $right['at']);
	}//end byInstantThenIndex()

	/**
	 * The slug of the ladder a timer names, else its organisation's default.
	 *
	 * @param FlowTimer $timer The timer.
	 *
	 * @return string The slug, or '' when neither names one.
	 */
	private function ladderSlugFor(FlowTimer $timer): string {
		$slug = trim((string)$timer->getLadderSlug());
		if ($slug === '') {
			$slug = (string)$this->organisationLadderSlug(organisation: $timer->getOrganisation());
		}

		return $slug;
	}//end ladderSlugFor()

	/**
	 * A ladder's role bindings; anything but an array binds nothing.
	 *
	 * @param array<string, mixed> $ladder The ladder definition.
	 *
	 * @return array<string, string> Role → `type:id`.
	 */
	private static function bindingsOf(array $ladder): array {
		$bindings = ($ladder['roleBindings'] ?? []);
		if (is_array($bindings) === false) {
			return [];
		}

		return $bindings;
	}//end bindingsOf()

	/**
	 * The start of a refused preBreach rung's message: its key and offset.
	 *
	 * @param array<string, mixed> $rung The normalised rung.
	 *
	 * @return string The label.
	 */
	private static function offsetLabel(array $rung): string {
		return sprintf(
			"Escalation rule '%s': the preBreach offset of %d %s",
			(string)$rung['key'],
			(int)$rung['offset'],
			(string)$rung['offsetUnit']
		);
	}//end offsetLabel()

	/**
	 * Validate and normalise one rule.
	 *
	 * @param mixed $rule  The declared rule.
	 * @param int   $index Its position, for messages.
	 *
	 * @return array<string, mixed> The normalised rung.
	 *
	 * @throws FlowTimerValidationException On a non-object rule or any refused field.
	 */
	private function normaliseRule(mixed $rule, int $index): array {
		if (is_array($rule) === false) {
			throw new FlowTimerValidationException(message: sprintf('Escalation rule #%d is not an object.', $index));
		}

		return (new EscalationRule(rule: $rule, index: $index))->toRung();
	}//end normaliseRule()

	/**
	 * The recipients one role resolves to.
	 *
	 * @param string             $role     The role.
	 * @param EscalationAudience $audience The subject task and the ladder's bindings.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The recipients.
	 */
	private function recipientsForRole(string $role, EscalationAudience $audience): array {
		$bound = $this->boundRecipients(binding: ($audience->roleBindings[$role] ?? null), role: $role);
		if ($bound !== []) {
			return $bound;
		}

		$performer = $this->handlerRecipients(role: $role, subject: $audience->subject);
		if ($performer !== []) {
			return $performer;
		}

		return [['type' => 'role', 'id' => $role, 'role' => $role]];
	}//end recipientsForRole()

	/**
	 * The recipient a `type:id` role binding names; none for any other binding.
	 *
	 * @param mixed  $binding The role's binding.
	 * @param string $role    The role.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The bound recipient, or [].
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	private function boundRecipients(mixed $binding, string $role): array {
		if (is_string($binding) === true && str_contains($binding, ':') === true) {
			[$type, $id] = explode(':', $binding, 2);

			return [['type' => trim($type), 'id' => trim($id), 'role' => $role]];
		}

		return [];
	}//end boundRecipients()

	/**
	 * The subject task's performers, for the handler role only.
	 *
	 * @param string    $role    The role.
	 * @param Task|null $subject The subject task, when there is one.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The performers, or [].
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	private function handlerRecipients(string $role, ?Task $subject): array {
		if ($role === self::ROLE_HANDLER && $subject !== null) {
			return $this->performerRecipients(subject: $subject, role: $role);
		}

		return [];
	}//end handlerRecipients()

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
			return [['type' => $this->assigneeType(subject: $subject), 'id' => $assignee, 'role' => $role]];
		}

		return $this->candidateRecipients(subject: $subject, role: $role);
	}//end performerRecipients()

	/**
	 * The recipient type of a task's assignee: a user, unless the task names another performer type.
	 *
	 * @param Task $subject The task.
	 *
	 * @return string The type.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	private function assigneeType(Task $subject): string {
		if ((string)$subject->getPerformerType() !== Task::PERFORMER_USER) {
			return (string)$subject->getPerformerType();
		}

		return 'user';
	}//end assigneeType()

	/**
	 * An unassigned task's candidates: its groups, else its users.
	 *
	 * @param Task   $subject The task.
	 * @param string $role    The role.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The candidates, possibly none.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	private function candidateRecipients(Task $subject, string $role): array {
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
	}//end candidateRecipients()
}//end class
