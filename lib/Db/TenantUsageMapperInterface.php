<?php

/**
 * TenantUsageMapperInterface: what callers outside the Db component use of TenantUsageMapper.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\TenantUsage\UsageBucket;
use OCA\OpenRegister\Db\TenantUsage\UsageRange;

/**
 * The hourly tenant usage store: read an organisation's history, record an
 * hour's reading, and purge an organisation's rows.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to TenantUsageMapper. Guarded by H05InterfaceBindingsTest.
 */
interface TenantUsageMapperInterface {

	/**
	 * Find usage records for an organisation within a date range.
	 *
	 * @param string     $organisationUuid Organisation UUID
	 * @param UsageRange $range            The first and last bucket, inclusive
	 *
	 * @return TenantUsage[] Array of usage records
	 */
	public function findByOrgAndDateRange(string $organisationUuid, UsageRange $range): array;

	/**
	 * Record one hour's reading for an organisation (insert, or add to the hour's row).
	 *
	 * @param UsageBucket      $bucket      The organisation and the hourly bucket
	 * @param UsageMeasurement $measurement The traffic and storage measured
	 *
	 * @return TenantUsage The upserted entity
	 */
	public function upsertUsage(UsageBucket $bucket, UsageMeasurement $measurement): TenantUsage;

	/**
	 * Delete all usage records of one organisation, and nothing else.
	 *
	 * @param string $organisationUuid The uuid of the organisation being purged
	 *
	 * @return int Number of deleted records
	 *
	 * @throws InvalidArgumentException When the uuid is empty
	 */
	public function deleteByOrganisation(string $organisationUuid): int;
}//end interface
