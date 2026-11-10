<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Db;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * What `findLocalTenants()` actually returns, against a real database.
 *
 * The job-level tests mock the mapper, so they prove the jobs ASK the right
 * question. This proves the query ANSWERS it — and in particular the one thing
 * a mock cannot show:
 *
 * 🔴 A NULL `is_local_tenant` must count as a tenant. The column arrives by
 * migration, so every row written before it holds NULL. A plain `= true` would
 * make every pre-existing tenant invisible to all three tenant jobs at once —
 * tenants would silently stop being deprovisioned, purged and metered, and the
 * jobs would report success over an empty list.
 *
 * @group DB
 */
class OrganisationTenantScopeIntegrationTest extends TestCase {

	/**
	 * The mapper under test.
	 *
	 * @var OrganisationMapper
	 */
	private OrganisationMapper $mapper;

	/**
	 * Uuids created by this test, dropped in tearDown.
	 *
	 * @var array<int, string>
	 */
	private array $created = [];

	/**
	 * Resolve the mapper from the server container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = \OC::$server->get(OrganisationMapper::class);

	}//end setUp()

	/**
	 * Remove everything this test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->created as $uuid) {
			try {
				$this->mapper->delete($this->mapper->findByUuid($uuid));
			} catch (\Throwable $e) {
				// Already gone, or never created; nothing to clean.
			}
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * Store an organisation.
	 *
	 * @param string       $name    Its name.
	 * @param boolean|null $isLocal The tenancy flag, or null to leave it unset.
	 *
	 * @return string The uuid.
	 */
	private function make(string $name, ?bool $isLocal): string {
		$uuid = (string)Uuid::v4();

		$org = new Organisation();
		$org->setUuid($uuid);
		$org->setName($name . '-' . substr($uuid, 0, 8));
		$org->setSlug('scope-' . substr($uuid, 0, 8));
		$org->setStatus('active');
		if ($isLocal !== null) {
			$org->setIsLocalTenant($isLocal);
		}

		$this->mapper->insert($org);
		$this->created[] = $uuid;

		return $uuid;

	}//end make()

	/**
	 * The uuids findLocalTenants returns, across pages.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function tenantUuids(): array {
		$out = [];
		foreach ($this->mapper->findLocalTenants(limit: 500, filters: ['status' => 'active']) as $org) {
			$out[] = (string)$org->getUuid();
		}

		return $out;

	}//end tenantUuids()

	/**
	 * A counterparty is excluded; a tenant and a pre-migration row are not.
	 *
	 * @return void
	 */
	public function testACounterpartyIsExcludedAndANullIsNot(): void {
		$tenant = $this->make('tenant', true);
		$legacy = $this->make('legacy', null);
		$partner = $this->make('partner', false);

		$found = $this->tenantUuids();

		$this->assertContains($tenant, $found, 'an explicit tenant is a tenant');
		$this->assertContains(
			$legacy,
			$found,
			'a row predating the column must still count as a tenant, or every existing tenant silently stops being processed'
		);
		$this->assertNotContains($partner, $found, 'a counterparty is not a tenant of this installation');

	}//end testACounterpartyIsExcludedAndANullIsNot()

	/**
	 * findAll still returns everything, so the admin surface is unchanged.
	 *
	 * The narrowing is deliberately confined to the tenant path: an
	 * organisation list that hid counterparties would hide the ketenpartners
	 * the federation exists to work with.
	 *
	 * @return void
	 */
	public function testFindAllStillReturnsCounterparties(): void {
		$partner = $this->make('partner', false);

		$found = [];
		foreach ($this->mapper->findAll(limit: 500, filters: ['status' => 'active']) as $org) {
			$found[] = (string)$org->getUuid();
		}

		$this->assertContains($partner, $found);

	}//end testFindAllStillReturnsCounterparties()

	/**
	 * A counterparty carries the peer instance it is a tenant of.
	 *
	 * @return void
	 */
	public function testACounterpartyCarriesItsRemoteInstance(): void {
		$uuid = (string)Uuid::v4();
		$org = new Organisation();
		$org->setUuid($uuid);
		$org->setName('Gemeente Elders-' . substr($uuid, 0, 8));
		$org->setSlug('elders-' . substr($uuid, 0, 8));
		$org->setStatus('active');
		$org->setIsLocalTenant(false);
		$org->setRemoteInstanceUrl('https://fed2.example');
		$this->mapper->insert($org);
		$this->created[] = $uuid;

		$stored = $this->mapper->findByUuid($uuid);

		$this->assertFalse($stored->getIsLocalTenant());
		$this->assertSame('https://fed2.example', $stored->getRemoteInstanceUrl());

	}//end testACounterpartyCarriesItsRemoteInstance()

}//end class
