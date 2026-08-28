<?php

declare(strict_types=1);

/**
 * CacheHandler tenant-scope tests (SEC-CTRL-2 step 2)
 *
 * These tests pin the tenancy boundary of the shared object-name cache. Every
 * one of them is a control: each asserts a name belonging to organisation A is
 * NOT visible to a caller whose active organisation is B, through each of the
 * three paths that can produce a name — the organisation mapper, the object
 * mapper and the magic-table SQL — plus the cache path, which is the arm a
 * query-only fix would miss (a cache warmed as A must not be served to B).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One control per leaking path.
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
use OCP\DB\IResult;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IMemcache;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Cross-tenant disclosure controls for CacheHandler's name resolution.
 */
class CacheHandlerTenantScopeTest extends TestCase {
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
	 * The organisation the current caller is acting in, switched per test.
	 *
	 * @var string|null
	 */
	private ?string $activeOrganisation = null;

	/**
	 * Set up shared doubles.
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

		// No distributed hits by default; the in-memory arm is exercised explicitly.
		$this->nameDistributedCache->method('get')->willReturn(null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$this->userSession->method('getUser')->willReturn($user);

		// The active organisation follows $this->activeOrganisation so a single
		// handler can be read as tenant A and then as tenant B.
		$this->organisationMapper->method('getActiveOrganisationWithFallback')
			->willReturnCallback(fn (): ?string => $this->activeOrganisation);
		$this->organisationMapper->method('getDefaultOrganisationFromConfig')
			->willReturnCallback(fn (): ?string => $this->activeOrganisation);
		$this->organisationMapper->method('getOrganisationHierarchy')
			->willReturnCallback(fn (string $uuid): array => [$uuid]);

		$this->activeOrganisation = self::ORG_B;
	}

	/**
	 * Build a CacheHandler with the shared doubles.
	 *
	 * @param RegisterMapper|null $registerMapper Register mapper for magic-table queries
	 * @param SchemaMapper|null   $schemaMapper   Schema mapper for magic-table queries
	 * @param IDBConnection|null  $db             Database connection for magic-table queries
	 *
	 * @return CacheHandler
	 */
	private function buildHandler(
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
			$db
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
	 * Build a Register whose schema has magic mapping enabled.
	 *
	 * @param int   $id            Register id
	 * @param array $schemaIds     Schema ids
	 * @param array $configuration Register configuration
	 *
	 * @return Register
	 */
	private function createRegister(int $id, array $schemaIds, array $configuration): Register {
		$register = new Register();
		$ref = new ReflectionClass($register);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($register, $id);
		$register->setSchemas($schemaIds);
		$register->setConfiguration($configuration);
		return $register;
	}

	/**
	 * Build a Schema with a given id and slug.
	 *
	 * @param int    $id   Schema id
	 * @param string $slug Schema slug
	 *
	 * @return Schema
	 */
	private function createSchema(int $id, string $slug): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, $id);
		$schema->setSlug($slug);
		return $schema;
	}

	// =====================================================================
	// LEAK CONTROLS — each of these fails on the unscoped implementation.
	// =====================================================================

	/**
	 * A caller in organisation B must not resolve the name of an object owned
	 * by organisation A through the object-mapper path.
	 *
	 * @return void
	 */
	public function testObjectNameFromAnotherOrganisationIsNotDisclosed(): void {
		$this->organisationMapper->method('findMultipleByUuid')->willReturn([]);
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->createObject('11111111-1111-1111-1111-111111111111', 'Alpha Secret Dossier', self::ORG_A),
		]);

		$handler = $this->buildHandler();

		$names = $handler->getMultipleObjectNames(['11111111-1111-1111-1111-111111111111']);

		$this->assertArrayNotHasKey(
			'11111111-1111-1111-1111-111111111111',
			$names,
			'A caller in organisation B resolved the name of an object owned by organisation A.'
		);
	}

	/**
	 * A caller in organisation B must not resolve the NAME of organisation A.
	 *
	 * @return void
	 */
	public function testOrganisationNameFromAnotherTenantIsNotDisclosed(): void {
		$organisation = new Organisation();
		$organisation->setUuid(self::ORG_A);
		$organisation->setName('Gemeente Alpha');

		$this->organisationMapper->method('findMultipleByUuid')->willReturn([$organisation]);
		$this->objectMapper->method('findMultiple')->willReturn([]);

		$handler = $this->buildHandler();

		$names = $handler->getMultipleObjectNames([self::ORG_A]);

		$this->assertArrayNotHasKey(
			self::ORG_A,
			$names,
			'A caller in organisation B resolved organisation A\'s name.'
		);
	}

	/**
	 * THE CACHE ARM. Warm the shared name cache as tenant A, then read as
	 * tenant B: B must not be served A's names out of the warm cache.
	 *
	 * A query-only fix passes every other control in this file and fails here.
	 *
	 * @return void
	 */
	public function testCacheWarmedAsTenantAIsNotServedToTenantB(): void {
		$this->organisationMapper->method('findMultipleByUuid')->willReturn([]);
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->createObject('22222222-2222-2222-2222-222222222222', 'Alpha Payroll', self::ORG_A),
		]);

		$handler = $this->buildHandler();

		// Warm as tenant A — A may legitimately see its own object's name.
		$this->activeOrganisation = self::ORG_A;
		$warm = $handler->getMultipleObjectNames(['22222222-2222-2222-2222-222222222222']);
		$this->assertSame(
			'Alpha Payroll',
			$warm['22222222-2222-2222-2222-222222222222'] ?? null,
			'Positive control: tenant A must still resolve its own object name.'
		);

		// Read as tenant B — the entry is now in the in-memory name cache.
		$this->activeOrganisation = self::ORG_B;
		$leaked = $handler->getMultipleObjectNames(['22222222-2222-2222-2222-222222222222']);

		$this->assertArrayNotHasKey(
			'22222222-2222-2222-2222-222222222222',
			$leaked,
			'The warm name cache served organisation A\'s name to a caller in organisation B.'
		);
	}

	/**
	 * getAllObjectNames() must return only the caller's own tenant's names,
	 * even when the cache was warmed by another tenant's request.
	 *
	 * @return void
	 */
	public function testGetAllObjectNamesIsScopedToTheCallersOrganisation(): void {
		$this->organisationMapper->method('findMultipleByUuid')->willReturn([]);
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->createObject('33333333-3333-3333-3333-333333333333', 'Alpha Register Row', self::ORG_A),
			$this->createObject('44444444-4444-4444-4444-444444444444', 'Beta Register Row', self::ORG_B),
		]);

		$handler = $this->buildHandler();

		// Tenant A warms both entries into the shared cache.
		$this->activeOrganisation = self::ORG_A;
		$handler->getMultipleObjectNames([
			'33333333-3333-3333-3333-333333333333',
			'44444444-4444-4444-4444-444444444444',
		]);

		// Tenant B asks for everything the cache holds.
		$this->activeOrganisation = self::ORG_B;
		$all = $handler->getAllObjectNames(false);

		$this->assertArrayNotHasKey(
			'33333333-3333-3333-3333-333333333333',
			$all,
			'getAllObjectNames() disclosed an organisation A name to a caller in organisation B.'
		);
		$this->assertSame(
			'Beta Register Row',
			$all['44444444-4444-4444-4444-444444444444'] ?? null,
			'Positive control: tenant B must still see its own name.'
		);
	}

	/**
	 * The magic-table warmup must record each name's owning organisation and
	 * hand back only the caller's own tenant's rows.
	 *
	 * @return void
	 */
	public function testWarmupFromMagicTablesIsScopedByOrganisationColumn(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$db = $this->createMock(IDBConnection::class);

		$this->organisationMapper->method('findAllWithUserCount')->willReturn([]);
		$this->objectMapper->method('findAll')->willReturn([]);

		$registerMapper->method('findAll')->willReturn([
			$this->createRegister(1, [5], ['schemas' => ['test-schema' => ['magicMapping' => true]]]),
		]);
		$schemaMapper->method('find')->willReturn($this->createSchema(5, 'test-schema'));

		$queryResult = $this->createMock(IResult::class);
		$queryResult->method('fetch')->willReturnOnConsecutiveCalls(
			[
				'_uuid' => '55555555-5555-5555-5555-555555555555',
				'_name' => 'Alpha Magic Row',
				'_organisation' => self::ORG_A,
			],
			[
				'_uuid' => '66666666-6666-6666-6666-666666666666',
				'_name' => 'Beta Magic Row',
				'_organisation' => self::ORG_B,
			],
			false
		);
		$db->method('executeQuery')->willReturn($queryResult);

		$handler = $this->buildHandler($registerMapper, $schemaMapper, $db);

		$this->activeOrganisation = self::ORG_B;
		$handler->warmupNameCache();

		$all = $handler->getAllObjectNames(false);

		$this->assertArrayNotHasKey(
			'55555555-5555-5555-5555-555555555555',
			$all,
			'A magic-table row owned by organisation A was disclosed to a caller in organisation B.'
		);
		$this->assertSame(
			'Beta Magic Row',
			$all['66666666-6666-6666-6666-666666666666'] ?? null,
			'Positive control: the caller\'s own magic-table row must still resolve.'
		);
	}

	/**
	 * A name whose owning organisation cannot be established must not be
	 * served — the resolver fails closed, it does not guess.
	 *
	 * @return void
	 */
	public function testNameWithUnknownOrganisationIsNotDisclosed(): void {
		$this->organisationMapper->method('findMultipleByUuid')->willReturn([]);
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->createObject('77777777-7777-7777-7777-777777777777', 'Untenanted Row', null),
		]);

		$handler = $this->buildHandler();

		$names = $handler->getMultipleObjectNames(['77777777-7777-7777-7777-777777777777']);

		$this->assertArrayNotHasKey(
			'77777777-7777-7777-7777-777777777777',
			$names,
			'A name with no resolvable owning organisation was served to a tenant-scoped caller.'
		);
	}

	// =====================================================================
	// FUNCTIONAL CONTROLS — the shape the six internal callers depend on.
	// =====================================================================

	/**
	 * The in-scope path must keep working: a caller resolves names for its own
	 * organisation's objects and organisations in one call, keyed by UUID.
	 *
	 * This is the exact return shape ObjectsController::collectNamesForResponse,
	 * ObjectService::collectNamesForResults, ExportService, PerformanceHandler,
	 * MetadataHydrationHandler and MagicFacetHandler consume.
	 *
	 * @return void
	 */
	public function testInScopeNamesAreStillResolvedForEveryInternalCaller(): void {
		$organisation = new Organisation();
		$organisation->setUuid(self::ORG_B);
		$organisation->setName('Gemeente Beta');

		$this->organisationMapper->method('findMultipleByUuid')->willReturn([$organisation]);
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->createObject('88888888-8888-8888-8888-888888888888', 'Beta Dossier', self::ORG_B),
		]);

		$handler = $this->buildHandler();

		$names = $handler->getMultipleObjectNames([
			self::ORG_B,
			'88888888-8888-8888-8888-888888888888',
		]);

		$this->assertSame('Gemeente Beta', $names[self::ORG_B] ?? null);
		$this->assertSame('Beta Dossier', $names['88888888-8888-8888-8888-888888888888'] ?? null);
	}

	/**
	 * An empty identifier list is still answered with an empty map, without
	 * touching the database — the contract every caller relies on.
	 *
	 * @return void
	 */
	public function testEmptyIdentifierListStillReturnsEmptyMap(): void {
		$handler = $this->buildHandler();

		$this->assertSame([], $handler->getMultipleObjectNames([]));
	}

	/**
	 * THE CALLER CONTRACT. Scoping makes the answer a PARTIAL map: requested
	 * identifiers the caller may not see are simply absent, never present with a
	 * null/empty value and never an exception.
	 *
	 * That is the only behaviour change the six internal callers can observe, and
	 * every one of them already handles an absent key:
	 *
	 * - Controller\ObjectsController::collectNamesForResponse() returns the map
	 *   straight to the client; an absent key renders as the raw UUID.
	 *   (It only ever asks for UUIDs found inside an object the caller already
	 *   read, so its identifiers are in scope by construction.)
	 * - Service\ObjectService::collectNamesForResults() likewise returns the map.
	 * - Service\ExportService::resolveUuidNameMap() array_merge()s it onto a
	 *   pre-seeded map.
	 * - Service\Object\SaveObject\MetadataHydrationHandler tests
	 *   `empty($names[$uuid]) === false` and falls back to the UUID.
	 * - Service\Object\PerformanceHandler assigns the map to `relatedNames`.
	 * - Db\MagicMapper\MagicFacetHandler tests `isset($names[$value])` at both of
	 *   its single-value sites and falls back to a shortened UUID, and its batch
	 *   site foreach()es over exactly what came back.
	 *
	 * @return void
	 */
	public function testPartialMapContractHoldsForCallersThatIndexTheResult(): void {
		$this->organisationMapper->method('findMultipleByUuid')->willReturn([]);
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->createObject('99999999-9999-9999-9999-999999999999', 'Visible Beta', self::ORG_B),
			$this->createObject('aaaa1111-aaaa-1111-aaaa-111111111111', 'Hidden Alpha', self::ORG_A),
		]);

		$handler = $this->buildHandler();

		$names = $handler->getMultipleObjectNames([
			'99999999-9999-9999-9999-999999999999',
			'aaaa1111-aaaa-1111-aaaa-111111111111',
			'bbbb2222-bbbb-2222-bbbb-222222222222',
		]);

		// Present for the in-scope id...
		$this->assertSame('Visible Beta', $names['99999999-9999-9999-9999-999999999999'] ?? null);
		// ...and ABSENT — not null, not '' — for the out-of-scope and unknown ones.
		$this->assertArrayNotHasKey('aaaa1111-aaaa-1111-aaaa-111111111111', $names);
		$this->assertArrayNotHasKey('bbbb2222-bbbb-2222-bbbb-222222222222', $names);

		// Every value in the map is a non-empty string, which is what the callers
		// that index it (MetadataHydrationHandler, MagicFacetHandler) rely on.
		foreach ($names as $uuid => $name) {
			$this->assertIsString($name, 'Name for ' . $uuid . ' must be a string.');
			$this->assertNotSame('', $name);
		}
	}
}
