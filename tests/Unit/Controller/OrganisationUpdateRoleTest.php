<?php

/**
 * Who may change an organisation.
 *
 * A member reads their organisation and nothing more: renaming it or changing
 * its description takes a Nextcloud administrator or the organisation's own
 * administrator (its owner). The role is checked before anything is written.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
class OrganisationUpdateRoleTest extends TestCase {

	private OrganisationMapper&MockObject $organisationMapper;

	private Organisation $organisation;

	protected function setUp(): void {
		parent::setUp();

		$this->organisation = new Organisation();
		$this->organisation->setUuid('org-1');
		$this->organisation->setName('Gemeente Waterland');
		$this->organisation->setDescription('Gemeente');
		$this->organisation->setSlug('waterland');
		$this->organisation->setOwner('marieke');
		$this->organisation->setUsers(['marieke', 'pieter']);

		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation);
		$this->organisationMapper->method('save')->willReturnArgument(0);
	}//end setUp()

	/**
	 * The controller with OpenRegister's own role checks, signed in as a user.
	 *
	 * @param string               $uid    The signed-in user.
	 * @param bool                 $admin  Whether that user is a Nextcloud administrator.
	 * @param array<string, mixed> $params The request.
	 *
	 * @return OrganisationController The controller.
	 */
	private function controllerAs(string $uid, bool $admin, array $params): OrganisationController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$service = new OrganisationService(
			organisationMapper: $this->organisationMapper,
			userSession: $session,
			session: $this->createMock(ISession::class),
			config: $this->createMock(IConfig::class),
			appConfig: $this->createMock(IAppConfig::class),
			groupManager: $groups,
			userManager: $this->createMock(IUserManager::class),
			logger: new NullLogger()
		);

		return new OrganisationController(
			appName: 'openregister',
			request: $request,
			organisationService: $service,
			organisationMapper: $this->organisationMapper,
			logger: new NullLogger(),
			tenantLifecycleService: $this->createMock(TenantLifecycleService::class),
			tenantUsageMapper: $this->createMock(TenantUsageMapper::class),
			userSession: $session,
			groupManager: $groups,
			usageService: $this->createMock(OrganisationUsageService::class)
		);
	}//end controllerAs()

	/**
	 * @dataProvider verbs
	 *
	 * @param string $verb The controller method, update (PUT) or patch (PATCH).
	 */
	public function testAMemberIsRefusedBeforeAnythingIsWritten(string $verb): void {
		$this->organisationMapper->expects($this->never())->method('save');
		$this->organisationMapper->expects($this->never())->method('update');

		$response = $this->controllerAs('pieter', false, ['name' => 'Waterland-Noord', 'description' => 'Anders'])->{$verb}('org-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			'Only an administrator or an administrator of this organisation may change it. Members can read it.',
			$response->getData()['error']
		);
		$this->assertSame('Gemeente Waterland', $this->organisation->getName());
		$this->assertSame('Gemeente', $this->organisation->getDescription());
	}//end testAMemberIsRefusedBeforeAnythingIsWritten()

	/**
	 * @dataProvider verbs
	 *
	 * @param string $verb The controller method.
	 */
	public function testTheOrganisationsAdministratorMayChangeIt(string $verb): void {
		$response = $this->controllerAs('marieke', false, ['name' => 'Waterland-Noord', 'description' => 'Anders'])->{$verb}('org-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Waterland-Noord', $this->organisation->getName());
		$this->assertSame('Anders', $this->organisation->getDescription());
	}//end testTheOrganisationsAdministratorMayChangeIt()

	/**
	 * @dataProvider verbs
	 *
	 * @param string $verb The controller method.
	 */
	public function testANextcloudAdministratorMayChangeIt(string $verb): void {
		$response = $this->controllerAs('admin', true, ['description' => 'Gemeente in Noord-Holland'])->{$verb}('org-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Gemeente in Noord-Holland', $this->organisation->getDescription());
	}//end testANextcloudAdministratorMayChangeIt()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function verbs(): array {
		return [
			'PUT'   => ['update'],
			'PATCH' => ['patch'],
		];
	}//end verbs()
}//end class
