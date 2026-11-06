<?php

/**
 * The pin state asked for on one entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Notifications\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use OCA\OpenRegister\Notifications\Db\TimelineEntry;

/**
 * An entry and whether it should be pinned.
 */
class EntryPin {

	/**
	 * Constructor.
	 *
	 * @param TimelineEntry $entry  The entry.
	 * @param boolean       $pinned Whether it should be pinned.
	 */
	public function __construct(
		public readonly TimelineEntry $entry,
		public readonly bool $pinned,
	) {
	}//end __construct()
}//end class
