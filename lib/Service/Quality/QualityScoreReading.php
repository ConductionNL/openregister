<?php

/**
 * OpenRegister QualityScoreReading
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
 * How one schema's quality score is read off its objects: the payload field
 * the score was materialised into, and the cut-offs that turn it into a
 * status. Both come from the same `x-openregister-quality` annotation and are
 * read together for every row, so {@see QualityStatisticsService} passes them
 * as one.
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */
final readonly class QualityScoreReading {

	/**
	 * Bundle the reading.
	 *
	 * @param string               $field      Payload field the score lives at.
	 * @param array<string, mixed> $thresholds Status cut-offs.
	 */
	public function __construct(
		public string $field,
		public array $thresholds,
	) {
	}//end __construct()
}//end class
