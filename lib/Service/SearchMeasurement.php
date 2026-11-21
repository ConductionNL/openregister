<?php

/**
 * OpenRegister SearchMeasurement
 *
 * What one executed search returned and how it ran, for its search trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * The measured outcome of one executed search.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
final class SearchMeasurement {

	/**
	 * Constructor.
	 *
	 * @param int    $resultCount   Number of results returned on this page.
	 * @param int    $totalResults  Total number of matching results.
	 * @param float  $responseTime  Response time in milliseconds.
	 * @param string $executionType Execution type ('sync' or 'async').
	 */
	public function __construct(
		public readonly int $resultCount,
		public readonly int $totalResults,
		public readonly float $responseTime = 0.0,
		public readonly string $executionType = 'sync',
	) {
	}//end __construct()
}//end class
