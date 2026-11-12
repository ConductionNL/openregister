<?php

/**
 * OpenRegister QualityListingWindow
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

/**
 * The sort and page of one lowest-quality listing.
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */
final readonly class QualityListingWindow {

	/**
	 * Constructor.
	 *
	 * @param string $sort Sort field: `qualityScore` (default) or `qualityStatus`.
	 * @param string $order Sort order: `asc` (default) or `desc`.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 */
	public function __construct(
		public string $sort = 'qualityScore',
		public string $order = 'asc',
		public int $limit = QualityStatisticsServiceInterface::DEFAULT_LIMIT,
		public int $offset = 0,
	) {
	}//end __construct()
}//end class
