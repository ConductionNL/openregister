<?php

/**
 * OpenRegister RelativeTimeEvaluation
 *
 * Evaluates a `$age` condition where a rule is evaluated: the present moment
 * from the clock, and the working calendar that resolves for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Which calendar a relative-time condition counts in, and whether it holds now.
 *
 * 🔑 THE CALENDAR ORDER IS THE TIMERS' ORDER (task 3.2b). A `$age` body may
 * name a `calendar`; otherwise the object's organisation calendar applies;
 * otherwise the default. That is exactly {@see WorkingCalendarService::resolve()},
 * so a rule and a timer on the same object count the same working hours and
 * cannot disagree about one deadline. A record-type level belongs to
 * working-calendar-admin and is not invented here.
 *
 * 🔴 A calendar that does not resolve is a REFUSAL, never wall-clock time: a
 * business-unit offset silently counted on the wall clock is a different
 * deadline. Hours and calendar days need no calendar and never resolve one.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
class RelativeTimeEvaluation {

	/**
	 * Constructor.
	 *
	 * @param RelativeTimeCondition  $condition The `$age` vocabulary and its compiled comparison.
	 * @param WorkingCalendarService $calendars The calendar resolution the timers use.
	 * @param ITimeFactory           $clock     The present moment.
	 */
	public function __construct(
		private readonly RelativeTimeCondition $condition,
		private readonly WorkingCalendarService $calendars,
		private readonly ITimeFactory $clock,
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
		return $this->condition->isRelativeTime(node: $node);
	}//end isRelativeTime()

	/**
	 * Whether a relative-time condition holds for one document, now.
	 *
	 * @param mixed                $node         The `$age` node.
	 * @param array<string, mixed> $document     The object the condition reads.
	 * @param string|null          $organisation The object's organisation, for its calendar.
	 *
	 * @return bool True when it holds.
	 *
	 * @throws ConditionRefusedException When the condition, its calendar or the date cannot be read.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function holds(mixed $node, array $document, ?string $organisation = null): bool {
		$calendar = $this->calendarFor(node: $node, organisation: $organisation);
		$refusal  = $this->condition->refusalFor(node: $node, calendar: $calendar);
		if ($refusal !== null) {
			throw new ConditionRefusedException(conditionName: RelativeTimeCondition::KEY, why: $refusal);
		}

		$moment = new RelativeTimeMoment(now: $this->clock->getDateTime(), calendar: $calendar);

		return $this->condition->holds(node: $node, check: new RelativeTimeCheck(document: $document, moment: $moment));
	}//end holds()

	/**
	 * Why a relative-time condition may not be saved, or null when it may.
	 *
	 * At save there is no object and so no organisation: the calendar checked
	 * is the named one, else the default, which is what an object without an
	 * organisation calendar will count in.
	 *
	 * @param mixed $node The `$age` node.
	 *
	 * @return string|null The reason, naming the calendar when it is the calendar that is missing.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function refusalAtSave(mixed $node): ?string {
		try {
			$calendar = $this->calendarFor(node: $node, organisation: null);
		} catch (ConditionRefusedException $refused) {
			return $refused->getWhy();
		}

		return $this->condition->refusalFor(node: $node, calendar: $calendar);
	}//end refusalAtSave()

	/**
	 * The calendar a condition counts in, or null when its unit needs none.
	 *
	 * @param mixed       $node         The `$age` node.
	 * @param string|null $organisation The object's organisation.
	 *
	 * @return WorkingCalendar|null The calendar.
	 *
	 * @throws ConditionRefusedException When the calendar does not resolve, naming it.
	 */
	private function calendarFor(mixed $node, ?string $organisation): ?WorkingCalendar {
		$body = self::bodyOf(node: $node);
		if (in_array(self::unitOf(body: $body), RelativeTimeCondition::BUSINESS_UNITS, true) === false) {
			return null;
		}

		$named = $body['calendar'] ?? null;
		if ($named !== null && is_string($named) === false) {
			throw new ConditionRefusedException(conditionName: RelativeTimeCondition::KEY, why: 'its "calendar" must name a calendar by slug');
		}

		try {
			return $this->calendars->resolve(calendarSlug: $named, organisation: $organisation);
		} catch (FlowTimerValidationException $missing) {
			throw new ConditionRefusedException(
				conditionName: RelativeTimeCondition::KEY,
				why: sprintf('its working calendar does not resolve: %s', $missing->getMessage())
			);
		}
	}//end calendarFor()

	/**
	 * The `$age` body, or an empty one when the node carries none.
	 *
	 * @param mixed $node The node.
	 *
	 * @return array<string, mixed> The body.
	 */
	private static function bodyOf(mixed $node): array {
		$body = null;
		if (is_array($node) === true) {
			$body = ($node[RelativeTimeCondition::KEY] ?? null);
		}

		if (is_array($body) === false) {
			return [];
		}

		return $body;
	}//end bodyOf()

	/**
	 * The unit the body's first comparison counts in, or an empty string.
	 *
	 * The FIRST comparison, as {@see RelativeTimeCondition} reads it.
	 *
	 * @param array<string, mixed> $body The `$age` body.
	 *
	 * @return string The unit.
	 */
	private static function unitOf(array $body): string {
		foreach (RelativeTimeCondition::COMPARISONS as $comparison) {
			if (array_key_exists($comparison, $body) === true) {
				return (string)(($body[$comparison]['unit'] ?? null) ?? '');
			}
		}

		return '';
	}//end unitOf()
}//end class
