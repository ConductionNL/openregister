<?php

/**
 * ActivityPeriod: the dates an activity listing is narrowed to.
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
 * The start and end date (Y-m-d) an activity listing is narrowed to.
 *
 * @spec openspec/specs/profile-actions/spec.md
 */
final class ActivityPeriod {

	/**
	 * Describe it.
	 *
	 * @param string|null $from Optional start date (Y-m-d).
	 * @param string|null $to   Optional end date (Y-m-d).
	 */
	public function __construct(
		public readonly ?string $from = null,
		public readonly ?string $to = null,
	) {
	}//end __construct()
}//end class
