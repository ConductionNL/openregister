<?php

/**
 * TenancyIdentityTrait unit test.
 *
 * Who the caller is to the tenancy layer: the active organisation and its
 * hierarchy, the default organisation from the app config, the user id and
 * admin flag, the shared master data a consumer may read, and the redacted
 * log lines.
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

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait;
use OCA\OpenRegister\Service\SharedMasterDataServiceInterface;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A mapper-shaped host for the trait, with only the collaborators a case gives it.
 */
final class TenancyIdentityHost {
	use TenancyIdentityTrait;

	/**
	 * The connection.
	 *
	 * @var IDBConnection
	 */
	protected IDBConnection $db;

	/**
	 * The logger, when the case gives one.
	 *
	 * @var LoggerInterface
	 */
	protected LoggerInterface $logger;

	/**
	 * The app config, when the case gives one.
	 *
	 * @var IAppConfig
	 */
	protected IAppConfig $appConfig;

	/**
	 * The session, when the case gives one.
	 *
	 * @var IUserSession
	 */
	protected IUserSession $userSession;

	/**
	 * The organisation mapper, when the case gives one.
	 *
	 * @var OrganisationMapper
	 */
	protected OrganisationMapper $organisationMapper;

	/**
	 * The group manager, when the case gives one.
	 *
	 * @var IGroupManager
	 */
	protected IGroupManager $groupManager;

	/**
	 * The table this host maps.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Build the host with the given collaborators.
	 *
	 * @param array<string, object> $deps  Collaborators by property name; `shared` is the shared master data service.
	 * @param string                $table The mapped table.
	 */
	public function __construct(array $deps, string $table=SharedMasterDataServiceInterface::REGISTERS) {
		foreach (['db', 'logger', 'appConfig', 'userSession', 'organisationMapper', 'groupManager'] as $name) {
			if (isset($deps[$name]) === true) {
				$this->{$name} = $deps[$name];
			}
		}

		if (isset($deps['shared']) === true) {
			$this->sharedMasterData = $deps['shared'];
		}

		$this->table = $table;
	}//end __construct()

	/**
	 * The mapped table.
	 *
	 * @return string
	 */
	public function getTableName(): string {
		return $this->table;
	}//end getTableName()

	/**
	 * Run a trait method.
	 *
	 * @param string $method The method.
	 * @param array  $args   Named arguments.
	 *
	 * @return mixed
	 */
	public function call(string $method, array $args=[]): mixed {
		return $this->{$method}(...$args);
	}//end call()
}//end class

/**
 * @covers \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 * @uses   \OCA\OpenRegister\Service\TenancyCollaborators
 * @uses   \OCA\OpenRegister\Service\TenantLogRedactor
 */
final class TenancyIdentityTraitTest extends TestCase {

	private const ORG = 'aaaaaaaa-0000-0000-0000-000000000001';

	private const PARENT = 'aaaaaaaa-0000-0000-0000-000000000002';

	/**
	 * A session signed in as the given user, or with nobody signed in.
	 *
	 * @param string|null $uid The user id, or null for nobody.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * An app config answering the given string values.
	 *
	 * @param array<string, string> $values Values by key.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(array $values): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($values[$key] ?? $default)
		);

		return $config;
	}//end appConfig()

	/**
	 * Without a session there is no active organisation and no organisations at all.
	 *
	 * @return void
	 */
	public function testWithoutASessionThereIsNoOrganisation(): void {
		$host = new TenancyIdentityHost(deps: []);

		$this->assertNull($host->call(method: 'getActiveOrganisationUuid'));
		$this->assertSame([], $host->call(method: 'getActiveOrganisationUuids'));
		$this->assertNull($host->call(method: 'getCurrentUserId'));
		$this->assertFalse($host->call(method: 'isCurrentUserAdmin'));
	}//end testWithoutASessionThereIsNoOrganisation()

	/**
	 * Nobody signed in and no organisation mapper: the default organisation comes from the app config.
	 *
	 * The explicit setting wins; without it the stored organisation settings
	 * name the default; without either there is none.
	 *
	 * @return void
	 */
	public function testTheDefaultOrganisationComesFromTheAppConfig(): void {
		$explicit = new TenancyIdentityHost(
			deps: ['userSession' => $this->session(uid: null), 'appConfig' => $this->appConfig(values: ['defaultOrganisation' => self::ORG, 'organisation' => '{"default_organisation":"other"}'])]
		);
		$stored = new TenancyIdentityHost(
			deps: ['userSession' => $this->session(uid: null), 'appConfig' => $this->appConfig(values: ['organisation' => '{"default_organisation":"' . self::PARENT . '"}'])]
		);
		$storedWithout = new TenancyIdentityHost(
			deps: ['userSession' => $this->session(uid: null), 'appConfig' => $this->appConfig(values: ['organisation' => '{"other":true}'])]
		);
		$nothing = new TenancyIdentityHost(deps: ['userSession' => $this->session(uid: null), 'appConfig' => $this->appConfig(values: [])]);
		$noConfig = new TenancyIdentityHost(deps: ['userSession' => $this->session(uid: null)]);

		$this->assertSame(self::ORG, $explicit->call(method: 'getActiveOrganisationUuid'));
		$this->assertSame(self::PARENT, $stored->call(method: 'getActiveOrganisationUuid'));
		$this->assertNull($storedWithout->call(method: 'getActiveOrganisationUuid'));
		$this->assertNull($nothing->call(method: 'getActiveOrganisationUuid'));
		$this->assertNull($noConfig->call(method: 'getDefaultOrganisationUuid'));
		$this->assertSame([self::ORG], $explicit->call(method: 'getActiveOrganisationUuids'), 'without a mapper the active organisation stands alone');
	}//end testTheDefaultOrganisationComesFromTheAppConfig()

	/**
	 * Nobody signed in but an organisation mapper: the mapper's configured default is the active organisation.
	 *
	 * @return void
	 */
	public function testTheMapperNamesTheDefaultForAnAnonymousCaller(): void {
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->expects($this->never())->method('getActiveOrganisationWithFallback');
		$organisations->method('getDefaultOrganisationFromConfig')->willReturn(self::PARENT);

		$host = new TenancyIdentityHost(
			deps: ['userSession' => $this->session(uid: null), 'organisationMapper' => $organisations, 'appConfig' => $this->appConfig(values: ['defaultOrganisation' => self::ORG])]
		);

		$this->assertSame(self::PARENT, $host->call(method: 'getActiveOrganisationUuid'), 'the mapper wins over the app config');
	}//end testTheMapperNamesTheDefaultForAnAnonymousCaller()

	/**
	 * A failing hierarchy lookup is logged and falls back to the active organisation alone.
	 *
	 * @return void
	 */
	public function testAFailingHierarchyFallsBackToTheActiveOrganisation(): void {
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->with('anna')->willReturn(self::ORG);
		$organisations->method('getOrganisationHierarchy')->willThrowException(new RuntimeException('table gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Failed to get organisation hierarchy: table gone'),
			$this->callback(static fn (array $context): bool => ($context['activeOrgUuid'] ?? null) === self::ORG)
		);

		$host = new TenancyIdentityHost(deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $organisations, 'logger' => $logger]);

		$this->assertSame([self::ORG], $host->call(method: 'getActiveOrganisationUuids'));
	}//end testAFailingHierarchyFallsBackToTheActiveOrganisation()

	/**
	 * The hierarchy, when it answers, is the set of organisations; an empty one falls back.
	 *
	 * @return void
	 */
	public function testTheHierarchyIsTheOrganisationSet(): void {
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn(self::ORG);
		$organisations->method('getOrganisationHierarchy')->willReturnOnConsecutiveCalls([self::ORG, self::PARENT], []);

		$host = new TenancyIdentityHost(deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $organisations]);

		$this->assertSame([self::ORG, self::PARENT], $host->call(method: 'getActiveOrganisationUuids'));
		$this->assertSame([self::ORG], $host->call(method: 'getActiveOrganisationUuids'));
	}//end testTheHierarchyIsTheOrganisationSet()

	/**
	 * The admin flag needs a signed-in user and a group manager.
	 *
	 * @return void
	 */
	public function testTheAdminFlagNeedsAUserAndAGroupManager(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');

		$this->assertFalse((new TenancyIdentityHost(deps: ['userSession' => $this->session(uid: 'root')]))->call(method: 'isCurrentUserAdmin'));
		$this->assertTrue((new TenancyIdentityHost(deps: ['userSession' => $this->session(uid: 'root'), 'groupManager' => $groups]))->call(method: 'isCurrentUserAdmin'));
		$this->assertFalse((new TenancyIdentityHost(deps: ['userSession' => $this->session(uid: 'anna'), 'groupManager' => $groups]))->call(method: 'isCurrentUserAdmin'));
		$this->assertFalse((new TenancyIdentityHost(deps: ['userSession' => $this->session(uid: null), 'groupManager' => $groups]))->call(method: 'isCurrentUserAdmin'));
	}//end testTheAdminFlagNeedsAUserAndAGroupManager()

	/**
	 * Shared master data ids are asked only for registers and schemas, and only with organisations.
	 *
	 * @return void
	 */
	public function testSharedMasterDataIdsAreAskedOnlyWhereTheyApply(): void {
		$shared = $this->createMock(SharedMasterDataServiceInterface::class);
		$shared->expects($this->once())->method('sharedIds')
			->with(SharedMasterDataServiceInterface::SCHEMAS, [self::ORG])
			->willReturn([4, 9]);

		$schemas = new TenancyIdentityHost(deps: ['shared' => $shared], table: SharedMasterDataServiceInterface::SCHEMAS);
		$views = new TenancyIdentityHost(deps: ['shared' => $shared], table: 'openregister_views');

		$this->assertSame([], $schemas->call(method: 'sharedMasterDataIds', args: ['activeOrgUuids' => []]));
		$this->assertSame([], $views->call(method: 'sharedMasterDataIds', args: ['activeOrgUuids' => [self::ORG]]));
		$this->assertSame([4, 9], $schemas->call(method: 'sharedMasterDataIds', args: ['activeOrgUuids' => [self::ORG]]));
	}//end testSharedMasterDataIdsAreAskedOnlyWhereTheyApply()

	/**
	 * The shared master data service is built once from the connection and then reused.
	 *
	 * @return void
	 */
	public function testTheSharedMasterDataServiceIsBuiltOnce(): void {
		$host = new TenancyIdentityHost(deps: ['db' => $this->createMock(IDBConnection::class)]);

		$first = $host->call(method: 'sharedMasterData');

		$this->assertInstanceOf(SharedMasterDataServiceInterface::class, $first);
		$this->assertSame($first, $host->call(method: 'sharedMasterData'));
	}//end testTheSharedMasterDataServiceIsBuiltOnce()

	/**
	 * Redacted warnings and info lines carry a pseudonym for the actor, never the user id.
	 *
	 * @return void
	 */
	public function testRedactedLinesPseudonymiseTheActor(): void {
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context) use (&$lines, $level): void {
					$lines[] = [$level, $message, $context];
				}
			);
		}

		$host = new TenancyIdentityHost(deps: ['logger' => $logger]);
		$host->call(method: 'warnRedacted', args: ['message' => 'denied', 'context' => ['actor' => 'anna', 'type' => 'cross_tenant_access_denied']]);
		$host->call(method: 'infoRedacted', args: ['message' => 'override', 'context' => ['actor' => null]]);

		$this->assertCount(2, $lines);
		$this->assertSame(['warning', 'denied'], [$lines[0][0], $lines[0][1]]);
		$this->assertStringStartsWith('actor:', $lines[0][2]['actor']);
		$this->assertNotSame('actor:anna', $lines[0][2]['actor']);
		$this->assertSame('cross_tenant_access_denied', $lines[0][2]['type']);
		$this->assertSame(['info', 'override', 'actor:anonymous'], [$lines[1][0], $lines[1][1], $lines[1][2]['actor']]);
	}//end testRedactedLinesPseudonymiseTheActor()

	/**
	 * Without a logger a redacted line is simply not written.
	 *
	 * @return void
	 */
	public function testWithoutALoggerNothingIsWritten(): void {
		$host = new TenancyIdentityHost(deps: []);

		$this->assertNull($host->call(method: 'redactedLine', args: ['context' => ['actor' => 'anna']]));
		$host->call(method: 'warnRedacted', args: ['message' => 'denied', 'context' => ['actor' => 'anna']]);
		$this->assertNull($host->call(method: 'tenancyAppConfig'));
	}//end testWithoutALoggerNothingIsWritten()
}//end class
