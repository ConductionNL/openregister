<?php

/**
 * "Created more than three working hours ago", as configuration (row 11.50).
 *
 * The ledger note: "A rule cannot say created more than three working hours
 * ago, so time-based escalation is written as jobs rather than as configuration
 * a manager can change."
 *
 * 🔑 COMPILED, NOT INTERPRETED PER ROW (D-5). The offset resolves to ONE
 * instant — `now` minus the offset, walked through the working calendar once —
 * and the condition then becomes `createdAt <= <that instant>`, which is an
 * indexed comparison. Evaluating the walk per object would make a sweep over a
 * hundred thousand objects a hundred thousand walks, and that is the difference
 * between a feature and a feature nobody can switch on.
 *
 * 🔴 AN UNRESOLVABLE CALENDAR IS REFUSED AT SAVE, NEVER DOWNGRADED AT
 * EVALUATION (task 3.4). Falling back to wall-clock hours when the calendar is
 * missing would move every deadline that rule computes, silently, and the only
 * symptom would be terms landing on Sundays. The refusal happens where somebody
 * can read it.
 *
 * 🔑 AND IT USES THE ENGINE'S OWN ARITHMETIC, so two screens cannot disagree
 * about the same deadline. `workingHours` converts to business days through
 * `SlaCalculator::convert()` and is walked by the same `sub()` the timers use.
 * That means `workingHours` counts HOURS THAT FALL ON WORKING DAYS, because
 * that is what the engine's business-day walk counts: a working day is a whole
 * day to it. A window-aware offset — hours inside 09:00 to 17:00 — is a
 * different number, and `elapsedBusinessHours()` measures it but has no
 * inverse. Building one here would be inventing arithmetic the arm path does
 * not do, so the unit is named for what it actually counts rather than for what
 * it might be assumed to.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\UnitConversion;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use Throwable;

/**
 * Compiles a relative-time condition into one indexed comparison.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
class RelativeTimeCondition {

	/**
	 * The key a relative-time condition is written under.
	 *
	 * @var string
	 */
	public const KEY = '$age';

	/**
	 * Wall-clock hours, counted on every day including a Sunday.
	 *
	 * @var string
	 */
	public const UNIT_HOURS = 'hours';

	/**
	 * Hours that fall on WORKING DAYS, as the engine's business-day walk
	 * counts them. See the class docblock: this is not the 09:00-to-17:00
	 * window, and it is not pretending to be.
	 *
	 * @var string
	 */
	public const UNIT_WORKING_HOURS = 'workingHours';

	/**
	 * Calendar days, which are DATES and survive a DST change.
	 *
	 * @var string
	 */
	public const UNIT_CALENDAR_DAYS = 'calendarDays';

	/**
	 * Working days, skipping weekends and the calendar's rules.
	 *
	 * @var string
	 */
	public const UNIT_BUSINESS_DAYS = 'businessDays';

	/**
	 * The units a condition may use.
	 *
	 * @var array<int, string>
	 */
	public const UNITS = [
		self::UNIT_HOURS,
		self::UNIT_WORKING_HOURS,
		self::UNIT_CALENDAR_DAYS,
		self::UNIT_BUSINESS_DAYS,
	];

	/**
	 * The units that need a working calendar to mean anything.
	 *
	 * @var array<int, string>
	 */
	public const BUSINESS_UNITS = [self::UNIT_WORKING_HOURS, self::UNIT_BUSINESS_DAYS];

	/**
	 * The comparison: the property is at least this old.
	 *
	 * @var string
	 */
	public const MORE_THAN = 'moreThan';

	/**
	 * The comparison: the property is younger than this.
	 *
	 * @var string
	 */
	public const LESS_THAN = 'lessThan';

	/**
	 * The comparisons a condition may use.
	 *
	 * @var array<int, string>
	 */
	public const COMPARISONS = [self::MORE_THAN, self::LESS_THAN];

	/**
	 * The largest offset a condition may declare, in the unit it declares.
	 *
	 * Bounded because the offset is walked, and a walk of 10,000 business days
	 * is what `SlaCalculator::MAX_WALK_DAYS` already refuses — better to refuse
	 * it at save, naming the number, than to have the walk throw mid-sweep.
	 *
	 * @var int
	 */
	public const MAX_OFFSET = 10000;

	/**
	 * The operator each comparison compiles to.
	 *
	 * `moreThan` means older than the threshold, so the comparison is the
	 * LESS-than one. Getting this inversion wrong selects exactly the objects
	 * that are not due, which reads as "the rule does nothing" rather than as
	 * a bug.
	 *
	 * @var array<string, string>
	 */
	private const OPERATORS = [self::MORE_THAN => '<=', self::LESS_THAN => '>'];

	/**
	 * The engine unit each condition unit is walked in.
	 *
	 * Working hours are walked as business days, after the ENGINE'S OWN
	 * conversion, so the number this rule uses is the number the timers use.
	 *
	 * @var array<string, string>
	 */
	private const ENGINE_UNITS = [
		self::UNIT_HOURS => SlaCalculatorInterface::UNIT_HOURS,
		self::UNIT_WORKING_HOURS => SlaCalculatorInterface::UNIT_BUSINESS_DAYS,
		self::UNIT_CALENDAR_DAYS => SlaCalculatorInterface::UNIT_CALENDAR_DAYS,
		self::UNIT_BUSINESS_DAYS => SlaCalculatorInterface::UNIT_BUSINESS_DAYS,
	];

	/**
	 * Constructor.
	 *
	 * @param SlaCalculatorInterface $calculator The engine the timers use.
	 */
	public function __construct(
		private readonly SlaCalculatorInterface $calculator,
	) {
	}//end __construct()

	/**
	 * Whether a node is a relative-time condition.
	 *
	 * @param mixed $node The node.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function isRelativeTime(mixed $node): bool {
		return (is_array($node) === true && array_key_exists(self::KEY, $node) === true);
	}//end isRelativeTime()

	/**
	 * Why a relative-time condition may not be saved, or null when it may.
	 *
	 * @param mixed                $node     The condition node.
	 * @param WorkingCalendar|null $calendar The calendar that resolves for this schema.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function refusalFor(mixed $node, ?WorkingCalendar $calendar): ?string {
		if ($this->isRelativeTime(node: $node) === false) {
			return null;
		}

		$declaration = $node[self::KEY];
		if (is_array($declaration) === false) {
			return sprintf('"%s" must be an object naming a property and a comparison', self::KEY);
		}

		return $this->declarationRefusal(declaration: $declaration, calendar: $calendar);
	}//end refusalFor()

	/**
	 * Why a condition body may not be saved: no property, no comparison, or a bad offset.
	 *
	 * @param array<string, mixed> $declaration The condition body.
	 * @param WorkingCalendar|null $calendar    The calendar that resolves for this schema.
	 *
	 * @return string|null The reason, or null when it may be saved.
	 */
	private function declarationRefusal(array $declaration, ?WorkingCalendar $calendar): ?string {
		$property = (string)($declaration['property'] ?? '');
		if ($property === '') {
			return sprintf('"%s" must name the date property it compares', self::KEY);
		}

		if ($this->comparisonIn(declaration: $declaration) === null) {
			return sprintf('"%s" on "%s" must declare one of %s', self::KEY, $property, implode(' or ', self::COMPARISONS));
		}

		return ($this->offsetShapeRefusal(declaration: $declaration) ?? $this->calendarRefusal(declaration: $declaration, calendar: $calendar));
	}//end declarationRefusal()

	/**
	 * Which comparison the declaration names, or null when it names none.
	 *
	 * The FIRST match wins, as it always has: a declaration naming two
	 * comparisons is read as the earlier one rather than refused.
	 *
	 * @param array<string, mixed> $declaration The condition body.
	 *
	 * @return string|null The comparison key, or null.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	private function comparisonIn(array $declaration): ?string {
		foreach (self::COMPARISONS as $candidate) {
			if (array_key_exists($candidate, $declaration) === true) {
				return $candidate;
			}
		}

		return null;
	}//end comparisonIn()

	/**
	 * Why the declared offset is not a {value, unit} block with a known unit and a value in bounds.
	 *
	 * @param array<string, mixed> $declaration The condition body, naming a property and a comparison.
	 *
	 * @return string|null The reason, or null when the offset is well formed.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	private function offsetShapeRefusal(array $declaration): ?string {
		$comparison = (string)$this->comparisonIn(declaration: $declaration);
		$property = (string)$declaration['property'];
		$offset = $declaration[$comparison];
		if (is_array($offset) === false) {
			return sprintf('"%s" on "%s" must be {value, unit}', $comparison, $property);
		}

		return ($this->unitRefusal(offset: $offset, property: $property) ?? $this->valueRefusal(offset: $offset, property: $property));
	}//end offsetShapeRefusal()

	/**
	 * Why the offset's unit is refused, or null when it is a known unit.
	 *
	 * @param array<string, mixed> $offset   The declared {value, unit} block.
	 * @param string               $property The date property being compared.
	 *
	 * @return string|null The reason.
	 */
	private function unitRefusal(array $offset, string $property): ?string {
		$unit = (string)($offset['unit'] ?? '');
		if (in_array($unit, self::UNITS, true) === true) {
			return null;
		}

		return sprintf('unit "%s" on "%s" is refused: use one of %s', $unit, $property, implode(', ', self::UNITS));
	}//end unitRefusal()

	/**
	 * Why the offset's value is refused, or null when it is a positive number within bounds.
	 *
	 * @param array<string, mixed> $offset   The declared {value, unit} block.
	 * @param string               $property The date property being compared.
	 *
	 * @return string|null The reason.
	 */
	private function valueRefusal(array $offset, string $property): ?string {
		$value = ($offset['value'] ?? null);
		if (is_numeric($value) === false || (float)$value <= 0 || (float)$value > self::MAX_OFFSET) {
			return sprintf('offset on "%s" must be a positive number no greater than %d', $property, self::MAX_OFFSET);
		}

		return null;
	}//end valueRefusal()

	/**
	 * Why a business unit cannot be evaluated without a calendar, or null when it can.
	 *
	 * 🔴 The refusal that matters. A business unit with no calendar cannot be
	 * evaluated as anything except wall-clock time, and wall-clock time is a
	 * DIFFERENT DEADLINE. Refused here, where an author reads it.
	 *
	 * @param array<string, mixed> $declaration The condition body, with a well-formed offset.
	 * @param WorkingCalendar|null $calendar    The calendar for this schema.
	 *
	 * @return string|null The reason.
	 */
	private function calendarRefusal(array $declaration, ?WorkingCalendar $calendar): ?string {
		$unit = (string)$declaration[(string)$this->comparisonIn(declaration: $declaration)]['unit'];
		if (in_array($unit, self::BUSINESS_UNITS, true) === false || $calendar !== null) {
			return null;
		}

		return sprintf(
			'"%s" on "%s" is counted in %s, and no working calendar resolves for this schema; '
			. 'it would silently become wall-clock time, which is a different deadline',
			self::KEY,
			(string)$declaration['property'],
			$unit
		);
	}//end calendarRefusal()

	/**
	 * Compile the condition into one indexed comparison (D-5, task 3.3).
	 *
	 * The walk happens HERE, once, and what comes back is a property, an
	 * operator and an instant — which is a `WHERE created_at <= ?`, not a loop.
	 *
	 * @param mixed              $node   The condition node.
	 * @param RelativeTimeMoment $moment The present moment, and the calendar when the unit needs one.
	 *
	 * @return array{property: string, operator: string, value: string}|null The comparison, or null when the node is not one.
	 *
	 * @throws ConditionRefusedException When the condition cannot be compiled.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function compile(mixed $node, RelativeTimeMoment $moment): ?array {
		if ($this->isRelativeTime(node: $node) === false) {
			return null;
		}

		$refusal = $this->refusalFor(node: $node, calendar: $moment->calendar);
		if ($refusal !== null) {
			throw new ConditionRefusedException(conditionName: self::KEY, why: $refusal);
		}

		return $this->compiled(declaration: $node[self::KEY], moment: $moment);
	}//end compile()

	/**
	 * The comparison an accepted condition body compiles to.
	 *
	 * @param array<string, mixed> $declaration The accepted condition body.
	 * @param RelativeTimeMoment   $moment      The present moment and calendar.
	 *
	 * @return array{property: string, operator: string, value: string} The comparison.
	 *
	 * @throws ConditionRefusedException When the engine refuses the walk.
	 */
	private function compiled(array $declaration, RelativeTimeMoment $moment): array {
		$comparison = (string)$this->comparisonIn(declaration: $declaration);
		$threshold = $this->threshold(offset: $declaration[$comparison], moment: $moment);

		return [
			'property' => (string)$declaration['property'],
			'operator' => self::OPERATORS[$comparison],
			'value' => $threshold->format(DATE_ATOM),
		];
	}//end compiled()

	/**
	 * Whether the condition holds for one document.
	 *
	 * The PHP verdict, for a single object. It applies the SAME compiled
	 * comparison the query would, so the two cannot disagree — which is the
	 * property that matters, because a sweep selects by query and a save
	 * evaluates in PHP.
	 *
	 * @param mixed             $node  The condition node.
	 * @param RelativeTimeCheck $check The object, and the moment and calendar it is judged at.
	 *
	 * @return bool True when it holds.
	 *
	 * @throws ConditionRefusedException When the condition or the value cannot be read.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function holds(mixed $node, RelativeTimeCheck $check): bool {
		$compiled = $this->compile(node: $node, moment: $check->moment);
		if ($compiled === null) {
			throw new ConditionRefusedException(conditionName: self::KEY, why: 'the node is not a relative-time condition');
		}

		$value = $this->dateIn(document: $check->document, property: $compiled['property'])->getTimestamp();
		$threshold = (new DateTimeImmutable($compiled['value']))->getTimestamp();
		if ($compiled['operator'] === '<=') {
			return ($value <= $threshold);
		}

		return ($value > $threshold);
	}//end holds()

	/**
	 * The date a document holds under a property.
	 *
	 * 🔴 A missing or unreadable date is a REFUSAL, not a false. An object
	 * whose `createdAt` is absent is not "not yet due", it is an object the
	 * rule cannot judge, and saying "not due" would quietly exclude it from
	 * every sweep forever.
	 *
	 * @param array<string, mixed> $document The object.
	 * @param string               $property The date property.
	 *
	 * @return DateTimeImmutable The date.
	 *
	 * @throws ConditionRefusedException When the property holds no readable date.
	 */
	private function dateIn(array $document, string $property): DateTimeImmutable {
		$raw = ($document[$property] ?? null);
		if (is_string($raw) === false || $raw === '') {
			throw new ConditionRefusedException(
				conditionName: self::KEY,
				why: sprintf('"%s" holds no readable date on this object', $property)
			);
		}

		return $this->parseDate(raw: $raw, property: $property);
	}//end dateIn()

	/**
	 * Parse a stored date, refusing one that is not a date.
	 *
	 * @param string $raw      The stored value.
	 * @param string $property The date property, for the message.
	 *
	 * @return DateTimeImmutable The date.
	 *
	 * @throws ConditionRefusedException When the value is not a date.
	 */
	private function parseDate(string $raw, string $property): DateTimeImmutable {
		try {
			return new DateTimeImmutable($raw);
		} catch (Throwable $e) {
			throw new ConditionRefusedException(
				conditionName: self::KEY,
				why: sprintf('"%s" holds "%s", which is not a date', $property, $raw),
				previous: $e
			);
		}
	}//end parseDate()

	/**
	 * `now` minus the offset, walked through the calendar once.
	 *
	 * @param array<string, mixed> $offset The accepted {value, unit} block.
	 * @param RelativeTimeMoment   $moment The present moment and calendar.
	 *
	 * @return DateTimeImmutable The threshold instant.
	 *
	 * @throws ConditionRefusedException When the engine refuses the walk.
	 */
	private function threshold(array $offset, RelativeTimeMoment $moment): DateTimeImmutable {
		try {
			return $this->walkBack(offset: $offset, moment: $moment);
		} catch (ConditionRefusedException $refused) {
			throw $refused;
		} catch (Throwable $e) {
			throw new ConditionRefusedException(
				conditionName: self::KEY,
				why: $e->getMessage(),
				previous: $e
			);
		}//end try
	}//end threshold()

	/**
	 * Walk back from `now` by the offset, through the engine the timers use.
	 *
	 * Wall-clock hours and calendar days need NO calendar, and the engine's
	 * own branches for them never touch one. Demanding a calendar here would
	 * make an author invent one to say "two days".
	 *
	 * There is no null check on the calendar for a business unit, and that is
	 * deliberate rather than an omission. `compile()` calls `refusalFor()`
	 * before it ever reaches this method, so a business unit with no calendar
	 * has already been refused by the time the walk is asked for; and if some
	 * future caller reached it directly, `SlaCalculator::add()` refuses a
	 * business unit with a null calendar itself. Two reachable guards, rather
	 * than a third one here that no test could ever redden.
	 *
	 * Working hours become business days through the ENGINE'S OWN conversion.
	 * Doing the division here would be a second implementation of
	 * hoursPerWorkingDay, and those drift.
	 *
	 * @param array<string, mixed> $offset The accepted {value, unit} block.
	 * @param RelativeTimeMoment   $moment The present moment and calendar.
	 *
	 * @return DateTimeImmutable The threshold instant.
	 */
	private function walkBack(array $offset, RelativeTimeMoment $moment): DateTimeImmutable {
		$value = (float)$offset['value'];
		$unit = (string)$offset['unit'];
		if ($unit === self::UNIT_WORKING_HOURS) {
			$value = $this->calculator->convert(
				conversion: new UnitConversion(
					value: $value,
					fromUnit: SlaCalculatorInterface::UNIT_HOURS,
					toUnit: SlaCalculatorInterface::UNIT_BUSINESS_DAYS,
					calendar: $moment->calendar
				)
			);
		}

		return $this->calculator->sub(from: $moment->now, value: $value, unit: self::ENGINE_UNITS[$unit], calendar: $moment->calendar);
	}//end walkBack()
}//end class
