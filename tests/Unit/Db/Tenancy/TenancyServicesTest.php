<?php

/**
 * TenancyServices unit test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Tenancy;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Tenancy\TenancyServices
 */
final class TenancyServicesTest extends TestCase {
	/**
	 * The bundle hands back exactly the services it was built with.
	 *
	 * @return void
	 */
	public function testItHandsBackTheServicesItWasBuiltWith(): void {
		$organisations = $this->createMock(OrganisationMapperInterface::class);
		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);

		$bundle = new TenancyServices($organisations, $session, $groups);

		$this->assertSame($organisations, $bundle->organisationMapper);
		$this->assertSame($session, $bundle->userSession);
		$this->assertSame($groups, $bundle->groupManager);
	}//end testItHandsBackTheServicesItWasBuiltWith()
}//end class
