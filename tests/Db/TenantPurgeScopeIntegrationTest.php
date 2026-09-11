<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Db;

use DateTime;
use OCA\OpenRegister\BackgroundJob\TenantPurgeJob;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Symfony\Component\Uid\Uuid;

/**
 * What a purge deletes, against a real database.
 *
 * TenantPurgeJob PERMANENTLY deletes. It once removed the usage records of the
 * organisation it purged with `deleteOlderThan(2099-12-31)`, which has no
 * organisation in its WHERE clause: every purge of one organisation deleted the
 * usage history of every organisation on the installation. A mocked mapper
 * cannot show that, because the defect was the SQL. So this seeds real rows
 * for several organisations, runs the real job with the real usage mapper, and
 * counts what is left.
 *
 * The organisation read goes through the real `findLocalTenants()` query, but
 * its result is narrowed to the organisations this test created. Without that,
 * running the suite against a developer's instance would purge that
 * instance's own expired organisations as a side effect.
 *
 * @group DB
 */
class TenantPurgeScopeIntegrationTest extends TestCase {

	/**
	 * The real organisation mapper.
	 *
	 * @var OrganisationMapper
	 */
	private OrganisationMapper $organisations;

	/**
	 * The real usage mapper, the SQL under test.
	 *
	 * @var TenantUsageMapper
	 */
	private TenantUsageMapper $usage;

	/**
	 * Uuids of the organisations this test created.
	 *
	 * @var array<int, string>
	 */
	private array $created = [];

	/**
	 * Resolve the mappers from the server container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->organisations = \OC::$server->get(OrganisationMapper::class);
		$this->usage = \OC::$server->get(TenantUsageMapper::class);

	}//end setUp()

	/**
	 * Remove every organisation and usage row this test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->created as $uuid) {
			foreach ($this->usageOf($uuid) as $row) {
				try {
					$this->usage->delete($row);
				} catch (\Throwable $e) {
					// Already gone; nothing to clean.
				}
			}

			try {
				$this->organisations->delete($this->organisations->findByUuid($uuid));
			} catch (\Throwable $e) {
				// Purged by the test, or never created; nothing to clean.
			}
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * Store an organisation with two usage records.
	 *
	 * @param string $status Its lifecycle status.
	 * @param string|null $deprovisionedAt When it was deprovisioned, or null.
	 *
	 * @return string The uuid.
	 */
	private function seed(string $status, ?string $deprovisionedAt): string {
		$uuid = (string)Uuid::v4();

		$org = new Organisation();
		$org->setUuid($uuid);
		$org->setName('purge-scope-' . $status . '-' . substr($uuid, 0, 8));
		$org->setSlug('purge-scope-' . substr($uuid, 0, 8));
		$org->setStatus($status);
		if ($deprovisionedAt !== null) {
			$org->setDeprovisionedAt(new DateTime($deprovisionedAt));
		}

		$this->organisations->insert($org);
		$this->created[] = $uuid;

		$this->usage->upsertUsage(
			organisationUuid: $uuid,
			period: new DateTime('2026-01-01 10:00:00'),
			requestCount: 1,
			bandwidthBytes: 1,
			storageBytes: 1
		);
		$this->usage->upsertUsage(
			organisationUuid: $uuid,
			period: new DateTime('2026-06-01 10:00:00'),
			requestCount: 1,
			bandwidthBytes: 1,
			storageBytes: 1
		);

		return $uuid;
	}//end seed()

	/**
	 * Every usage record an organisation has, across all periods.
	 *
	 * @param string $uuid The organisation.
	 *
	 * @return array<int, \OCA\OpenRegister\Db\TenantUsage> Its records.
	 */
	private function usageOf(string $uuid): array {
		return $this->usage->findByOrgAndDateRange(
			organisationUuid: $uuid,
			from: new DateTime('2000-01-01 00:00:00'),
			to: new DateTime('2098-12-31 23:59:59')
		);

	}//end usageOf()

	/**
	 * Whether an organisation row still exists.
	 *
	 * @param string $uuid The organisation.
	 *
	 * @return bool True when it can still be found.
	 */
	private function exists(string $uuid): bool {
		try {
			$this->organisations->findByUuid($uuid);

			return true;
		} catch (\Throwable $e) {
			return false;
		}

	}//end exists()

	/**
	 * Run the purge job once, over the organisations this test created only.
	 *
	 * @return void
	 */
	private function runPurge(): void {
		$real = $this->organisations;
		$mine = $this->created;

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findLocalTenants')->willReturnCallback(
			static function (int $limit = 50, int $offset = 0, ?array $filters = []) use ($real, $mine): array {
				$rows = $real->findLocalTenants(limit: 1000, offset: 0, filters: $filters);

				return array_values(
					array_filter(
						$rows,
						static fn (Organisation $org): bool => in_array($org->getUuid(), $mine, true)
					)
				);
			}
		);
		$mapper->method('delete')->willReturnCallback(
			static fn (Organisation $org): Organisation => $real->delete($org)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('90');

		$job = new TenantPurgeJob(
			$this->createMock(ITimeFactory::class),
			$mapper,
			$this->usage,
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);

		// Protected methods are invokable through reflection since PHP 8.1.
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

	}//end runPurge()

	/**
	 * Purging one organisation leaves another organisation's usage intact.
	 *
	 * The test this change exists for. On the unscoped delete it fails on the
	 * active organisation's count, which drops from 2 to 0.
	 *
	 * @return void
	 */
	public function testPurgingOneOrganisationLeavesAnothersUsageRecords(): void {
		$purged = $this->seed('archived', '2020-01-01 00:00:00');
		$bystander = $this->seed('active', null);

		$this->assertCount(2, $this->usageOf($purged), 'precondition: the archived organisation has usage');
		$this->assertCount(2, $this->usageOf($bystander), 'precondition: the bystander has usage');

		$this->runPurge();

		$this->assertFalse($this->exists($purged), 'the archived organisation past retention is purged');
		$this->assertCount(0, $this->usageOf($purged), 'its own usage records go with it');
		$this->assertTrue($this->exists($bystander), 'the bystander organisation is untouched');
		$this->assertCount(
			2,
			$this->usageOf($bystander),
			'purging one organisation must not delete the usage records of another'
		);

	}//end testPurgingOneOrganisationLeavesAnothersUsageRecords()

}//end class
