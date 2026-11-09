<?php

/**
 * ConfigurationMapper listing golden tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The SQL findAll() and findByApp() build, with and without the organisation filter.
 */
class ConfigurationMapperListingGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test, for a signed-in non-admin user.
	 *
	 * @return ConfigurationMapper
	 */
	private function mapper(): ConfigurationMapper {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new ConfigurationMapper(
			$this->recordingDb(),
			new TenancyServices($this->createMock(OrganisationMapper::class), $session, $groups),
			$this->createMock(IEventDispatcher::class)
		);
	}//end mapper()

	/**
	 * findAll() with defaults is unbounded and organisation-filtered; without an active organisation it matches nothing.
	 *
	 * @return void
	 */
	public function testFindAllDefaultsFilterOnOrganisation(): void {
		$this->mapper()->findAll();

		$this->assertSame('select(*) from(openregister_configurations) setMaxResults() setFirstResult(0) orderBy(created, DESC) andWhere(1 = 0) executeQuery()', $this->sql());
	}//end testFindAllDefaultsFilterOnOrganisation()

	/**
	 * findAll() pages, filters, searches and skips the organisation filter on request.
	 *
	 * @return void
	 */
	public function testFindAllWithPageFiltersSearchAndNoTenancy(): void {
		$this->mapper()->findAll(
			limit: 5,
			offset: 10,
			filters: ['app' => 'dossiq'],
			searchConditions: ['title LIKE :q'],
			searchParams: ['q' => '%x%'],
			_multitenancy: false
		);

		$this->assertSame('select(*) from(openregister_configurations) setMaxResults(5) setFirstResult(10) orderBy(created, DESC) andWhere(eq(app, :p0)) andWhere((title LIKE :q)) setParameter(q, %x%) executeQuery()', $this->sql());
	}//end testFindAllWithPageFiltersSearchAndNoTenancy()

	/**
	 * findByApp() pages and filters on organisation by default.
	 *
	 * @return void
	 */
	public function testFindByAppDefaultsFilterOnOrganisation(): void {
		$this->mapper()->findByApp('dossiq');

		$this->assertSame('select(*) from(openregister_configurations) where(eq(app, :p0)) setMaxResults(50) setFirstResult(0) orderBy(created, DESC) andWhere(1 = 0) executeQuery()', $this->sql());
		$this->assertSame('dossiq', $this->bound(':p0'));
	}//end testFindByAppDefaultsFilterOnOrganisation()

	/**
	 * findByApp() as a system lookup skips the organisation filter.
	 *
	 * @return void
	 */
	public function testFindByAppSystemLookupSkipsOrganisation(): void {
		$this->mapper()->findByApp('dossiq', 7, 14, true);

		$this->assertSame('select(*) from(openregister_configurations) where(eq(app, :p0)) setMaxResults(7) setFirstResult(14) orderBy(created, DESC) executeQuery()', $this->sql());
	}//end testFindByAppSystemLookupSkipsOrganisation()
}//end class
