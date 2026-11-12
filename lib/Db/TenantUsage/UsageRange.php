<?php

/**
 * UsageRange: the period bounds of an organisation's usage history.
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
 * The first and the last hourly bucket a usage history covers, both inclusive.
 *
 * @spec openspec/specs/tenant-quotas/spec.md
 */
final class UsageRange {

	/**
	 * Constructor.
	 *
	 * @param DateTime $from The first bucket.
	 * @param DateTime $to   The last bucket.
	 */
	public function __construct(
		public readonly DateTime $from,
		public readonly DateTime $to,
	) {
	}//end __construct()
}//end class
