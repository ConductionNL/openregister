<?php

/**
 * FirstRowQuery: the first row of a query that may fail.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCP\DB\QueryBuilder\IQueryBuilder;
use Throwable;

/**
 * Reads the first row of a query built by the caller, treating a failure to
 * build or run it as no row.
 */
trait FirstRowQuery {

	/**
	 * The first row the query returns; null when it returns none or fails.
	 *
	 * @param callable(): IQueryBuilder $query Builds the query.
	 *
	 * @return array<string,mixed>|null
	 */
	private function firstRowOf(callable $query): ?array {
		try {
			$result = $query()->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			return null;
		}

		if ($row === false) {
			return null;
		}

		return $row;
	}//end firstRowOf()
}//end trait
