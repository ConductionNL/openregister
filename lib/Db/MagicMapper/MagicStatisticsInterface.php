<?php

/**
 * MagicStatisticsInterface: Object statistics and chart data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
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

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Object statistics and chart data.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicStatisticsInterface {
	/**
	 * Get statistics for objects across all magic tables.
	 *
	 * @param StatisticsScope $scope The registers and schemas to cover and the pairs to leave out; every table when omitted.
	 *
	 * @return int[] Statistics data
	 *
	 * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int}
	 */
	public function getStatistics(StatisticsScope $scope=new StatisticsScope()): array;

	/**
	 * Get object statistics grouped by schema for multiple schemas.
	 *
	 * Returns per-schema statistics using one count query per register-schema pair,
	 * grouped by schema ID. Replaces N individual getStatistics() calls.
	 *
	 * @param int[] $schemaIds Array of schema IDs to get statistics for.
	 *
	 * @return array<int, array{total: int, size: int}> Map of schemaId => statistics array.
	 */
	public function getStatisticsGroupedBySchema(array $schemaIds): array;

	/**
	 * Get register chart data.
	 *
	 * @param int|null $registerId Register ID filter
	 * @param int|null $schemaId Schema ID filter
	 *
	 * @return (int|mixed|string)[][] Chart data
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 */
	public function getRegisterChartData(?int $registerId = null, ?int $schemaId = null): array;

	/**
	 * Get schema chart data.
	 *
	 * @param int|null $registerId Register ID filter
	 * @param int|null $schemaId Schema ID filter
	 *
	 * @return (int|mixed|string)[][] Chart data
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 */
	public function getSchemaChartData(?int $registerId = null, ?int $schemaId = null): array;

	/**
	 * Get size distribution chart data for objects.
	 *
	 * @param int|null $registerId Optional register ID filter.
	 * @param int|null $schemaId Optional schema ID filter.
	 *
	 * @return array{labels: list<string>, series: list<int>} Chart data.
	 */
	public function getSizeDistributionChartData(?int $registerId = null, ?int $schemaId = null): array;
}//end interface
