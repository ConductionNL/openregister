<?php

/**
 * UserService profile golden tests
 *
 * Pins what buildUserDataArray() returns and what updateUserProperties() writes,
 * branch by branch: optional IUser methods present or absent, quota maths and
 * fallbacks, language/locale defaults, AccountManager failure fallbacks, the role
 * fallback keys, and every capability guard on the standard and profile setters
 * (allowed AND refused), including the order of the writes.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\UserService;
use OCA\OpenRegister\Service\User\UserDirectory;
use OCA\OpenRegister\Service\User\UserPlatform;
use OCA\OpenRegister\Service\User\UserStorage;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An IUser that also carries the optional methods some backends implement.
 */
interface UserServiceGoldenRichUser extends IUser {

	/**
	 * Whether the e-mail address is verified.
	 *
	 * @return mixed
	 */
	public function getEmailVerified();

	/**
	 * The avatar scope.
	 *
	 * @return mixed
	 */
	public function getAvatarScope();

	/**
	 * Whether the mail address may be changed.
	 *
	 * @return mixed
	 */
	public function canChangeMailAddress();

	/**
	 * The used space.
	 *
	 * @return mixed
	 */
	public function getUsedSpace();

	/**
	 * The language.
	 *
	 * @return mixed
	 */
	public function getLanguage();

	/**
	 * The locale.
	 *
	 * @return mixed
	 */
	public function getLocale();

	/**
	 * Set the language.
	 *
	 * @param mixed $language Language
	 *
	 * @return mixed
	 */
	public function setLanguage($language);

	/**
	 * Set the locale.
	 *
	 * @param mixed $locale Locale
	 *
	 * @return mixed
	 */
	public function setLocale($locale);
}//end interface

/**
 * Golden tests for the UserService profile read and write paths.
 */
class UserServiceProfileGoldenTest extends TestCase {

	/**
	 * Stored user config values, keyed "app/key".
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Every write, in order.
	 *
	 * @var list<string>
	 */
	private array $writes = [];

	/**
	 * Logged warnings.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Config mock.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Account manager mock.
	 *
	 * @var IAccountManager&MockObject
	 */
	private IAccountManager&MockObject $accountManager;

	/**
	 * Organisation service mock.
	 *
	 * @var OrganisationService&MockObject
	 */
	private OrganisationService&MockObject $organisationService;

	/**
	 * DB mock.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * L10N factory mock.
	 *
	 * @var IFactory&MockObject
	 */
	private IFactory&MockObject $l10nFactory;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = '') => ($this->store[$app.'/'.$key] ?? $default)
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, $value): void {
				$this->writes[] = "config:$uid:$app/$key=$value";
			}
		);
		$this->groupManager        = $this->createMock(IGroupManager::class);
		$this->accountManager      = $this->createMock(IAccountManager::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->db          = $this->createMock(IDBConnection::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
	}//end setUp()

	/**
	 * Build the service with a logger that records warnings.
	 *
	 * @return UserService
	 */
	private function service(): UserService {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		return new UserService(
			directory: new UserDirectory(
				userManager: $this->createMock(IUserManager::class),
				groupManager: $this->groupManager,
				accountManager: $this->accountManager,
				avatarManager: $this->createMock(IAvatarManager::class)
			),
			platform: new UserPlatform(
				userSession: $this->createMock(IUserSession::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				l10nFactory: $this->l10nFactory,
				logger: $logger
			),
			storage: new UserStorage(
				config: $this->config,
				db: $this->db,
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				secureRandom: $this->createMock(ISecureRandom::class)
			),
			organisationService: $this->organisationService
		);
	}//end service()

	/**
	 * An account whose properties come from a map; absent ones throw.
	 *
	 * @param array<string, string> $values Property values
	 *
	 * @return IAccount&MockObject
	 */
	private function account(array $values): IAccount&MockObject {
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->willReturnCallback(
			function (string $name) use ($values) {
				if (array_key_exists($name, $values) === false) {
					throw new PropertyDoesNotExistException($name);
				}

				$property = $this->createMock(IAccountProperty::class);
				$property->method('getValue')->willReturn($values[$name]);
				$property->method('setValue')->willReturnCallback(
					function ($value) use ($name, $property) {
						$this->writes[] = "account:set:$name=$value";
						return $property;
					}
				);
				return $property;
			}
		);
		$account->method('setProperty')->willReturnCallback(
			function (string $name, string $value, string $scope, string $verified) use ($account) {
				$this->writes[] = "account:create:$name=$value:$scope:$verified";
				return $account;
			}
		);
		return $account;
	}//end account()

	/**
	 * A user carrying every optional method.
	 *
	 * @param array<string, mixed> $values Return values by method name
	 *
	 * @return UserServiceGoldenRichUser&MockObject
	 */
	private function richUser(array $values=[]): UserServiceGoldenRichUser&MockObject {
		$values = array_merge(
			[
				'getUID'                => 'jan',
				'getDisplayName'        => 'Jan',
				'getEMailAddress'       => 'jan@example.org',
				'getEmailVerified'      => true,
				'isEnabled'             => true,
				'getQuota'              => '1000',
				'getUsedSpace'          => 250,
				'getAvatarScope'        => 'private',
				'getLastLogin'          => 42,
				'getBackendClassName'   => 'Database',
				'canChangeDisplayName'  => true,
				'canChangeMailAddress'  => false,
				'canChangePassword'     => true,
				'canChangeAvatar'       => false,
				'getLanguage'           => 'nl',
				'getLocale'             => '',
			],
			$values
		);
		$user   = $this->createMock(UserServiceGoldenRichUser::class);
		foreach ($values as $method => $value) {
			if ($value instanceof \Throwable) {
				$user->method($method)->willThrowException($value);
				continue;
			}

			$user->method($method)->willReturn($value);
		}

		foreach (['setDisplayName', 'setEMailAddress', 'setPassword', 'setLanguage', 'setLocale'] as $setter) {
			$user->method($setter)->willReturnCallback(
				function ($value) use ($setter) {
					$this->writes[] = "user:$setter=$value";
					return true;
				}
			);
		}

		return $user;
	}//end richUser()

	/**
	 * A plain IUser (no optional methods).
	 *
	 * @return IUser&MockObject
	 */
	private function plainUser(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('piet');
		$user->method('getDisplayName')->willReturn('Piet');
		$user->method('getEMailAddress')->willReturn(null);
		$user->method('isEnabled')->willReturn(false);
		$user->method('getQuota')->willReturn('none');
		$user->method('getLastLogin')->willReturn(0);
		$user->method('getBackendClassName')->willReturn('LDAP');
		$user->method('canChangeDisplayName')->willReturn(false);
		$user->method('canChangePassword')->willReturn(false);
		$user->method('canChangeAvatar')->willReturn(true);
		foreach (['setDisplayName', 'setEMailAddress', 'setPassword'] as $setter) {
			$user->method($setter)->willReturnCallback(
				function ($value) use ($setter) {
					$this->writes[] = "user:$setter=$value";
					return true;
				}
			);
		}

		return $user;
	}//end plainUser()

	/**
	 * A rich user with every source populated gives the full pinned array.
	 *
	 * @return void
	 */
	public function testBuildUserDataArrayRichUser(): void {
		$admin = $this->createMock(IGroup::class);
		$admin->method('getGID')->willReturn('admin');
		$staff = $this->createMock(IGroup::class);
		$staff->method('getGID')->willReturn('staff');
		$this->groupManager->method('getUserGroups')->willReturn(['x' => $admin, 'y' => $staff]);
		$this->accountManager->method('getAccount')->willReturn(
			$this->account([IAccountManager::PROPERTY_PHONE => '0612', IAccountManager::PROPERTY_ROLE => ''])
		);
		$this->store = ['core/firstName' => 'Jan', 'core/organisation' => 'org-1', 'core/functie' => 'Archivaris'];
		$this->organisationService->expects($this->once())->method('getUserOrganisationStats')->willReturn(
			['total' => 2, 'active' => ['name' => 'A', 'uuid' => 'u1'], 'results' => [['name' => 'A'], ['uuid' => 'u2']]]
		);
		$service = $this->service();

		$expected = [
			'uid'                 => 'jan',
			'displayName'         => 'Jan',
			'email'               => 'jan@example.org',
			'emailVerified'       => true,
			'enabled'             => true,
			'quota'               => ['free' => '1000', 'used' => 250, 'total' => '1000', 'relative' => 25.0],
			'avatarScope'         => 'private',
			'lastLogin'           => 42,
			'backend'             => 'Database',
			'subadmin'            => [],
			'groups'              => ['admin', 'staff'],
			'language'            => 'nl',
			'locale'              => 'nl_NL',
			'backendCapabilities' => ['displayName' => true, 'email' => false, 'password' => true, 'avatar' => false],
			'phone'               => '0612',
			'firstName'           => 'Jan',
			'lastName'            => null,
			'middleName'          => null,
			'organisation'        => 'org-1',
			'role'                => 'Archivaris',
			'organisations'       => [
				'active'    => ['name' => 'A', 'uuid' => 'u1', 'naam' => 'A'],
				'all'       => [['name' => 'A', 'naam' => 'A'], ['uuid' => 'u2', 'naam' => null]],
				'total'     => 2,
				'available' => true,
			],
		];
		$this->assertSame($expected, $service->buildUserDataArray($this->richUser()));
		// Second call reuses the cached organisation stats (expects once above).
		$this->assertSame($expected, $service->buildUserDataArray($this->richUser()));
	}//end testBuildUserDataArrayRichUser()

	/**
	 * A plain user with failing sources falls back everywhere.
	 *
	 * @return void
	 */
	public function testBuildUserDataArrayPlainUserFallbacks(): void {
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->accountManager->method('getAccount')->willThrowException(new \RuntimeException('no account'));
		$this->store = ['settings/phone' => '0101', 'settings/website' => '', 'settings/twitter' => '@piet', 'core/role' => 'Beheerder', 'core/functie' => 'Oud'];
		$this->organisationService->method('getUserOrganisationStats')->willThrowException(new \RuntimeException('down'));
		$this->db->method('getQueryBuilder')->willThrowException(new \Error('no db'));

		$expected = [
			'uid'                 => 'piet',
			'displayName'         => 'Piet',
			'email'               => null,
			'emailVerified'       => null,
			'enabled'             => false,
			'quota'               => ['free' => 'none', 'used' => 0, 'total' => 'none', 'relative' => 0],
			'avatarScope'         => 'contacts',
			'lastLogin'           => 0,
			'backend'             => 'LDAP',
			'subadmin'            => [],
			'groups'              => [],
			'language'            => '',
			'locale'              => '',
			'backendCapabilities' => ['displayName' => false, 'email' => false, 'password' => false, 'avatar' => true],
			'phone'               => '0101',
			'twitter'             => '@piet',
			'firstName'           => null,
			'lastName'            => null,
			'middleName'          => null,
			'role'                => 'Beheerder',
			'organisations'       => ['active' => null, 'all' => [], 'total' => 0, 'available' => true],
		];
		$this->assertSame($expected, $this->service()->buildUserDataArray($this->plainUser()));
		// The first quota warning depends on this process's memory use (the
		// memory guard), so it is pinned as one of the two possible texts.
		$warnings = array_values(array_unique($this->warnings));
		$this->assertContains(
			$warnings[0],
			[
				'[UserService] Memory-safe quota calculation failed for user: piet',
				'[UserService] Memory usage too high for quota calculation',
			]
		);
		$this->assertSame(
			['[UserService] AccountManager failed for user: piet', 'Failed to get user organisation stats: down'],
			array_slice($warnings, 1)
		);
	}//end testBuildUserDataArrayPlainUserFallbacks()

	/**
	 * Quota and language/locale variants, as [quota, locale-language] pairs.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}>
	 */
	public static function quotaAndLocaleCases(): array {
		return [
			'zero quota'                 => [
				['getQuota' => '0'],
				['free' => '0', 'used' => 250, 'total' => '0', 'relative' => 0],
				'nl',
				'nl_NL',
			],
			'unlimited quota'            => [
				['getQuota' => 'unlimited', 'getLocale' => 'de_DE'],
				['free' => 'unlimited', 'used' => 250, 'total' => 'unlimited', 'relative' => 0],
				'nl',
				'de_DE',
			],
			'non numeric quota'          => [
				['getQuota' => '5 GB'],
				['free' => '5 GB', 'used' => 250, 'total' => '5 GB', 'relative' => 0],
				'nl',
				'nl_NL',
			],
			'used space throws'          => [
				['getUsedSpace' => new \RuntimeException('boom')],
				['free' => '1000', 'used' => 0, 'total' => '1000', 'relative' => 0.0],
				'nl',
				'nl_NL',
			],
			'quota throws'               => [
				['getQuota' => new \RuntimeException('boom')],
				['free' => 'none', 'used' => 0, 'total' => 'none', 'relative' => 0],
				'nl',
				'nl_NL',
			],
			'english default locale'     => [
				['getLanguage' => 'en'],
				['free' => '1000', 'used' => 250, 'total' => '1000', 'relative' => 25.0],
				'en',
				'en_US',
			],
			'language from factory'      => [
				['getLanguage' => ''],
				['free' => '1000', 'used' => 250, 'total' => '1000', 'relative' => 25.0],
				'fr',
				'fr_FR',
			],
		];
	}//end quotaAndLocaleCases()

	/**
	 * Quota maths/fallbacks and language/locale defaults.
	 *
	 * @param array<string, mixed> $userValues     User overrides
	 * @param array<string, mixed> $expectedQuota  Pinned quota block
	 * @param string               $expectedLang   Pinned language
	 * @param string               $expectedLocale Pinned locale
	 *
	 * @dataProvider quotaAndLocaleCases
	 *
	 * @return void
	 */
	public function testQuotaAndLocale(array $userValues, array $expectedQuota, string $expectedLang, string $expectedLocale): void {
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->accountManager->method('getAccount')->willReturn($this->account([]));
		$this->l10nFactory->method('findLanguage')->willReturn('fr');
		$this->db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no db'));

		$data = $this->service()->buildUserDataArray($this->richUser($userValues));
		$this->assertSame($expectedQuota, $data['quota']);
		$this->assertSame($expectedLang, $data['language']);
		$this->assertSame($expectedLocale, $data['locale']);
	}//end testQuotaAndLocale()

	/**
	 * The language is empty and the factory finds none: locale stays as the user had it.
	 *
	 * @return void
	 */
	public function testEmptyLanguageKeepsEmptyLocale(): void {
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->accountManager->method('getAccount')->willReturn($this->account([]));
		$this->l10nFactory->method('findLanguage')->willReturn('');

		$data = $this->service()->buildUserDataArray($this->richUser(['getLanguage' => '']));
		$this->assertSame('', $data['language']);
		$this->assertSame('', $data['locale']);
	}//end testEmptyLanguageKeepsEmptyLocale()

	/**
	 * Without a native getUsedSpace the size comes from the home storage root row.
	 *
	 * @return void
	 */
	public function testUsedSpaceFromStorageRootRow(): void {
		if (memory_get_usage(true) > 128 * 1024 * 1024) {
			$this->markTestSkipped('The memory guard in getUsedSpaceMemorySafe is active in this process.');
		}

		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->accountManager->method('getAccount')->willReturn($this->account([]));
		foreach ([['size' => '400'], ['size' => 'n/a'], false] as $row) {
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturn($row);
			$expr = $this->createMock(IExpressionBuilder::class);
			$qb   = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'setMaxResults'] as $fluent) {
				$qb->method($fluent)->willReturnSelf();
			}

			$qb->method('expr')->willReturn($expr);
			$qb->method('executeQuery')->willReturn($result);
			$db = $this->createMock(IDBConnection::class);
			$db->method('getQueryBuilder')->willReturn($qb);
			$this->db = $db;

			$user = $this->plainUser();
			$data = $this->service()->buildUserDataArray($user);
			$used = 0;
			if ($row !== false && is_numeric($row['size']) === true) {
				$used = 400;
			}

			$this->assertSame(['free' => 'none', 'used' => $used, 'total' => 'none', 'relative' => 0], $data['quota']);
		}
	}//end testUsedSpaceFromStorageRootRow()

	/**
	 * Allowed writes: every capability true, profile properties existing, changed, equal and new.
	 *
	 * @return void
	 */
	public function testUpdateUserPropertiesAllowedWrites(): void {
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$account = $this->account(
			[
				IAccountManager::PROPERTY_PHONE   => '0612',
				IAccountManager::PROPERTY_WEBSITE => 'https://old.example.org',
			]
		);
		$this->accountManager->method('getAccount')->willReturn($account);
		$this->accountManager->expects($this->once())->method('updateAccount')->with($account);
		$user = $this->richUser(['canChangeMailAddress' => true]);

		$this->service()->updateUserProperties(
			$user,
			[
				'displayName' => 'Jan B',
				'email'       => 'jb@example.org',
				'password'    => 'secret',
				'language'    => 'de',
				'locale'      => 'de_DE',
				'phone'       => '0612',
				'website'     => 'https://new.example.org',
				'role'        => 'Archivaris',
				'biography'   => 42,
				'firstName'   => 'Jan',
				'middleName'  => 'van',
				'lastName'    => null,
			]
		);

		$this->assertSame(
			[
				'user:setDisplayName=Jan B',
				'user:setEMailAddress=jb@example.org',
				'user:setPassword=secret',
				'user:setLanguage=de',
				'user:setLocale=de_DE',
				'account:set:website=https://new.example.org',
				'account:create:role=Archivaris:v2-local:0',
				'account:create:biography=42:v2-local:0',
				'config:jan:core/firstName=Jan',
				'config:jan:core/middleName=van',
				'config:jan:core/role=Archivaris',
			],
			$this->writes
		);
	}//end testUpdateUserPropertiesAllowedWrites()

	/**
	 * Refused writes: capability false or method absent, unchanged account values.
	 *
	 * @return void
	 */
	public function testUpdateUserPropertiesRefusedWrites(): void {
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->accountManager->method('getAccount')->willReturn($this->account([IAccountManager::PROPERTY_PHONE => '0612']));
		$this->accountManager->expects($this->never())->method('updateAccount');

		$this->service()->updateUserProperties(
			$this->richUser(['canChangeDisplayName' => false, 'canChangePassword' => false]),
			['displayName' => 'X', 'email' => 'x@example.org', 'password' => 'p', 'phone' => '0612']
		);
		$this->service()->updateUserProperties(
			$this->plainUser(),
			['displayName' => 'X', 'email' => 'x@example.org', 'password' => 'p', 'language' => 'de', 'locale' => 'de_DE']
		);

		$this->assertSame([], $this->writes);
	}//end testUpdateUserPropertiesRefusedWrites()

	/**
	 * A failing AccountManager is logged and the name fields and role are still written.
	 *
	 * @return void
	 */
	public function testUpdateUserPropertiesAccountManagerFailure(): void {
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$calls = 0;
		$this->accountManager->method('getAccount')->willReturnCallback(
			function () use (&$calls) {
				$calls++;
				// The two buildUserDataArray() snapshots read fine, the update fails.
				if ($calls === 2) {
					throw new \RuntimeException('locked');
				}

				return $this->account([]);
			}
		);

		$this->service()->updateUserProperties($this->plainUser(), ['phone' => '1', 'lastName' => 'Jansen', 'role' => 'R']);

		$this->assertSame(['config:piet:core/lastName=Jansen', 'config:piet:core/role=R'], $this->writes);
		$this->assertContains('[UserService] Failed to update AccountManager properties for user: piet', $this->warnings);
	}//end testUpdateUserPropertiesAccountManagerFailure()
}//end class
