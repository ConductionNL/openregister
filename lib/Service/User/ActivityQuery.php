<?php

/**
 * ActivityQuery: one page of a user's audit-trail activity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\User
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/profile-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

/**
 * The page and the filters of one activity listing.
 *
 * ADR-011 Rule 2 operation object for UserService::activity(); the period
 * travels as its own ActivityPeriod.
 *
 * @spec openspec/specs/profile-actions/spec.md
 */
final class ActivityQuery {

	/**
	 * Describe it.
	 *
	 * @param int            $limit  Maximum results to return.
	 * @param int            $offset Results to skip.
	 * @param string|null    $type   Optional action type filter.
	 * @param ActivityPeriod $period Optional start and end dates.
	 */
	public function __construct(
		public readonly int $limit = 25,
		public readonly int $offset = 0,
		public readonly ?string $type = null,
		public readonly ActivityPeriod $period = new ActivityPeriod(),
	) {
	}//end __construct()
}//end class
