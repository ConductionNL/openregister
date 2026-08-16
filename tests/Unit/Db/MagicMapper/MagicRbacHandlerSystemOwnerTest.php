<?php

/**
 * OpenRegister - MagicRbacHandler system-owner carve-out test
 *
 * Verifies the openregister#1617 system-row visibility carve-out: rows
 * owned by the configured system identifier are visible to admins and to
 * users in any group listed under `openregister.systemReaderGroups`, but
 * remain hidden from other non-admin users (no leak).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the system-row visibility carve-out added to
 * {@see MagicRbacHandler::applyRbacFilters()} (and its raw-SQL twin
 * {@see MagicRbacHandler::buildRbacConditionsSql()}) for openregister#1617.
 *
 * Admin users hit the bypass at the top of both methods and never reach the
 * carve-out; the new code only fires for non-admin users in a configured
 * reader group. These tests pin that contract from the outside.
 */
class MagicRbacHandlerSystemOwnerTest extends TestCase {

	/**
	 * IUserSession mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * IGroupManager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Container mock — used to lazy-load OrganisationService.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * OrganisationService mock — exposes system identifier + reader groups.
	 *
	 * @var OrganisationService&MockObject
	 */
	private OrganisationService $organisationService;

	/**
	 * System under test.
	 *
	 * @var MagicRbacHandler
	 */
	private MagicRbacHandler $handler;

	/**
	 * Build mocks + wire container to return the OrganisationService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->organisationService = $this->createMock(OrganisationService::class);

		$this->container
			->method('get')
			->willReturnCallback(
				function (string $id) {
					if ($id === OrganisationService::class) {
						return $this->organisationService;
					}

					throw new \RuntimeException('Unexpected container::get(' . $id . ')');
				}
			);

		$this->handler = new MagicRbacHandler(
			$this->userSession,
			$this->groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ConditionMatcher::class),
			$this->container,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Helper — set up a logged-in user with the given groups.
	 *
	 * @param string $uid User identifier.
	 * @param array $groups Group IDs the user belongs to.
	 *
	 * @return void
	 */
	private function mockUser(string $uid, array $groups): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}//end mockUser()

	/**
	 * Helper — build a schema with a single conditional authorization rule
	 * for the read action.
	 *
	 * @return Schema The schema with a non-empty auth.read array.
	 */
	private function schemaWithReadRule(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setTitle('Test');
		$schema->setAuthorization(['read' => ['some-group']]);
		return $schema;
	}//end schemaWithReadRule()

	/**
	 * Helper — build a query-builder mock whose expression builder produces
	 * a deterministic, capturable SQL-ish fragment for each `eq()` call.
	 *
	 * Captures every emitted OR-clause via the spy returned by reference.
	 *
	 * @param array $captured Output array receiving each "column={value}" string.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function mockQueryBuilder(array &$captured): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr
			->method('eq')
			->willReturnCallback(
				function (string $col, mixed $param) use (&$captured) {
					$captured[] = $col . '=' . (string)$param;
					return $col . '=' . (string)$param;
				}
			);
		$expr
			->method('orX')
			->willReturnCallback(
				function (...$args): ICompositeExpression {
					// Return a minimal real ICompositeExpression implementation —
					// mocked __toString is forbidden by PHPUnit, and the type
					// signature on orX() must be satisfied at runtime.
					return new class implements ICompositeExpression {
						public function addMultiple(array $parts = []): ICompositeExpression {
							return $this;
						}

						public function add($part): ICompositeExpression {
							return $this;
						}

						public function count(): int {
							return 0;
						}

						public function getType(): string {
							return 'OR';
						}
					};
				}
			);

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		// andWhere is called once at the end - no return value capture needed.
		$qb->method('andWhere');

		return $qb;
	}//end mockQueryBuilder()

	/**
	 * Non-admin user in a configured systemReaderGroups group gets a
	 * `_owner = '__system__'` OR-condition appended.
	 *
	 * @return void
	 */
	public function testReaderGroupGetsSystemOwnerCondition(): void {
		$this->mockUser(uid: 'carol', groups: ['log-readers']);

		$this->organisationService
			->method('getSystemReaderGroups')
			->willReturn(['log-readers']);
		$this->organisationService
			->method('getSystemUserId')
			->willReturn('__system__');

		$captured = [];
		$qb = $this->mockQueryBuilder($captured);

		$this->handler->applyRbacFilters(
			qb: $qb,
			schema: $this->schemaWithReadRule(),
			action: 'read'
		);

		$this->assertContains(
			't._owner=__system__',
			$captured,
			'Reader-group member must receive the system-owner OR-condition.'
		);
	}//end testReaderGroupGetsSystemOwnerCondition()

	/**
	 * Non-admin user NOT in any reader group does NOT receive the
	 * system-owner condition — no visibility leak.
	 *
	 * @return void
	 */
	public function testNonReaderUserDoesNotGetSystemOwnerCondition(): void {
		$this->mockUser(uid: 'bob', groups: ['users']);

		$this->organisationService
			->method('getSystemReaderGroups')
			->willReturn(['log-readers']);
		// getSystemUserId is NOT expected to be called - assert that.
		$this->organisationService
			->expects($this->never())
			->method('getSystemUserId');

		$captured = [];
		$qb = $this->mockQueryBuilder($captured);

		$this->handler->applyRbacFilters(
			qb: $qb,
			schema: $this->schemaWithReadRule(),
			action: 'read'
		);

		$this->assertNotContains(
			't._owner=__system__',
			$captured,
			'Non-reader users must NOT receive the system-owner OR-condition.'
		);
	}//end testNonReaderUserDoesNotGetSystemOwnerCondition()

	/**
	 * Reader-group config that's empty means no system-owner condition
	 * even when the user has groups.
	 *
	 * @return void
	 */
	public function testEmptyReaderGroupsConfigSkipsCarveOut(): void {
		$this->mockUser(uid: 'bob', groups: ['log-readers']);

		$this->organisationService
			->method('getSystemReaderGroups')
			->willReturn([]);

		$captured = [];
		$qb = $this->mockQueryBuilder($captured);

		$this->handler->applyRbacFilters(
			qb: $qb,
			schema: $this->schemaWithReadRule(),
			action: 'read'
		);

		$this->assertNotContains(
			't._owner=__system__',
			$captured,
			'Empty reader-groups config must skip the carve-out entirely.'
		);
	}//end testEmptyReaderGroupsConfigSkipsCarveOut()

	/**
	 * Admin users hit the bypass at the top and never reach the carve-out -
	 * no system-owner condition is emitted, but the test also verifies the
	 * method returned without crashing on the admin path.
	 *
	 * @return void
	 */
	public function testAdminUserBypassesBeforeCarveOut(): void {
		$this->mockUser(uid: 'admin1', groups: ['admin']);

		// Container should NOT be touched - admin bypasses before OrganisationService is consulted.
		$this->organisationService
			->expects($this->never())
			->method('getSystemReaderGroups');

		$captured = [];
		$qb = $this->mockQueryBuilder($captured);

		$this->handler->applyRbacFilters(
			qb: $qb,
			schema: $this->schemaWithReadRule(),
			action: 'read'
		);

		// No conditions captured at all - admin bypass returns immediately.
		$this->assertSame(
			[],
			$captured,
			'Admin must bypass before any conditions are emitted.'
		);
	}//end testAdminUserBypassesBeforeCarveOut()
}//end class
