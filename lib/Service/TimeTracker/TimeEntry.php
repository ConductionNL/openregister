<?php

/**
 * TimeEntry: one TimeManager entry a link points at.
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
 * The kind (client, task or time) and the upstream uuid of a TimeManager entry.
 *
 * ADR-011 Rule 2 component-local value object: the pair that travels through
 * TimeTrackerLinkService when it links, hydrates and refreshes an entry.
 *
 * @spec openspec/specs/integration-time-tracker/spec.md
 */
final class TimeEntry {

	/**
	 * Describe it.
	 *
	 * @param string $type Entry kind (client, task or time).
	 * @param string $id   Upstream entry uuid.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
