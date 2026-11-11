<?php

/**
 * SearchTrailOutcome: what one executed search returned and how it ran, ready to be stored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The finished outcome values a new search trail stores.
 *
 * The mapper stores these values as given; rounding and defaults are the
 * caller's work.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
final class SearchTrailOutcome {

	/**
	 * Constructor.
	 *
	 * @param int    $resultCount   Number of results returned on this page.
	 * @param int    $totalResults  Total number of matching results.
	 * @param int    $responseTime  Response time in whole milliseconds.
	 * @param string $executionType Execution type ('sync' or 'async').
	 */
	public function __construct(
		public readonly int $resultCount,
		public readonly int $totalResults,
		public readonly int $responseTime,
		public readonly string $executionType,
	) {
	}//end __construct()
}//end class
