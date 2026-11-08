<?php

/**
 * CalendarSearchQuery — the arguments of one ICalendar::search() call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

/**
 * The text pattern, options and paging of one calendar search, carried
 * together through RegisterCalendar's search steps.
 */
final class CalendarSearchQuery {

	/**
	 * The summary text pattern; '' matches every event.
	 *
	 * @var string
	 */
	public readonly string $pattern;

	/**
	 * The search options (`timerange` is read).
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $options;

	/**
	 * The page size, or null for no limit.
	 *
	 * @var integer|null
	 */
	public readonly ?int $limit;

	/**
	 * The page offset, or null.
	 *
	 * @var integer|null
	 */
	public readonly ?int $offset;

	/**
	 * Constructor.
	 *
	 * @param string               $pattern The summary text pattern.
	 * @param array<string, mixed> $options The search options.
	 * @param int|null             $limit   The page size.
	 * @param int|null             $offset  The page offset.
	 */
	public function __construct(string $pattern, array $options, ?int $limit, ?int $offset) {
		$this->pattern = $pattern;
		$this->options = $options;
		$this->limit = $limit;
		$this->offset = $offset;
	}//end __construct()
}//end class
