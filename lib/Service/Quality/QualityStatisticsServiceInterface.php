<?php

/**
 * Quality statistics and the lowest-quality listing for a register and schema, as the quality API reads them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Db\Schema;

/**
 * Quality statistics and the lowest-quality listing for a register and schema, as the quality API reads them.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see QualityStatisticsService} in ContractBindings.
 */
interface QualityStatisticsServiceInterface {

	/**
	 * Default page size for the lowest-quality listing.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 20;

	/**
	 * Compute quality statistics for a register/schema.
	 *
	 * @param int|string $register Register id, uuid or slug.
	 * @param int|string $schema Schema id, uuid or slug.
	 *
	 * @return array{
	 *   average: float|null,
	 *   total: int,
	 *   buckets: array{good: int, fair: int, poor: int},
	 *   histogram: array<int, array{min: float, max: float, count: int}>
	 * } Statistics envelope. Zeroed (null average, zero counts) when the
	 *   scoped set is empty.
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
	 */
	public function statisticsFor($register, $schema): array;

	/**
	 * List objects of a register/schema ascending by quality score.
	 *
	 * @param int|string $register Register id, uuid or slug.
	 * @param int|string $schema Schema id, uuid or slug.
	 * @param string|null $qualityStatus Optional status filter (`good`/`fair`/`poor`).
	 * @param QualityListingWindow $window Sort field and order, page size and offset.
	 *
	 * @return array{
	 *   items: array<int, array{id: string, qualityScore: float|null, qualityStatus: string|null}>,
	 *   total: int,
	 *   limit: int,
	 *   offset: int
	 * } Paginated listing, worst-first by default.
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
	 */
	public function lowestQuality(
		$register,
		$schema,
		?string $qualityStatus = null,
		QualityListingWindow $window = new QualityListingWindow(),
	): array;
}//end interface
