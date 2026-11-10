<?php

/**
 * A tenant's usage for one period, as the API shows it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\TenantUsage;
use PHPUnit\Framework\TestCase;

/**
 * Counters serialise as integers and dates as ISO 8601, or null when unset.
 *
 * @covers \OCA\OpenRegister\Db\TenantUsage
 */
final class TenantUsageSerialisationTest extends TestCase {

	/**
	 * A filled row serialises every field, dates in ISO 8601 with their offset.
	 *
	 * @return void
	 */
	public function testAFilledRowSerialisesEveryField(): void {
		$utc = new DateTimeZone('UTC');
		$usage = new TenantUsage();
		$usage->setId(5);
		$usage->setOrganisationUuid('org-1');
		$usage->setPeriod(new DateTime('2026-11-01 00:00:00', $utc));
		$usage->setRequestCount(120);
		$usage->setBandwidthBytes(2048);
		$usage->setStorageBytes(4096);
		$usage->setCreated(new DateTime('2026-11-01 08:00:00', $utc));
		$usage->setUpdated(new DateTime('2026-11-02 09:30:00', $utc));

		$this->assertSame(
			[
				'id' => 5,
				'organisationUuid' => 'org-1',
				'period' => '2026-11-01T00:00:00+00:00',
				'requestCount' => 120,
				'bandwidthBytes' => 2048,
				'storageBytes' => 4096,
				'created' => '2026-11-01T08:00:00+00:00',
				'updated' => '2026-11-02T09:30:00+00:00',
			],
			$usage->jsonSerialize()
		);
	}//end testAFilledRowSerialisesEveryField()

	/**
	 * A fresh row has zero counters and null dates.
	 *
	 * @return void
	 */
	public function testAFreshRowHasZeroCountersAndNullDates(): void {
		$json = (new TenantUsage())->jsonSerialize();

		$this->assertSame(
			['organisationUuid' => '', 'period' => null, 'requestCount' => 0, 'bandwidthBytes' => 0, 'storageBytes' => 0, 'created' => null, 'updated' => null],
			array_diff_key($json, ['id' => true])
		);
	}//end testAFreshRowHasZeroCountersAndNullDates()

	/**
	 * Database strings are hydrated to the declared types.
	 *
	 * @return void
	 */
	public function testDatabaseRowsAreHydratedToTheirTypes(): void {
		$usage = TenantUsage::fromRow(['organisation_uuid' => 'org-2', 'request_count' => '7', 'storage_bytes' => '9']);

		$this->assertSame(7, $usage->getRequestCount());
		$this->assertSame(9, $usage->getStorageBytes());
		$this->assertSame('org-2', $usage->getOrganisationUuid());
	}//end testDatabaseRowsAreHydratedToTheirTypes()
}//end class
