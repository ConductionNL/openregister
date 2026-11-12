<?php

/**
 * OpenRegister TaskLocation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Where a CalDAV task lives: the calendar holding it and the URI of its calendar object.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class TaskLocation {

	/**
	 * Constructor.
	 *
	 * @param int|string $calendarId The calendar id, as the caller or the calendar row carries it.
	 * @param string     $uri        The calendar object URI (.ics).
	 */
	public function __construct(
		public readonly int|string $calendarId,
		public readonly string $uri,
	) {

	}//end __construct()
}//end class
