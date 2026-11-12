<?php

/**
 * UsageBucket: one organisation's usage row for one hour.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\TenantUsage
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\TenantUsage;

use DateTime;

/**
 * The organisation and the hourly period that identify one usage row.
 *
 * @spec openspec/specs/tenant-quotas/spec.md
 */
final class UsageBucket {

	/**
	 * Constructor.
	 *
	 * @param string   $organisationUuid The organisation UUID.
	 * @param DateTime $period           The hourly bucket.
	 */
	public function __construct(
		public readonly string $organisationUuid,
		public readonly DateTime $period,
	) {
	}//end __construct()
}//end class
