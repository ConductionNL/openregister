<?php

declare(strict_types=1);

/**
 * Entity RBAC must be able to DENY.
 *
 * `MultiTenancyTrait::hasRbacPermission()` gated its whole organisation and
 * group check behind `isset($this->organisationService)`, returning **true**
 * when absent "for backward compatibility". No class using the trait ever
 * declared or injected that property — the only mentions in the codebase were
 * commented-out lines sitting next to `// REMOVED: Services should not be in
 * mappers.` — and `isset()` on an undeclared property is always false. So the
 * allow branch was the only reachable one: every authenticated non-admin was
 * granted every entity permission, and everything below it was dead code.
 * openregister#2833.
 *
 * The existing RegisterSchemaRbacTest documents this accurately and scopes
 * around it: it asserts `verifyRbacPermission()` is *called*, deliberately not
 * that it can *deny*. That is why nothing went red for as long as it did.
 *
 * This file supplies the missing half. Each test drives a real decision and
 * asserts the outcome, so the check cannot go dead again in the same way — a
 * guard that is merely invoked is indistinguishable from no guard at all.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The organisation-membership branch of entity RBAC.
 */
class MultiTenancyRbacDenialTest extends TestCase {
	/** @var OrganisationMapper&MockObject */
	private OrganisationMapper $organisationMapper;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	protected function setUp(): void {
		parent::setUp();
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}

	/**
	 * A mapper wired with the mocks above, signed in as a non-admin.
	 *
	 * @param string $uid the signed-in user id
	 *
	 * @return RegisterMapper the mapper under test
	 */
	private function mapperAsUser(string $uid): RegisterMapper {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		// Non-admin: isAdmin() is consulted through the group manager.
		$this->groupManager->method('isAdmin')->willReturn(false);

		return new RegisterMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(ContainerInterface::class),
			$this->organisationMapper,
			$this->userSession,
			$this->groupManager,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Ask the protected decision directly.
	 *
	 * @param RegisterMapper $mapper the mapper
	 *
	 * @return bool the decision
	 */
	private function decide(RegisterMapper $mapper): bool {
		$method = new ReflectionMethod(RegisterMapper::class, 'hasRbacPermission');
		$method->setAccessible(true);
		return (bool)$method->invoke($mapper, 'create', 'register');
	}

	/**
	 * An organisation the mapper will resolve, carrying a user list.
	 *
	 * @param array $userIds members
	 *
	 * @return Organisation the organisation
	 */
	private function organisationWith(array $userIds): Organisation {
		$org = new Organisation();
		$org->setUuid('org-uuid-1');
		// The entity stores the list as `users`; getUserIds() is the reader.
		$org->setUsers($userIds);
		return $org;
	}

	/**
	 * THE REGRESSION. A non-admin outside the active organisation is DENIED.
	 *
	 * Before #2833 this returned true — the check never reached a decision.
	 *
	 * @return void
	 */
	public function testANonAdminOutsideTheActiveOrganisationIsDenied(): void {
		$mapper = $this->mapperAsUser('outsider');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn('org-uuid-1');
		$this->organisationMapper->method('findByUuid')
			->willReturn($this->organisationWith(['alice', 'bob']));

		$this->assertFalse(
			$this->decide($mapper),
			'a user who is not a member of the active organisation must not be granted entity permissions'
		);
	}

	/**
	 * A non-admin who IS a member is not blocked by this branch.
	 *
	 * The counterpart matters as much as the denial: a check that refuses
	 * everyone is as broken as one that permits everyone, and would be caught
	 * only by someone losing access in production.
	 *
	 * @return void
	 */
	public function testAMemberOfTheActiveOrganisationPassesThisBranch(): void {
		$mapper = $this->mapperAsUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn('org-uuid-1');
		$this->organisationMapper->method('findByUuid')
			->willReturn($this->organisationWith(['alice', 'bob']));

		$this->assertTrue($this->decide($mapper));
	}

	/**
	 * An organisation that cannot be resolved is not a permission to proceed.
	 *
	 * @return void
	 */
	public function testAnUnresolvableOrganisationIsDenied(): void {
		$mapper = $this->mapperAsUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn('org-uuid-gone');
		$this->organisationMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('no such organisation'));

		$this->assertFalse(
			$this->decide($mapper),
			'a lookup failure must not be read as authorisation'
		);
	}

	/**
	 * An admin still short-circuits to allowed, ahead of any of this.
	 *
	 * @return void
	 */
	public function testAnAdminIsStillAllowed(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$mapper = new RegisterMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(ContainerInterface::class),
			$this->organisationMapper,
			$this->userSession,
			$this->groupManager,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertTrue($this->decide($mapper));
	}
}
