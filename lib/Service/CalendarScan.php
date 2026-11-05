<?php

/**
 * OpenRegister Calendar Scan
 *
 * A pass over the events of one CalDAV calendar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeInterface;

/**
/**
 * A CalDAV calendar, by id and URI, and an optional lower bound on event start.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class CalendarScan {

	/**
	 * Unix timestamp events must not start before; null for no bound.
	 *
	 * @var integer|null
	 */
	private ?int $cutoff = null;

	/**
	 * Constructor.
	 *
	 * @param int    $calendarId  Numeric calendar id.
	 * @param string $calendarUri Calendar URI.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly int $calendarId,
		public readonly string $calendarUri,
	) {
	}//end __construct()

	/**
	 * A copy that skips events starting before the given time.
	 *
	 * @param int|null $cutoff Unix timestamp; null for no bound.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function startingFrom(?int $cutoff): self {
		$copy = clone $this;
		$copy->cutoff = $cutoff;
		return $copy;
	}//end startingFrom()

	/**
	 * Whether an event starting at the given time is in scope.
	 *
	 * @param DateTimeInterface|null $start Event start; null when unknown.
	 *
	 * @return bool False only when both the start and the bound are known and the start is earlier.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function admits(?DateTimeInterface $start): bool {
		return $this->cutoff === null || $start === null || $start->getTimestamp() >= $this->cutoff;
	}//end admits()
}//end class
