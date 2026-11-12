<?php

/**
 * EntryRowLookup: one TimeManager row read by uuid on behalf of a user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TimeTracker
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-time-tracker/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TimeTracker;

/**
 * The table, the row uuid and the user one TimeManager row is read for.
 *
 * ADR-011 Rule 3 run object shared by the row-reading helpers of
 * TimeTrackerLinkService; it never leaves that class.
 *
 * @internal
 *
 * @spec openspec/specs/integration-time-tracker/spec.md
 */
final class EntryRowLookup {

	/**
	 * Describe it.
	 *
	 * @param string $table The TimeManager table.
	 * @param string $uuid  The row uuid.
	 * @param string $uid   The user the row must belong to.
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $uuid,
		public readonly string $uid,
	) {
	}//end __construct()
}//end class
