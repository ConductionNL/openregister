<?php

declare(strict_types=1);

/**
 * CacheHandler name-scope POLICY tests (SEC-CTRL-2 step 2)
 *
 * `CacheHandlerTenantScopeTest` proves the leak is closed on the happy tenant
 * boundary. This class exercises the DECISION FUNCTION itself — every arm that
 * decides whether a caller may be told a name. Each one is a place where getting
 * the answer wrong either re-opens the disclosure (too permissive) or silently
 * blanks names for legitimate users (too strict), so none of them is filler.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One control per policy arm.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Tests need to mock many dependencies.
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IMemcache;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Policy-arm controls for CacheHandler's name-visibility decision.
 */
class CacheHandlerNameScopePolicyTest extends TestCase {
	private const ORG_A = 'aaaaaaaa-0000-0000-0000-000000000001';
	private const ORG_B = 'bbbbbbbb-0000-0000-0000-000000000002';

	/** @var MagicMapper */
	private MagicMapper $objectMapper;

	/** @var OrganisationMapper */
	private OrganisationMapper $organisationMapper;

	/** @var LoggerInterface */
	private LoggerInterface $logger;

	/** @var ICacheFactory */
	private ICacheFactory $cacheFactory;

	/** @var IMemcache */
	private IMemcache $nameDistributedCache;

	/** @var IMemcache */
	private IMemcache $queryCache;

	/** @var IUserSession */
	private IUserSession $userSession;

	/**
	 * Set up shared doubles. No user by default; tests opt in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->nameDistributedCache = $this->createMock(IMemcache::class);
		$this->queryCache = $this->createMock(IMemcache::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->cacheFactory->method('createDistributed')
			->willReturnCallback(function (string $prefix) {
				if ($prefix === 'openregister_object_names') {
					return $this->nameDistributedCache;
				}

				return $this->queryCache;
			});

		$this->nameDistributedCache->method('get')->willReturn(null);
		$this->organisationMapper->method('findMultipleByUuid')->willReturn([]);
		$this->objectMapper->method('findMultiple')->willReturn([]);
	}

	/**
	 * Give the session a user with the supplied uid.
	 *
	 * @param string $uid The user id
	 *
	 * @return void
	 */
	private function withUser(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * Build an IAppConfig double answering a fixed multitenancy config string.
	 *
	 * @param string $multitenancyJson The raw value stored under openregister/multitenancy
	 *
	 * @return IAppConfig
	 */
	private function appConfigWith(string $multitenancyJson): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = '') use ($multitenancyJson) {
				if ($app === 'openregister' && $key === 'multitenancy') {
					return $multitenancyJson;
				}

				return $default;
			});
		return $appConfig;
	}

	/**
	 * Build a CacheHandler with the supplied optional collaborators.
	 *
	 * @param IGroupManager|null  $groupManager   Group manager for the admin-override arm
	 * @param IAppConfig|null     $appConfig      App config for the multitenancy switches
	 * @param RegisterMapper|null $registerMapper Register mapper for magic-table queries
	 * @param SchemaMapper|null   $schemaMapper   Schema mapper for magic-table queries
	 * @param IDBConnection|null  $db             Database connection for magic-table queries
	 *
	 * @return CacheHandler
	 */
	private function buildHandler(
		?IGroupManager $groupManager = null,
		?IAppConfig $appConfig = null,
		?RegisterMapper $registerMapper = null,
		?SchemaMapper $schemaMapper = null,
		?IDBConnection $db = null,
	): CacheHandler {
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')
			->willReturnCallback(function (string $class) {
				if ($class === MagicMapper::class) {
					return $this->objectMapper;
				}

				return $this->createMock($class);
			});

		return new CacheHandler(
			$this->organisationMapper,
			$this->logger,
			$this->cacheFactory,
			$this->userSession,
			$container,
			$registerMapper,
			$schemaMapper,
			$db,
			$groupManager,
			$appConfig
		);
	}

	/**
	 * Build an ObjectEntity owned by a given organisation.
	 *
	 * @param string      $uuid         Object UUID
	 * @param string      $name         Object name
	 * @param string|null $organisation Owning organisation UUID
	 *
	 * @return ObjectEntity
	 */
	private function createObject(string $uuid, string $name, ?string $organisation): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		$object->setOrganisation($organisation);
		return $object;
	}

	/**
	 * Read the resolved scope out of the handler.
	 *
	 * @param CacheHandler $handler The handler under test
	 *
	 * @return array<int, string>|null
	 */
	private function resolveScope(CacheHandler $handler): ?array {
		$ref = new ReflectionClass($handler);
		$begin = $ref->getMethod('beginNameScope');
		$begin->setAccessible(true);
		$begin->invoke($handler);

		$method = $ref->getMethod('resolveNameScope');
		$method->setAccessible(true);
		return $method->invoke($handler);
	}

	// =====================================================================
	// resolveNameScope() — the four documented outcomes
	// =====================================================================

	/**
	 * Multitenancy switched off instance-wide means no boundary at all.
	 *
	 * @return void
	 */
	public function testMultitenancyDisabledYieldsUnrestrictedScope(): void {
		$this->withUser('alice');
		$handler = $this->buildHandler(null, $this->appConfigWith('{"enabled":false}'));

		$this->assertNull($this->resolveScope($handler));
	}

	/**
	 * `enabled: true` is filtering ON — the switch must not read as a wildcard.
	 *
	 * @return void
	 */
	public function testMultitenancyExplicitlyEnabledStillScopes(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);

		$handler = $this->buildHandler(null, $this->appConfigWith('{"enabled":true}'));

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	/**
	 * An unparseable multitenancy config must FAIL CLOSED (keep filtering), never
	 * be read as "disabled".
	 *
	 * @return void
	 */
	public function testUnparseableMultitenancyConfigKeepsFiltering(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);

		$handler = $this->buildHandler(null, $this->appConfigWith('not json at all'));

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	/**
	 * An empty multitenancy config is the default install — filtering stays on.
	 *
	 * @return void
	 */
	public function testEmptyMultitenancyConfigKeepsFiltering(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);

		$handler = $this->buildHandler(null, $this->appConfigWith(''));

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	/**
	 * No active organisation at all is the trait's `1 = 0` arm: nothing visible.
	 *
	 * @return void
	 */
	public function testNoActiveOrganisationYieldsEmptyScope(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(null);

		$handler = $this->buildHandler();

		$this->assertSame([], $this->resolveScope($handler));
	}

	/**
	 * With no session the DEFAULT organisation stands in — the same fallback
	 * Db\MultiTenancyTrait::getActiveOrganisationUuid() applies.
	 *
	 * @return void
	 */
	public function testAnonymousCallerFallsBackToTheDefaultOrganisation(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationMapper->method('getDefaultOrganisationFromConfig')->willReturn(self::ORG_A);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_A]);

		$handler = $this->buildHandler();

		$this->assertSame([self::ORG_A], $this->resolveScope($handler));
	}

	/**
	 * A caller sees its active organisation AND its parents, exactly like the
	 * mappers — narrowing this would blank names for hierarchical tenants.
	 *
	 * @return void
	 */
	public function testScopeIncludesTheParentOrganisations(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')
			->willReturn([self::ORG_B, self::ORG_A]);

		$handler = $this->buildHandler();

		$this->assertSame([self::ORG_B, self::ORG_A], $this->resolveScope($handler));
	}

	/**
	 * If the hierarchy lookup fails, fall back to the ACTIVE organisation alone —
	 * the narrower answer, never a wider one.
	 *
	 * @return void
	 */
	public function testHierarchyFailureFallsBackToTheActiveOrganisationAlone(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')
			->willThrowException(new \RuntimeException('hierarchy unavailable'));

		$handler = $this->buildHandler();

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	/**
	 * If the scope cannot be established at all, nothing is visible.
	 *
	 * @return void
	 */
	public function testScopeResolutionFailureDeniesEverything(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')
			->willThrowException(new \RuntimeException('mapper down'));

		$handler = $this->buildHandler();

		$this->assertSame([], $this->resolveScope($handler));
	}

	// =====================================================================
	// The admin override — the one arm that widens the boundary
	// =====================================================================

	/**
	 * An admin with the override enabled reads names across organisations.
	 *
	 * @return void
	 */
	public function testAdminOverrideEnabledYieldsUnrestrictedScope(): void {
		$this->withUser('root');
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$handler = $this->buildHandler($groupManager, $this->appConfigWith('{"adminOverride":true}'));

		$this->assertNull($this->resolveScope($handler));
	}

	/**
	 * SaaS mode revokes the admin override — a hard tenant boundary wins.
	 *
	 * @return void
	 */
	public function testSaasModeRevokesTheAdminOverride(): void {
		$this->withUser('root');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$handler = $this->buildHandler(
			$groupManager,
			$this->appConfigWith('{"adminOverride":true,"saasMode":true}')
		);

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	/**
	 * A NON-admin never gets the override, however the config is set.
	 *
	 * @return void
	 */
	public function testNonAdminNeverGetsTheOverride(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		$handler = $this->buildHandler($groupManager, $this->appConfigWith('{"adminOverride":true}'));

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	/**
	 * An admin WITHOUT the override configured stays inside their organisation.
	 *
	 * @return void
	 */
	public function testAdminWithoutOverrideConfiguredStaysScoped(): void {
		$this->withUser('root');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$handler = $this->buildHandler($groupManager, $this->appConfigWith('{"enabled":true}'));

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	/**
	 * The override cannot apply to an anonymous caller — there is no admin.
	 *
	 * @return void
	 */
	public function testAnonymousCallerNeverGetsTheAdminOverride(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationMapper->method('getDefaultOrganisationFromConfig')->willReturn(self::ORG_A);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_A]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$handler = $this->buildHandler($groupManager, $this->appConfigWith('{"adminOverride":true}'));

		$this->assertSame([self::ORG_A], $this->resolveScope($handler));
	}

	/**
	 * An unparseable config cannot switch the override on.
	 *
	 * @return void
	 */
	public function testUnparseableConfigCannotEnableTheOverride(): void {
		$this->withUser('root');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$handler = $this->buildHandler($groupManager, $this->appConfigWith('"a bare string"'));

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	/**
	 * With no app config at all there is no override and filtering stays on.
	 *
	 * @return void
	 */
	public function testNoAppConfigMeansNoOverrideAndFilteringStaysOn(): void {
		$this->withUser('root');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$handler = $this->buildHandler($groupManager, null);

		$this->assertSame([self::ORG_B], $this->resolveScope($handler));
	}

	// =====================================================================
	// The distributed-cache envelope — the cross-deploy boundary
	// =====================================================================

	/**
	 * A value written BEFORE this change is a bare string with no tenancy. It
	 * must read as a MISS, never be served unscoped.
	 *
	 * @return void
	 */
	public function testLegacyBareStringCacheValueIsRejected(): void {
		$handler = $this->buildHandler();

		$ref = new ReflectionClass($handler);
		$method = $ref->getMethod('readNameEnvelope');
		$method->setAccessible(true);

		$this->assertNull($method->invoke($handler, 'Legacy Name'));
		$this->assertNull($method->invoke($handler, null));
		$this->assertNull($method->invoke($handler, ['o' => self::ORG_A]));
		$this->assertNull($method->invoke($handler, ['n' => 42, 'o' => null]));
		$this->assertNull($method->invoke($handler, ['n' => 'Name', 'o' => 7]));
		$this->assertSame(
			['n' => 'Name', 'o' => self::ORG_A],
			$method->invoke($handler, ['n' => 'Name', 'o' => self::ORG_A])
		);
	}

	/**
	 * A distributed entry whose value survives the shape check but carries no
	 * tenancy settles nothing: the caller must fall through to the database
	 * rather than be handed an unscoped name.
	 *
	 * @return void
	 */
	public function testTenancylessDistributedEntryIsNotServedWhileScoping(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);

		$distributed = $this->createMock(IMemcache::class);
		$distributed->method('get')->willReturn(['n' => 'Untenanted', 'o' => null]);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($distributed);
		$this->cacheFactory = $cacheFactory;

		$handler = $this->buildHandler();

		$names = $handler->getMultipleObjectNames(['cccc3333-cccc-3333-cccc-333333333333']);

		$this->assertArrayNotHasKey('cccc3333-cccc-3333-cccc-333333333333', $names);
	}

	// =====================================================================
	// getSingleObjectName() — the internal primitive is gated too
	// =====================================================================

	/**
	 * An in-memory entry owned by another organisation is not served.
	 *
	 * @return void
	 */
	public function testSingleNameFromMemoryIsRefusedAcrossTenants(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);
		$this->organisationMapper->method('findByUuid')
			->willThrowException(new \RuntimeException('not an organisation'));
		$this->objectMapper->method('findAcrossAllSources')->willReturn(['object' => null]);

		$handler = $this->buildHandler();
		$handler->setObjectName(identifier: 'dddd4444-dddd-4444-dddd-444444444444', name: 'Alpha Name', organisation: self::ORG_A);

		$this->assertNull($handler->getSingleObjectName('dddd4444-dddd-4444-dddd-444444444444'));
	}

	/**
	 * An object resolved from the database is refused when it belongs elsewhere,
	 * and returned when it belongs here.
	 *
	 * @return void
	 */
	public function testSingleNameFromDatabaseHonoursTheBoundaryBothWays(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);
		$this->organisationMapper->method('findByUuid')
			->willThrowException(new \RuntimeException('not an organisation'));
		$this->objectMapper->method('findAcrossAllSources')
			->willReturnCallback(function (string|int $identifier) {
				if ((string)$identifier === 'eeee5555-eeee-5555-eeee-555555555555') {
					return ['object' => $this->createObject((string)$identifier, 'Alpha Only', self::ORG_A)];
				}

				return ['object' => $this->createObject((string)$identifier, 'Beta Only', self::ORG_B)];
			});

		$handler = $this->buildHandler();

		$this->assertNull($handler->getSingleObjectName('eeee5555-eeee-5555-eeee-555555555555'));
		$this->assertSame('Beta Only', $handler->getSingleObjectName('ffff6666-ffff-6666-ffff-666666666666'));
	}

	/**
	 * An ORGANISATION resolved from the database is refused across tenants and
	 * returned for the caller's own organisation.
	 *
	 * @return void
	 */
	public function testSingleOrganisationNameHonoursTheBoundaryBothWays(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);
		$this->organisationMapper->method('findByUuid')
			->willReturnCallback(function (string $uuid) {
				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				$organisation->setName('Org ' . substr($uuid, 0, 4));
				return $organisation;
			});

		$handler = $this->buildHandler();

		$this->assertNull($handler->getSingleObjectName(self::ORG_A));
		$this->assertSame('Org bbbb', $handler->getSingleObjectName(self::ORG_B));
	}

	// =====================================================================
	// The magic-table batch loader is the tenancy oracle
	// =====================================================================

	/**
	 * `batchLoadNamesFromMagicTables()` carries `_organisation` through, and
	 * `getMultipleObjectNames()` refuses the rows that belong elsewhere.
	 *
	 * @return void
	 */
	public function testBatchLoadedMagicTableNamesAreScopedByOrganisation(): void {
		$this->withUser('alice');
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_B);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_B]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$db = $this->createMock(IDBConnection::class);

		$register = new Register();
		$registerRef = new ReflectionClass($register);
		$idProp = $registerRef->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($register, 1);
		$register->setSchemas([5]);
		$register->setConfiguration(['schemas' => ['test-schema' => ['magicMapping' => true]]]);
		$registerMapper->method('findAll')->willReturn([$register]);

		$schema = new Schema();
		$schemaRef = new ReflectionClass($schema);
		$schemaIdProp = $schemaRef->getProperty('id');
		$schemaIdProp->setAccessible(true);
		$schemaIdProp->setValue($schema, 5);
		$schema->setSlug('test-schema');
		$schemaMapper->method('findMultipleOptimized')->willReturn([5 => $schema]);

		$statement = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$statement->method('fetch')->willReturnOnConsecutiveCalls(
			['_uuid' => '11112222-1111-2222-1111-222222222222', '_organisation' => self::ORG_A, 'name_value' => 'Alpha Batch'],
			['_uuid' => '33334444-3333-4444-3333-444444444444', '_organisation' => self::ORG_B, 'name_value' => 'Beta Batch'],
			false
		);
		$db->method('prepare')->willReturn($statement);

		$handler = $this->buildHandler(null, null, $registerMapper, $schemaMapper, $db);

		$names = $handler->getMultipleObjectNames([
			'11112222-1111-2222-1111-222222222222',
			'33334444-3333-4444-3333-444444444444',
		]);

		$this->assertArrayNotHasKey('11112222-1111-2222-1111-222222222222', $names);
		$this->assertSame('Beta Batch', $names['33334444-3333-4444-3333-444444444444'] ?? null);
	}

	/**
	 * With no magic-table dependencies wired there is no tenancy oracle, so the
	 * loader returns nothing instead of fataling on a null mapper.
	 *
	 * @return void
	 */
	public function testBatchLoaderWithoutDependenciesResolvesNothingRatherThanFataling(): void {
		$handler = $this->buildHandler();

		$ref = new ReflectionClass($handler);
		$method = $ref->getMethod('batchLoadNamesFromMagicTables');
		$method->setAccessible(true);

		$this->assertSame([], $method->invoke($handler, ['aaaa0000-aaaa-0000-aaaa-000000000000']));
	}
}
