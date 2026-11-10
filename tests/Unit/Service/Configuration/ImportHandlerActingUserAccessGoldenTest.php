<?php

/**
 * Access golden test for the acting user, declared groups and orphan cleanup of ImportHandler.
 *
 * Pins who an import acts as (session user, first admin, any user, nobody),
 * which groups a configuration provisions and records, and when a schema
 * keeps its `orphanCleanup` flag (no session or an administrator) and when it
 * loses it (a signed-in non-administrator): the allowed and the refused
 * outcomes, with every log call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class ImportHandlerActingUserAccessGoldenTest extends TestCase {

	/** @var IAppConfig&MockObject The app config double. */
	private IAppConfig&MockObject $appConfig;

	/** @var ImportHandler The handler under test. */
	private ImportHandler $handler;

	/** @var array<int, string> Every log and config call, in order. */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (...$args): bool {
				$this->calls[] = 'config ' . json_encode(array_slice($args, 0, 3));
				return true;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = $level . ' ' . $message . ' ' . json_encode(array_keys($context));
				}
			);
		}

		$this->handler = new ImportHandler(
			schemaMapper:        $this->createMock(SchemaMapper::class),
			registerMapper:      $this->createMock(RegisterMapper::class),
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $this->createMock(MappingMapper::class),
			appConfig:           $this->appConfig,
			logger:              $logger,
			appDataPath:         '/tmp',
			objectService:       $this->createMock(ObjectService::class)
		);
	}//end setUp()

	private function call(string $method, array $args = []): mixed {
		return (new ReflectionMethod(ImportHandler::class, $method))->invokeArgs($this->handler, $args);
	}//end call()

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	private function session(?IUser $user): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->handler->setUserSession($session);
	}//end session()

	/**
	 * @param array|\Throwable|null $admins Members of the admin group; null for no group.
	 */
	private function adminGroup(array|\Throwable|null $admins, array $adminUids = []): void {
		$groupManager = $this->createMock(IGroupManager::class);
		if ($admins instanceof \Throwable) {
			$groupManager->method('get')->willThrowException($admins);
		} else if ($admins === null) {
			$groupManager->method('get')->willReturn(null);
		} else {
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($admins);
			$groupManager->method('get')->with('admin')->willReturn($group);
		}

		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $adminUids, true));
		$this->handler->setGroupManager($groupManager);
	}//end adminGroup()

	private function users(array|\Throwable $found): void {
		$userManager = $this->createMock(IUserManager::class);
		if ($found instanceof \Throwable) {
			$userManager->method('search')->willThrowException($found);
		} else {
			$userManager->method('search')->with('', 1, 0)->willReturn($found);
		}

		$this->handler->setUserManager($userManager);
	}//end users()

	public function testASessionUserAlwaysActs(): void {
		$alice = $this->user('alice');
		$this->session($alice);
		$this->adminGroup([$this->user('root')]);
		$this->users([$this->user('bob')]);

		$this->assertSame($alice, $this->call('resolveActingUser'));
		$this->assertSame([], $this->calls);
	}//end testASessionUserAlwaysActs()

	public function testWithoutSessionTheFirstAdminActs(): void {
		$root = $this->user('root');
		$this->session(null);
		$this->adminGroup(['root' => $root, 'other' => $this->user('other')]);
		$this->users([$this->user('bob')]);

		$this->assertSame($root, $this->call('resolveActingUser'));
		$this->assertSame([], $this->calls);
	}//end testWithoutSessionTheFirstAdminActs()

	public function testWithoutAdminsAnyUserActs(): void {
		$bob = $this->user('bob');
		foreach ([[], null] as $admins) {
			$this->adminGroup($admins);
			$this->users(['bob' => $bob]);
			$this->assertSame($bob, $this->call('resolveActingUser'));
		}

		$this->assertSame([], $this->calls);
	}//end testWithoutAdminsAnyUserActs()

	public function testAFailingAdminLookupWarnsAndFallsBack(): void {
		$bob = $this->user('bob');
		$this->adminGroup(new RuntimeException('ldap down'));
		$this->users([$bob]);

		$this->assertSame($bob, $this->call('resolveActingUser'));
		$this->assertSame(
			['warning [ImportHandler] Failed to resolve admin acting user from admin group: ldap down ["file","line"]'],
			$this->calls
		);
	}//end testAFailingAdminLookupWarnsAndFallsBack()

	public function testNobodyActsWhenNoUserIsResolvable(): void {
		$this->assertNull($this->call('resolveActingUser'));

		$this->adminGroup([]);
		$this->users([]);
		$this->assertNull($this->call('resolveActingUser'));

		$this->users(new RuntimeException('db down'));
		$this->assertNull($this->call('resolveActingUser'));

		$none = 'warning [ImportHandler] No acting user resolvable for import - user-dependent ops (folders) may skip ["file","line"]';
		$this->assertSame(
			[$none, $none, 'warning [ImportHandler] Failed to resolve any fallback acting user: db down ["file","line"]', $none],
			$this->calls
		);
	}//end testNobodyActsWhenNoUserIsResolvable()

	private const RBAC_DOCUMENT = [
		'components' => [
			'registers' => ['zaken' => ['authorization' => ['read' => ['medewerkers']]]],
			'schemas' => ['zaak' => ['authorization' => ['delete' => ['archivarissen']]]],
		],
	];

	private function provisioner(?\Throwable $failure = null): void {
		$provisioner = $this->createMock(GroupProvisioner::class);
		$provisioner->method('provision')->willReturnCallback(
			function (array $groups, string $declaredBy) use ($failure): array {
				$this->calls[] = 'provision ' . json_encode([$groups, $declaredBy]);
				if ($failure !== null) {
					throw $failure;
				}

				return $groups;
			}
		);
		$this->handler->setGroupProvisioner($provisioner);
	}//end provisioner()

	public function testWithoutAProvisionerNoGroupsAreProvisioned(): void {
		$this->call('provisionDeclaredGroups', [self::RBAC_DOCUMENT, 'zaakapp']);
		$this->assertSame([], $this->calls);
	}//end testWithoutAProvisionerNoGroupsAreProvisioned()

	public function testADocumentWithoutGroupsProvisionsNothing(): void {
		$this->provisioner();
		$this->call('provisionDeclaredGroups', [['components' => []], 'zaakapp']);
		$this->assertSame([], $this->calls);
	}//end testADocumentWithoutGroupsProvisionsNothing()

	public function testDeclaredGroupsAreProvisionedAndRecordedPerApp(): void {
		$this->provisioner();
		$this->call('provisionDeclaredGroups', [self::RBAC_DOCUMENT, 'zaakapp']);
		$this->call('provisionDeclaredGroups', [self::RBAC_DOCUMENT, null]);

		$this->assertSame(
			[
				'provision [["medewerkers","archivarissen"],"zaakapp"]',
				'config ["openregister","declared_groups_zaakapp","[\"medewerkers\",\"archivarissen\"]"]',
				'provision [["medewerkers","archivarissen"],"unknown"]',
			],
			$this->calls
		);
	}//end testDeclaredGroupsAreProvisionedAndRecordedPerApp()

	public function testAFailingProvisionIsLoggedNotThrown(): void {
		$this->provisioner(new RuntimeException('backend refused'));
		$this->call('provisionDeclaredGroups', [self::RBAC_DOCUMENT, 'zaakapp']);

		$this->assertSame(
			[
				'provision [["medewerkers","archivarissen"],"zaakapp"]',
				'error [ImportHandler] declared-group provisioning failed: backend refused ["exception","appId"]',
			],
			$this->calls
		);
	}//end testAFailingProvisionIsLoggedNotThrown()

	private const FLAGGED = ['slug' => 's', 'configuration' => ['orphanCleanup' => true, 'other' => 1]];

	public function testOrphanCleanupIsKeptWithoutSessionOrForAnAdmin(): void {
		$this->assertSame(self::FLAGGED, $this->call('withoutOrphanCleanupForNonAdmin', [self::FLAGGED]));

		$this->session(null);
		$this->assertSame(self::FLAGGED, $this->call('withoutOrphanCleanupForNonAdmin', [self::FLAGGED]));

		$this->session($this->user('root'));
		$this->adminGroup([], ['root']);
		$this->assertSame(self::FLAGGED, $this->call('withoutOrphanCleanupForNonAdmin', [self::FLAGGED]));
	}//end testOrphanCleanupIsKeptWithoutSessionOrForAnAdmin()

	public function testOrphanCleanupIsDroppedForANonAdmin(): void {
		$this->session($this->user('alice'));
		$this->adminGroup([], ['root']);

		$this->assertSame(
			['slug' => 's', 'configuration' => ['other' => 1]],
			$this->call('withoutOrphanCleanupForNonAdmin', [self::FLAGGED])
		);
		$this->assertSame(
			['slug' => 's', 'configuration' => ['other' => 2]],
			$this->call('withoutOrphanCleanupForNonAdmin', [['slug' => 's', 'configuration' => '{"orphanCleanup":false,"other":2}']])
		);
	}//end testOrphanCleanupIsDroppedForANonAdmin()

	public function testANonAdminWithoutTheFlagKeepsTheDataAsGiven(): void {
		$this->session($this->user('alice'));
		$this->adminGroup([], ['root']);

		foreach ([
			['slug' => 's'],
			['slug' => 's', 'configuration' => '{"other":2}'],
			['slug' => 's', 'configuration' => 'not json'],
			['slug' => 's', 'configuration' => ['other' => 1]],
		] as $data) {
			$this->assertSame($data, $this->call('withoutOrphanCleanupForNonAdmin', [$data]));
		}
	}//end testANonAdminWithoutTheFlagKeepsTheDataAsGiven()

	public function testWithoutAGroupManagerASessionUserCountsAsNonAdmin(): void {
		$this->session($this->user('root'));

		$this->assertSame(
			['slug' => 's', 'configuration' => ['other' => 1]],
			$this->call('withoutOrphanCleanupForNonAdmin', [self::FLAGGED])
		);
	}//end testWithoutAGroupManagerASessionUserCountsAsNonAdmin()
}//end class
