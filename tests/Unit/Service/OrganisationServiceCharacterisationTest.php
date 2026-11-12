<?php

/**
 * Characterisation of OrganisationService's cache reconstruction, default
 * organisation provisioning, active organisation lookup, membership changes
 * and organisation-admin check.
 *
 * Pins allowed AND refused outcomes of the membership and admin paths, and
 * every branch of the default organisation fallback chain, so the bodies can
 * be split into named steps without changing a result, an exception message
 * or a mapper call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Branches of the organisation service methods being split into named steps.
 *
 * @covers \OCA\OpenRegister\Service\OrganisationService
 */
class OrganisationServiceCharacterisationTest extends TestCase {

	private OrganisationMapper&MockObject $mapper;
	private IUserSession&MockObject $userSession;
	private ISession&MockObject $session;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks and clear the service's static caches.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper       = $this->createMock(OrganisationMapper::class);
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->session      = $this->createMock(ISession::class);
		$this->config       = $this->createMock(IConfig::class);
		$this->appConfig    = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager  = $this->createMock(IUserManager::class);
		$this->logger       = $this->createMock(LoggerInterface::class);

		$ref = new ReflectionClass(OrganisationService::class);
		$ref->getProperty('defaultOrgCache')->setValue(null, null);
		$ref->getProperty('defaultOrgCacheTs')->setValue(null, null);
		$ref->getProperty('userOrgsCache')->setValue(null, []);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param SettingsServiceInterface|null $settings The optional settings service.
	 *
	 * @return OrganisationService The service.
	 */
	private function service(?SettingsServiceInterface $settings=null): OrganisationService {
		return new OrganisationService(
			$this->mapper,
			$this->userSession,
			$this->session,
			$this->config,
			$this->appConfig,
			$this->groupManager,
			$this->userManager,
			$this->logger,
			$settings
		);
	}//end service()

	/**
	 * Call a private method.
	 *
	 * @param OrganisationService $service The service.
	 * @param string              $method  The method.
	 * @param array               $args    The arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(OrganisationService $service, string $method, array $args=[]): mixed {
		return (new ReflectionMethod(OrganisationService::class, $method))->invokeArgs($service, $args);
	}//end call()

	/**
	 * Sign in a user.
	 *
	 * @param string $uid The uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * An organisation.
	 *
	 * @param string $uuid  The uuid.
	 * @param array  $users The members.
	 *
	 * @return Organisation The organisation.
	 */
	private function org(string $uuid, array $users=[]): Organisation {
		$org = new Organisation();
		$org->setUuid($uuid);
		$org->setName('Org ' . $uuid);
		$org->setUsers($users);
		return $org;
	}//end org()

	// ---------------------------------------------------------------- reconstructOrganisationFromCache

	/**
	 * Every cached field is restored, string timestamps parsed.
	 *
	 * @return void
	 */
	public function testReconstructRestoresEveryFieldAndParsesStrings(): void {
		$org = $this->call(
			$this->service(),
			'reconstructOrganisationFromCache',
			[[
				'id' => 7, 'uuid' => 'u-1', 'name' => 'N', 'description' => 'D', 'owner' => 'o',
				'users' => ['a', 'b'], 'created' => '2024-01-02 03:04:05', 'updated' => '2024-02-03 04:05:06',
			]]
		);

		$this->assertSame(7, $org->getId());
		$this->assertSame('u-1', $org->getUuid());
		$this->assertSame('N', $org->getName());
		$this->assertSame('D', $org->getDescription());
		$this->assertSame('o', $org->getOwner());
		$this->assertSame(['a', 'b'], $org->getUsers());
		$this->assertSame('2024-01-02 03:04:05', $org->getCreated()->format('Y-m-d H:i:s'));
		$this->assertSame('2024-02-03 04:05:06', $org->getUpdated()->format('Y-m-d H:i:s'));
	}//end testReconstructRestoresEveryFieldAndParsesStrings()

	/**
	 * DateTime timestamps are kept as they are.
	 *
	 * @return void
	 */
	public function testReconstructKeepsDateTimes(): void {
		$created = new DateTime('2023-05-06 07:08:09');
		$updated = new DateTime('2023-06-07 08:09:10');

		$org = $this->call($this->service(), 'reconstructOrganisationFromCache', [['created' => $created, 'updated' => $updated]]);

		$this->assertSame($created, $org->getCreated());
		$this->assertSame($updated, $org->getUpdated());
	}//end testReconstructKeepsDateTimes()

	/**
	 * Missing, null and non-date timestamp values leave the organisation untouched.
	 *
	 * @return void
	 */
	public function testReconstructSkipsMissingNullAndForeignValues(): void {
		$fresh = new Organisation();

		$org = $this->call($this->service(), 'reconstructOrganisationFromCache', [['name' => null, 'created' => 12345, 'updated' => ['x']]]);

		$this->assertEquals($fresh->jsonSerialize(), $org->jsonSerialize());
		$this->assertSame([], $org->getUpdatedFields());
	}//end testReconstructSkipsMissingNullAndForeignValues()

	// ---------------------------------------------------------------- fetchDefaultOrganisationFromDatabase

	/**
	 * A configured default that exists is returned as it is.
	 *
	 * @return void
	 */
	public function testAConfiguredDefaultThatExistsIsReturnedUntouched(): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $key === 'defaultOrganisation' ? 'cfg-uuid' : $default
		);
		$existing = $this->org('cfg-uuid');
		$this->mapper->expects($this->once())->method('findByUuid')->with('cfg-uuid')->willReturn($existing);
		$this->mapper->expects($this->never())->method('update');
		$this->mapper->expects($this->never())->method('save');

		$this->assertSame($existing, $this->call($this->service(), 'fetchDefaultOrganisationFromDatabase'));
	}//end testAConfiguredDefaultThatExistsIsReturnedUntouched()

	/**
	 * The settings service's default wins over the app config.
	 *
	 * @return void
	 */
	public function testTheSettingsServiceDefaultWins(): void {
		$this->appConfig->method('getValueString')->willReturn('cfg-uuid');
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getDefaultOrganisationUuid')->willReturn('settings-uuid');
		$settings->expects($this->never())->method('setDefaultOrganisationUuid');
		$existing = $this->org('settings-uuid');
		$this->mapper->expects($this->once())->method('findByUuid')->with('settings-uuid')->willReturn($existing);

		$this->assertSame($existing, $this->call($this->service($settings), 'fetchDefaultOrganisationFromDatabase'));
	}//end testTheSettingsServiceDefaultWins()

	/**
	 * A configured default that is gone is replaced, recorded, and given its admins.
	 *
	 * @return void
	 */
	public function testAMissingConfiguredDefaultIsReplacedAndGivenItsAdmins(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getDefaultOrganisationUuid')->willReturn('gone-uuid');
		$settings->expects($this->once())->method('setDefaultOrganisationUuid')->with('new-uuid');
		$this->appConfig->expects($this->atLeastOnce())->method('setValueString');

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('root');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin]);
		$this->groupManager->method('get')->with('admin')->willReturn($group);

		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->mapper->expects($this->once())->method('save')->willReturnCallback(
			static function (Organisation $org): Organisation {
				$org->setUuid('new-uuid');
				return $org;
			}
		);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->call($this->service($settings), 'fetchDefaultOrganisationFromDatabase');

		$this->assertSame('new-uuid', $result->getUuid());
		$this->assertSame('Default Organisation', $result->getName());
		$this->assertContains('root', $result->getUsers());
	}//end testAMissingConfiguredDefaultIsReplacedAndGivenItsAdmins()

	/**
	 * With no default configured anywhere a new one is created and updated once.
	 *
	 * @return void
	 */
	public function testNoConfiguredDefaultCreatesOne(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->groupManager->method('get')->willReturn(null);
		$this->mapper->expects($this->never())->method('findByUuid');
		$this->mapper->expects($this->once())->method('save')->willReturnCallback(
			static function (Organisation $org): Organisation {
				$org->setUuid('fresh-uuid');
				return $org;
			}
		);
		$this->appConfig->expects($this->atLeastOnce())->method('setValueString')->with('openregister', 'defaultOrganisation', 'fresh-uuid');
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->call($this->service(), 'fetchDefaultOrganisationFromDatabase');

		$this->assertSame('Default Organisation', $result->getName());
	}//end testNoConfiguredDefaultCreatesOne()

	/**
	 * Any other failure is logged once as an error and rethrown.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndRethrown(): void {
		$this->appConfig->method('getValueString')->willReturn('cfg-uuid');
		$this->mapper->method('findByUuid')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'[OrganisationService] Failed to fetch or create default organisation',
			$this->callback(static fn (array $context): bool => $context['error'] === 'db down')
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->call($this->service(), 'fetchDefaultOrganisationFromDatabase');
	}//end testAFailureIsLoggedAndRethrown()

	// ---------------------------------------------------------------- getActiveOrganisation

	/**
	 * Nobody signed in has no active organisation.
	 *
	 * @return void
	 */
	public function testNoUserHasNoActiveOrganisation(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->session->expects($this->never())->method('get');

		$this->assertNull($this->service()->getActiveOrganisation());
	}//end testNoUserHasNoActiveOrganisation()

	/**
	 * A fresh session entry is served without touching the database.
	 *
	 * @return void
	 */
	public function testAFreshCacheEntryIsServed(): void {
		$this->signIn('alice');
		$this->session->method('get')->willReturnMap(
			[
				['openregister_active_organisation_alice', ['uuid' => 'cached', 'name' => 'Cached']],
				['openregister_active_organisation_timestamp_alice', time()],
			]
		);
		$this->config->expects($this->never())->method('getUserValue');

		$this->assertSame('cached', $this->service()->getActiveOrganisation()->getUuid());
	}//end testAFreshCacheEntryIsServed()

	/**
	 * An expired, or half-present, session entry falls through to the database and is re-cached.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}> The cached entry and timestamp.
	 */
	public static function staleEntries(): array {
		return [
			'expired'      => [['uuid' => 'cached'], time() - 901],
			'no timestamp' => [['uuid' => 'cached'], null],
			'no entry'     => [null, time()],
		];
	}//end staleEntries()

	/**
	 * A stale entry is not served.
	 *
	 * @param mixed $entry     The cached entry.
	 * @param mixed $timestamp The cached timestamp.
	 *
	 * @dataProvider staleEntries
	 *
	 * @return void
	 */
	public function testAStaleCacheEntryFallsThroughToTheDatabase(mixed $entry, mixed $timestamp): void {
		$this->signIn('alice');
		$this->session->method('get')->willReturnMap(
			[
				['openregister_active_organisation_alice', $entry],
				['openregister_active_organisation_timestamp_alice', $timestamp],
			]
		);
		$this->config->method('getUserValue')->willReturn('');
		$this->session->expects($this->atLeastOnce())->method('set');

		$result = $this->service()->getActiveOrganisation(preloadedOrgs: [$this->org('from-db', ['alice'])]);

		$this->assertSame('from-db', $result->getUuid());
	}//end testAStaleCacheEntryFallsThroughToTheDatabase()

	// ---------------------------------------------------------------- joinOrganisation

	/**
	 * Joining needs a signed-in user.
	 *
	 * @return void
	 */
	public function testJoinRefusesAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->mapper->expects($this->never())->method('addUserToOrganisation');
		$this->expectExceptionMessage('No user logged in');

		$this->service()->joinOrganisation('org-1');
	}//end testJoinRefusesAnonymous()

	/**
	 * Adding a user who does not exist is refused before the mapper is asked.
	 *
	 * @return void
	 */
	public function testJoinRefusesAnUnknownTargetUser(): void {
		$this->signIn('alice');
		$this->userManager->method('get')->with('ghost')->willReturn(null);
		$this->mapper->expects($this->never())->method('addUserToOrganisation');
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Target user not found');

		$this->service()->joinOrganisation('org-1', 'ghost');
	}//end testJoinRefusesAnUnknownTargetUser()

	/**
	 * A user joins themselves without a user lookup, and their list cache is dropped.
	 *
	 * @return void
	 */
	public function testJoinSelf(): void {
		$this->signIn('alice');
		$this->userManager->expects($this->never())->method('get');
		$this->mapper->expects($this->once())->method('addUserToOrganisation')->with('org-1', 'alice');
		$this->session->expects($this->once())->method('remove')->with('openregister_user_organisations_alice');

		$this->assertTrue($this->service()->joinOrganisation('org-1', 'alice'));
	}//end testJoinSelf()

	/**
	 * An existing other user is added and their cache dropped.
	 *
	 * @return void
	 */
	public function testJoinAnotherExistingUser(): void {
		$this->signIn('alice');
		$this->userManager->method('get')->with('bob')->willReturn($this->createMock(IUser::class));
		$this->mapper->expects($this->once())->method('addUserToOrganisation')->with('org-1', 'bob');
		$this->session->expects($this->once())->method('remove')->with('openregister_user_organisations_bob');

		$this->assertTrue($this->service()->joinOrganisation('org-1', 'bob'));
	}//end testJoinAnotherExistingUser()

	/**
	 * Joining an organisation that does not exist is 'Organisation not found'.
	 *
	 * @return void
	 */
	public function testJoinAMissingOrganisation(): void {
		$this->signIn('alice');
		$this->mapper->method('addUserToOrganisation')->willThrowException(new DoesNotExistException('x'));
		$this->session->expects($this->never())->method('remove');
		$this->expectExceptionMessage('Organisation not found');

		$this->service()->joinOrganisation('org-1');
	}//end testJoinAMissingOrganisation()

	// ---------------------------------------------------------------- leaveOrganisation

	/**
	 * Leaving needs a signed-in user.
	 *
	 * @return void
	 */
	public function testLeaveRefusesAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->mapper->expects($this->never())->method('removeUserFromOrganisation');
		$this->expectExceptionMessage('No user logged in');

		$this->service()->leaveOrganisation('org-1');
	}//end testLeaveRefusesAnonymous()

	/**
	 * A user cannot leave their last organisation.
	 *
	 * @return void
	 */
	public function testLeaveRefusesTheLastOrganisation(): void {
		$this->signIn('alice');
		$this->mapper->method('findByUserId')->with('alice')->willReturn([$this->org('org-1', ['alice'])]);
		$this->mapper->expects($this->never())->method('removeUserFromOrganisation');
		$this->expectExceptionMessage('Cannot leave last organisation');

		$this->service()->leaveOrganisation('org-1');
	}//end testLeaveRefusesTheLastOrganisation()

	/**
	 * Removing another user skips the last-organisation check and drops their cache.
	 *
	 * @return void
	 */
	public function testLeaveForAnotherUserSkipsTheLastOrganisationCheck(): void {
		$this->signIn('alice');
		$this->mapper->expects($this->never())->method('findByUserId');
		$this->mapper->expects($this->once())->method('removeUserFromOrganisation')->with('org-1', 'bob');
		$this->session->method('get')->willReturnMap(
			[
				['openregister_active_organisation_alice', ['uuid' => 'other']],
				['openregister_active_organisation_timestamp_alice', time()],
			]
		);
		$this->config->expects($this->never())->method('deleteUserValue');
		$this->session->expects($this->once())->method('remove')->with('openregister_user_organisations_bob');

		$this->assertTrue($this->service()->leaveOrganisation('org-1', 'bob'));
	}//end testLeaveForAnotherUserSkipsTheLastOrganisationCheck()

	/**
	 * Leaving the active organisation clears it and picks another.
	 *
	 * @return void
	 */
	public function testLeavingTheActiveOrganisationResetsIt(): void {
		$this->signIn('alice');
		$this->mapper->method('findByUserId')->willReturn([$this->org('org-1', ['alice']), $this->org('org-2', ['alice'])]);
		$this->mapper->expects($this->once())->method('removeUserFromOrganisation')->with('org-1', 'alice');
		$this->session->method('get')->willReturnMap(
			[
				['openregister_active_organisation_alice', ['uuid' => 'org-1']],
				['openregister_active_organisation_timestamp_alice', time()],
			]
		);
		$this->config->expects($this->once())->method('deleteUserValue')->with('alice', 'openregister', 'active_organisation');
		$this->session->expects($this->atLeastOnce())->method('remove');

		$this->assertTrue($this->service()->leaveOrganisation('org-1'));
	}//end testLeavingTheActiveOrganisationResetsIt()

	/**
	 * Leaving an organisation that does not exist is 'Organisation not found'.
	 *
	 * @return void
	 */
	public function testLeaveAMissingOrganisation(): void {
		$this->signIn('alice');
		$this->mapper->method('removeUserFromOrganisation')->willThrowException(new DoesNotExistException('x'));
		$this->expectExceptionMessage('Organisation not found');

		$this->service()->leaveOrganisation('org-1', 'bob');
	}//end testLeaveAMissingOrganisation()

	// ---------------------------------------------------------------- isOrganisationAdmin

	/**
	 * Nobody signed in, or an empty user id, administers nothing.
	 *
	 * @return void
	 */
	public function testNoUserIsNoAdmin(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->groupManager->expects($this->never())->method('isAdmin');

		$this->assertFalse($this->service()->isOrganisationAdmin('org-1'));
		$this->assertFalse($this->service()->isOrganisationAdmin('org-1', ''));
	}//end testNoUserIsNoAdmin()

	/**
	 * A Nextcloud admin administers every organisation without a lookup.
	 *
	 * @return void
	 */
	public function testANextcloudAdminIsAdmin(): void {
		$this->signIn('root');
		$this->groupManager->method('isAdmin')->with('root')->willReturn(true);
		$this->mapper->expects($this->never())->method('findByUuid');

		$this->assertTrue($this->service()->isOrganisationAdmin('org-1'));
	}//end testANextcloudAdminIsAdmin()

	/**
	 * The owner administers it; another member does not; a missing organisation has no admin.
	 *
	 * @return void
	 */
	public function testOwnerIsAdminAndOthersAreNot(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$org = $this->org('org-1', ['alice', 'bob']);
		$org->setOwner('alice');
		$this->mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($org): Organisation {
				if ($uuid === 'org-1') {
					return $org;
				}

				throw new DoesNotExistException('missing');
			}
		);

		$service = $this->service();
		$this->assertTrue($service->isOrganisationAdmin('org-1', 'alice'));
		$this->assertFalse($service->isOrganisationAdmin('org-1', 'bob'));
		$this->assertFalse($service->isOrganisationAdmin('org-9', 'alice'));
	}//end testOwnerIsAdminAndOthersAreNot()

	/**
	 * Without a user id the signed-in user is checked.
	 *
	 * @return void
	 */
	public function testTheSignedInUserIsCheckedByDefault(): void {
		$this->signIn('alice');
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$org = $this->org('org-1');
		$org->setOwner('alice');
		$this->mapper->method('findByUuid')->willReturn($org);

		$this->assertTrue($this->service()->isOrganisationAdmin('org-1'));
	}//end testTheSignedInUserIsCheckedByDefault()
}//end class
