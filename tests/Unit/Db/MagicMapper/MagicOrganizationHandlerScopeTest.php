<?php

/**
 * MagicOrganizationHandlerScopeTest
 *
 * Pins the organisation boundary as a DECISION, independent of the SQL any
 * one caller renders from it.
 *
 * The rule had two implementations: this class built it with the query
 * builder, and `AggregationRunner::tryNativeAggregation()` carried a flattened
 * copy — a bare `_organisation = :activeOrg`. SQL `=` never matches NULL, so
 * every object with no organisation was invisible to the aggregation API while
 * the list API returned it. Measured 2026-08-30 on `decidiq/meeting`: four
 * meetings listed, one of them org-less, `count` answered 3, and
 * `filter[lifecycle]=closed` answered 0 for a meeting that plainly exists.
 * Every KPI tile in the fleet reads that endpoint.
 *
 * `SCOPE_IN_OR_NULL` is the case that was lost, so it is the case these tests
 * exist for.
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

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 */
class MagicOrganizationHandlerScopeTest extends TestCase {

	/**
	 * Build a handler for a caller with the given admin flag and active orgs.
	 *
	 * A non-null user is always supplied: `isSystemContext()` treats a NULL
	 * user under the CLI SAPI as a trusted system context, and PHPUnit runs
	 * under CLI, so a null user would silently make every case SCOPE_ALL.
	 *
	 * @param bool               $isAdmin  Whether the caller is in the admin group.
	 * @param array<int, string> $orgUuids Active organisation UUIDs.
	 *
	 * @return MagicOrganizationHandler
	 */
	private function handler(bool $isAdmin, array $orgUuids): MagicOrganizationHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($isAdmin === true ? ['admin'] : ['users']);

		$appConfig = $this->createMock(IAppConfig::class);
		// No multitenancy config ⇒ SaaS mode off.
		$appConfig->method('getValueString')->willReturn('');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no service'));

		$handler = $this->getMockBuilder(MagicOrganizationHandler::class)
			->setConstructorArgs([$session, $groups, $appConfig, $container, $this->createMock(LoggerInterface::class)])
			->onlyMethods(['getActiveOrganizationUuids'])
			->getMock();
		$handler->method('getActiveOrganizationUuids')->willReturn($orgUuids);

		return $handler;
	}//end handler()

	/**
	 * THE REGRESSION. An admin scoped to an organisation must still see rows
	 * that have no organisation — that is what the aggregation's flattened
	 * `_organisation = ?` silently dropped.
	 *
	 * @return void
	 */
	public function testAdminWithAnActiveOrgAlsoSeesOrgLessRows(): void {
		$scope = $this->handler(true, ['org-a'])->resolveOrganizationScope();

		$this->assertSame(MagicOrganizationHandler::SCOPE_IN_OR_NULL, $scope['mode']);
		$this->assertSame(['org-a'], $scope['uuids']);
	}//end testAdminWithAnActiveOrgAlsoSeesOrgLessRows()

	/**
	 * A non-admin is confined to their own organisation, and org-less rows
	 * stay invisible to them. Widening this would be a tenant leak.
	 *
	 * @return void
	 */
	public function testNonAdminIsConfinedToTheirOwnOrganisation(): void {
		$scope = $this->handler(false, ['org-a'])->resolveOrganizationScope();

		$this->assertSame(MagicOrganizationHandler::SCOPE_IN, $scope['mode']);
		$this->assertSame(['org-a'], $scope['uuids']);
	}//end testNonAdminIsConfinedToTheirOwnOrganisation()

	/**
	 * Several active organisations stay a set — a renderer that collapsed
	 * them to one would under-report the rest.
	 *
	 * @return void
	 */
	public function testMultipleActiveOrganisationsAreAllCarried(): void {
		$scope = $this->handler(false, ['org-a', 'org-b'])->resolveOrganizationScope();

		$this->assertSame(MagicOrganizationHandler::SCOPE_IN, $scope['mode']);
		$this->assertSame(['org-a', 'org-b'], $scope['uuids']);
	}//end testMultipleActiveOrganisationsAreAllCarried()

	/**
	 * An admin with no active organisation sees exactly the org-less rows.
	 *
	 * @return void
	 */
	public function testAdminWithoutAnActiveOrganisationSeesOnlyOrgLessRows(): void {
		$scope = $this->handler(true, [])->resolveOrganizationScope();

		$this->assertSame(MagicOrganizationHandler::SCOPE_NULL_ONLY, $scope['mode']);
		$this->assertSame([], $scope['uuids']);
	}//end testAdminWithoutAnActiveOrganisationSeesOnlyOrgLessRows()

	/**
	 * Fail-closed: a non-admin with no active organisation sees nothing at
	 * all, rather than everything.
	 *
	 * @return void
	 */
	public function testNonAdminWithoutAnActiveOrganisationSeesNothing(): void {
		$scope = $this->handler(false, [])->resolveOrganizationScope();

		$this->assertSame(MagicOrganizationHandler::SCOPE_NONE, $scope['mode']);
	}//end testNonAdminWithoutAnActiveOrganisationSeesNothing()

	/**
	 * The admin bypass lifts the boundary entirely, but only when the caller
	 * opts into it — the default must stay scoped.
	 *
	 * @return void
	 */
	public function testAdminBypassIsOptInAndLiftsTheBoundary(): void {
		$handler = $this->handler(true, ['org-a']);

		$this->assertSame(
			MagicOrganizationHandler::SCOPE_ALL,
			$handler->resolveOrganizationScope(adminBypassEnabled: true)['mode']
		);
		$this->assertSame(
			MagicOrganizationHandler::SCOPE_IN_OR_NULL,
			$handler->resolveOrganizationScope()['mode'],
			'the bypass must not leak into the default call'
		);
	}//end testAdminBypassIsOptInAndLiftsTheBoundary()

	/**
	 * The bypass is for admins only.
	 *
	 * @return void
	 */
	public function testAdminBypassDoesNothingForANonAdmin(): void {
		$scope = $this->handler(false, ['org-a'])->resolveOrganizationScope(adminBypassEnabled: true);

		$this->assertSame(MagicOrganizationHandler::SCOPE_IN, $scope['mode']);
	}//end testAdminBypassDoesNothingForANonAdmin()

}//end class
