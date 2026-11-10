<?php

/**
 * MagicOrganizationHandlerContextScopeTest
 *
 * Inside an organisation context the organisation scope is the context's,
 * before the system, admin and bypass branches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
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

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCP\IUserManager;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class MagicOrganizationHandlerContextScopeTest extends TestCase {

	/**
	 * A handler whose caller is an admin (or nobody) with the given active organisations.
	 *
	 * @param IUser|null         $user     The session user.
	 * @param array<int, string> $orgUuids What the organisation service answers.
	 * @param array<int, string> $holders  The shared master data holders.
	 * @param string             $multitenancy The stored multitenancy config.
	 *
	 * @return MagicOrganizationHandler
	 */
	private function handler(?IUser $user, array $orgUuids, array $holders = [], string $multitenancy = ''): MagicOrganizationHandler {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['admin']);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($multitenancy);

		$shared = $this->createMock(SharedMasterDataService::class);
		$shared->method('holdersForResource')->willReturn($holders);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($shared) {
				if ($id === SharedMasterDataService::class) {
					return $shared;
				}

				throw new \RuntimeException('no service ' . $id);
			}
		);

		$handler = $this->getMockBuilder(MagicOrganizationHandler::class)
			->setConstructorArgs([new MagicMapperIdentity($session, $groups, $this->createMock(IUserManager::class)), $appConfig, $container, $this->createMock(LoggerInterface::class)])
			->onlyMethods(['getActiveOrganizationUuids'])
			->getMock();
		$handler->method('getActiveOrganizationUuids')->willReturn($orgUuids);

		return $handler;
	}//end handler()

	private function admin(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		return $user;
	}//end admin()

	public function testAnAdminWithTheBypassStaysInsideTheContextsOrganisation(): void {
		$scope = OrganisationContext::run(
			'org-a',
			fn (): array => $this->handler($this->admin(), ['org-a', 'org-parent'])->resolveOrganizationScope(adminBypassEnabled: true)
		);

		$this->assertSame(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a', 'org-parent']], $scope);
	}//end testAnAdminWithTheBypassStaysInsideTheContextsOrganisation()

	public function testTheSystemScopeDoesNotWidenTheContext(): void {
		$scope = SystemOperationContext::run(
			fn (): array => OrganisationContext::run(
				'org-a',
				fn (): array => $this->handler(null, ['org-a'])->resolveOrganizationScope()
			)
		);

		$this->assertSame(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']], $scope);
	}//end testTheSystemScopeDoesNotWidenTheContext()

	public function testAnUnknownContextOrganisationAdmitsNothing(): void {
		$scope = OrganisationContext::run(
			'org-x',
			fn (): array => $this->handler($this->admin(), [])->resolveOrganizationScope(adminBypassEnabled: true)
		);

		$this->assertSame(['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => []], $scope);
	}//end testAnUnknownContextOrganisationAdmitsNothing()

	public function testSharedMasterDataHoldersJoinTheContextsScope(): void {
		$scope = OrganisationContext::run(
			'org-a',
			fn (): array => $this->handler(null, ['org-a'], ['org-holder', 'org-a'])->resolveOrganizationScope(registerId: 1, schemaId: 2)
		);

		$this->assertSame(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a', 'org-holder']], $scope);
	}//end testSharedMasterDataHoldersJoinTheContextsScope()

	public function testInSaasModeAnAdminWithTheBypassStaysInsideTheirOrganisation(): void {
		$scope = $this->handler($this->admin(), ['org-a'], [], '{"saasMode":true}')->resolveOrganizationScope(adminBypassEnabled: true);

		$this->assertSame(['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-a']], $scope);
	}//end testInSaasModeAnAdminWithTheBypassStaysInsideTheirOrganisation()

	public function testOutsideAContextAnAdminWithTheBypassReadsEverything(): void {
		$scope = $this->handler($this->admin(), ['org-a'])->resolveOrganizationScope(adminBypassEnabled: true);

		$this->assertSame(MagicOrganizationHandler::SCOPE_ALL, $scope['mode']);
	}//end testOutsideAContextAnAdminWithTheBypassReadsEverything()
}//end class
