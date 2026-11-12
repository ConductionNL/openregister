<?php

/**
 * The register and schema a dashboard read is narrowed to.
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
 * Which register and schema a dashboard read covers; null means all of them.
 */
final class DashboardScope {
	/**
	 * Constructor.
	 *
	 * @param int|null $registerId The register to narrow to.
	 * @param int|null $schemaId   The schema to narrow to.
	 */
	public function __construct(
		public readonly ?int $registerId = null,
		public readonly ?int $schemaId = null,
	) {
	}//end __construct()

	/**
	 * Whether a register and schema pair falls inside this scope.
	 *
	 * @param int $registerId The pair's register.
	 * @param int $schemaId   The pair's schema.
	 *
	 * @return bool
	 */
	public function contains(int $registerId, int $schemaId): bool {
		return ($this->registerId === null || $this->registerId === $registerId)
			&& ($this->schemaId === null || $this->schemaId === $schemaId);
	}//end contains()

	/**
	 * The filters that narrow a register listing to this scope.
	 *
	 * @return array<string, int>
	 */
	public function registerFilters(): array {
		if ($this->registerId === null) {
			return [];
		}

		return ['id' => $this->registerId];
	}//end registerFilters()

	/**
	 * The filters that narrow an audit log listing to this scope.
	 *
	 * @return array<string, int>
	 */
	public function logFilters(): array {
		$filters = [];
		if ($this->registerId !== null) {
			$filters['register'] = $this->registerId;
		}

		if ($this->schemaId !== null) {
			$filters['schema'] = $this->schemaId;
		}

		return $filters;
	}//end logFilters()

	/**
	 * This scope over a date range.
	 *
	 * @param DateTime|null $from Start date.
	 * @param DateTime|null $till End date.
	 *
	 * @return DashboardPeriod
	 */
	public function during(?DateTime $from, ?DateTime $till): DashboardPeriod {
		return new DashboardPeriod(scope: $this, from: $from, till: $till);
	}//end during()

	/**
	 * This scope for a most-active-objects read.
	 *
	 * @param int|null $limit Number of results.
	 * @param int|null $hours Hours to look back.
	 *
	 * @return ActivityWindow
	 */
	public function mostActive(?int $limit, ?int $hours): ActivityWindow {
		return new ActivityWindow(scope: $this, limit: $limit, hours: $hours);
	}//end mostActive()
}//end class
