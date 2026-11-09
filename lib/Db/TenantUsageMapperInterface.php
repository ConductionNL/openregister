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

use DateTime;
use InvalidArgumentException;

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
	 * @param string   $organisationUuid Organisation UUID
	 * @param DateTime $from             Start date
	 * @param DateTime $to               End date
	 *
	 * @return TenantUsage[] Array of usage records
	 */
	public function findByOrgAndDateRange(string $organisationUuid, DateTime $from, DateTime $to): array;

	/**
	 * Record one hour's reading for an organisation (insert, or add to the hour's row).
	 *
	 * @param string           $organisationUuid Organisation UUID
	 * @param DateTime         $period           Hourly bucket
	 * @param UsageMeasurement $measurement      The traffic and storage measured
	 *
	 * @return TenantUsage The upserted entity
	 */
	public function upsertUsage(string $organisationUuid, DateTime $period, UsageMeasurement $measurement): TenantUsage;

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
