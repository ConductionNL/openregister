<?php

/**
 * How many of the most active objects to read, and how far back.
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

/**
 * The scope, size and look-back of a most-active-objects read.
 */
final class ActivityWindow {
	/**
	 * Constructor.
	 *
	 * @param DashboardScope $scope The register and schema to narrow to.
	 * @param int|null       $limit Number of results.
	 * @param int|null       $hours Hours to look back.
	 */
	public function __construct(
		public readonly DashboardScope $scope,
		public readonly ?int $limit = 10,
		public readonly ?int $hours = 24,
	) {
	}//end __construct()
}//end class
