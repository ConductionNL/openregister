<?php

/**
 * OpenRegister StoredTask
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Kernel\Contracts\TaskLocation;

/**
 * The VTODO document a task has in its calendar, and where it is stored.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class StoredTask {

	/**
	 * Constructor.
	 *
	 * @param TaskLocation $at           Where the document is stored.
	 * @param string       $calendarData The stored iCalendar document.
	 */
	public function __construct(
		public readonly TaskLocation $at,
		public readonly string $calendarData,
	) {

	}//end __construct()
}//end class
