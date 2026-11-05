<?php

/**
 * The date range of a dashboard chart.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

use DateTime;

/**
 * The scope, start and end of a chart; null leaves that side open.
 */
final class DashboardPeriod {
	/**
	 * Constructor.
	 *
	 * @param DashboardScope $scope The register and schema to narrow to.
	 * @param DateTime|null  $from  Start date.
	 * @param DateTime|null  $till  End date.
	 */
	public function __construct(
		public readonly DashboardScope $scope,
		public readonly ?DateTime $from = null,
		public readonly ?DateTime $till = null,
	) {
	}//end __construct()
}//end class
