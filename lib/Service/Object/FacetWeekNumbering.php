<?php

/**
 * OpenRegister FacetWeekNumbering
 *
 * Which calendar's first week of the year the week histograms of a facet query follow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\FacetWeekNumberingInterface;
use OCA\OpenRegister\Kernel\Contracts\FacetWeekRelabelInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Service\Flow\Timer\CalendarWeekNumbering;
use OCA\OpenRegister\Service\Flow\Timer\RecordTypeCalendars;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the calendar a facet query's week numbers follow.
 *
 * The record type's calendar when every faceted schema names the same one,
 * otherwise the instance calendar. ISO, or a calendar that cannot be
 * resolved, leaves the buckets as the database keyed them.
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */
class FacetWeekNumbering implements FacetWeekNumberingInterface {

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendarService $calendars Resolves a calendar by slug, or the instance calendar.
	 * @param LoggerInterface        $logger    Notes a calendar that cannot be resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function __construct(
		private readonly WorkingCalendarService $calendars,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The week labels for facets over these schemas, or null when the buckets stay ISO.
	 *
	 * @param array<int, mixed> $schemas The schemas the facet query reads.
	 *
	 * @return FacetWeekRelabelInterface|null
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function labelsFor(array $schemas): ?FacetWeekRelabelInterface {
		$slug = self::sharedCalendar(schemas: $schemas);
		try {
			$calendar = $this->calendars->resolve(calendarSlug: $slug, organisation: null);
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[FacetWeekNumbering] No working calendar resolved; week buckets stay ISO weeks.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'calendar' => $slug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($calendar->getFirstWeekOfYear() === CalendarWeekNumbering::ISO) {
			return null;
		}

		return new FacetWeekLabels(calendar: $calendar);
	}//end labelsFor()

	/**
	 * The calendar slug every schema names, or null when one names none or they differ.
	 *
	 * @param array<int, mixed> $schemas The schemas.
	 *
	 * @return string|null
	 */
	private static function sharedCalendar(array $schemas): ?string {
		$shared = null;
		foreach ($schemas as $schema) {
			if ($schema instanceof SchemaInterface === false) {
				return null;
			}

			$slug = ($schema->getConfiguration() ?? [])[RecordTypeCalendars::CONFIGURATION_KEY] ?? null;
			if (is_string($slug) === false || trim($slug) === '') {
				return null;
			}

			if ($shared !== null && $shared !== trim($slug)) {
				return null;
			}

			$shared = trim($slug);
		}

		return $shared;
	}//end sharedCalendar()
}//end class
