<?php

/**
 * The calendar a term measures against, and why it is that one.
 *
 * Carries the scope that named it (timer, record type, unit or instance) and
 * whether a person's working pattern was laid over it, so the diagnostic can
 * say which applied rather than leave somebody to work it out (design D-C67-1
 * and D-C67-2).
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
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * A resolved calendar with its provenance.
 */
final class ResolvedWorkingCalendar {

	/**
	 * The person's own working pattern was applied.
	 *
	 * @var string
	 */
	public const PATTERN_PERSON = 'person';

	/**
	 * The scope calendar decided on its own.
	 *
	 * @var string
	 */
	public const PATTERN_CALENDAR = 'calendar';

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendar $calendar      The calendar the term measures against.
	 * @param string          $scope         Which scope named it, a WorkingCalendarScope::SCOPE_* value.
	 * @param string          $pattern       {@see self::PATTERN_PERSON} or {@see self::PATTERN_CALENDAR}.
	 * @param string|null     $person        The person the term is computed for, or null.
	 * @param string|null     $patternSource The app whose pattern was applied, or null.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	public function __construct(
		public readonly WorkingCalendar $calendar,
		public readonly string $scope,
		public readonly string $pattern = self::PATTERN_CALENDAR,
		public readonly ?string $person = null,
		public readonly ?string $patternSource = null,
	) {
	}//end __construct()

	/**
	 * What the diagnostic prints about the resolution.
	 *
	 * @return array{calendar: string, calendarScope: string, workingPattern: string, person: string|null, workingPatternSource: string|null}
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	public function describe(): array {
		return [
			'calendar' => $this->calendar->getSlug(),
			'calendarScope' => $this->scope,
			'workingPattern' => $this->pattern,
			'person' => $this->person,
			'workingPatternSource' => $this->patternSource,
		];
	}//end describe()
}//end class
