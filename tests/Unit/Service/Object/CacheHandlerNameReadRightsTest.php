<?php

declare(strict_types=1);

/**
 * CacheHandler name lookups follow the caller's read rights
 *
 * The rule these tests pin: a caller gets the name of every object they may
 * read, and nothing for an object they may not read. "May read" is answered by
 * the object read path itself (MagicMapper::filterReadableUuids, which applies
 * the same RBAC + multitenancy filter as GET /api/objects/{r}/{s}/{id}), not by
 * a second, organisation-only rule of the name cache.
 *
 * The bug this closes (Woo round 3, 2026-10-02): a non-admin with a schema read
 * grant (tilburg-demo, group gebruik-beheerder, schema `module`) could read the
 * objects but `POST /api/names` answered `[]`, because the objects belong to
 * another organisation than the caller's active one. Admin only "worked"
 * because admin's active organisation happened to own the rows.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Tests need to mock many dependencies.
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\IAppContainer;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IMemcache;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Names are disclosed exactly where the object is readable.
 */
#[CoversClass(CacheHandler::class)]
class CacheHandlerNameReadRightsTest extends TestCase {
	/** The caller's active organisation. */
	private const ORG_CALLER = 'cccccccc-0000-0000-0000-000000000001';

	/** Another organisation that owns the module rows. */
	private const ORG_OTHER = 'dddddddd-0000-0000-0000-000000000002';

	private const MODULE_UUID = '571ed9ec-4ebc-4537-8d6a-e349f293adc0';

	private const SECRET_UUID = '11111111-2222-3333-4444-555555555555';

	/** @var MagicMapper */
	private MagicMapper $objectMapper;

	/** @var OrganisationMapper */
	private OrganisationMapper $organisationMapper;

	/** @var IMemcache */
	private IMemcache $nameDistributedCache;

	/** @var ICacheFactory */
	private ICacheFactory $cacheFactory;

	/** @var IUserSession */
	private IUserSession $userSession;

	/** @var IGroupManager */
	private IGroupManager $groupManager;

	/**
	 * The user the session currently answers with.
	 *
	 * @var string
	 */
	private string $uid = 'tilburg-demo';

	/**
	 * Per user, the UUIDs the object read path admits (the stand-in for RBAC + multitenancy).
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $readableByUser = [];

	/**
	 * Every call the handler made to the read-path oracle.
	 *
	 * @var array<int, array{registerId: int, schemaId: int, uuids: array<int, string>}>
	 */
	private array $oracleCalls = [];

	/**
	 * The distributed cache, as a plain array.
	 *
	 * @var array<string, mixed>
	 */
	private array $distributed = [];

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->nameDistributedCache = $this->createMock(IMemcache::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->cacheFactory->method('createDistributed')->willReturnCallback(
			fn (string $prefix) => match ($prefix) {
				'openregister_object_names' => $this->nameDistributedCache,
				default => $this->createMock(IMemcache::class),
			}
		);
		$this->nameDistributedCache->method('get')->willReturnCallback(
			fn (string $key) => ($this->distributed[$key] ?? null)
		);
		$this->nameDistributedCache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->distributed[$key] = $value;
				return true;
			}
		);

		$this->userSession->method('getUser')->willReturnCallback(
			function (): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'admin');

		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_CALLER);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturnCallback(fn (string $uuid): array => [$uuid]);
		$this->organisationMapper->method('findMultipleByUuid')->willReturn([]);

		$this->objectMapper->method('filterReadableUuids')->willReturnCallback(
			function (int $registerId, int $schemaId, array $uuids): array {
				$this->oracleCalls[] = ['registerId' => $registerId, 'schemaId' => $schemaId, 'uuids' => array_values($uuids)];
				return array_values(array_intersect($uuids, ($this->readableByUser[$this->uid] ?? [])));
			}
		);
	}//end setUp()

	/**
	 * Build the handler with the shared doubles.
	 *
	 * @return CacheHandler
	 */
	private function buildHandler(): CacheHandler {
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			fn (string $class) => match ($class) {
				MagicMapper::class => $this->objectMapper,
				default => $this->createMock($class),
			}
		);

		return new CacheHandler(
			organisationMapper: $this->organisationMapper,
			logger: $this->createMock(LoggerInterface::class),
			cacheFactory: $this->cacheFactory,
			userSession: $this->userSession,
			container: $container,
			groupManager: $this->groupManager
		);
	}//end buildHandler()

	/**
	 * An object as the magic-table read returns it: with its register and schema.
	 *
	 * @param string      $uuid         Object UUID
	 * @param string      $name         Object name
	 * @param string|null $organisation Owning organisation
	 *
	 * @return ObjectEntity
	 */
	private function moduleObject(string $uuid, string $name, ?string $organisation): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		$object->setOrganisation($organisation);
		$object->setRegister('26');
		$object->setSchema('961');
		return $object;
	}//end moduleObject()

	/**
	 * THE BUG. A non-admin who may read an object owned by another organisation
	 * gets its name.
	 *
	 * @return void
	 */
	public function testNonAdminWhoCanReadAnObjectGetsItsName(): void {
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->moduleObject(self::MODULE_UUID, 'iBurgerzaken', self::ORG_OTHER),
		]);
		$this->readableByUser['tilburg-demo'] = [self::MODULE_UUID];

		$names = $this->buildHandler()->getMultipleObjectNames([self::MODULE_UUID]);

		$this->assertSame(['571ed9ec-4ebc-4537-8d6a-e349f293adc0' => 'iBurgerzaken'], $names);
		$this->assertSame(26, $this->oracleCalls[0]['registerId'] ?? null, 'The read path is asked about the object\'s own table.');
		$this->assertSame(961, $this->oracleCalls[0]['schemaId'] ?? null);
	}//end testNonAdminWhoCanReadAnObjectGetsItsName()

	/**
	 * THE LEAK CONTROL. A non-admin who may NOT read an object gets nothing for
	 * it, even when the object belongs to the caller's own organisation.
	 *
	 * @return void
	 */
	public function testNonAdminWhoCannotReadAnObjectGetsNothingForIt(): void {
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->moduleObject(self::SECRET_UUID, 'Admin-only batch', self::ORG_CALLER),
		]);
		$this->readableByUser['tilburg-demo'] = [];

		$names = $this->buildHandler()->getMultipleObjectNames([self::SECRET_UUID]);

		$this->assertSame([], $names, 'A name was disclosed for an object the caller may not read.');
	}//end testNonAdminWhoCannotReadAnObjectGetsNothingForIt()

	/**
	 * Admin is unchanged: admin may read the object, so admin gets the name.
	 *
	 * @return void
	 */
	public function testAdminStillGetsTheName(): void {
		$this->uid = 'admin';
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->moduleObject(self::MODULE_UUID, 'iBurgerzaken', self::ORG_CALLER),
		]);
		$this->readableByUser['admin'] = [self::MODULE_UUID];

		$names = $this->buildHandler()->getMultipleObjectNames([self::MODULE_UUID]);

		$this->assertSame('iBurgerzaken', $names[self::MODULE_UUID] ?? null);
	}//end testAdminStillGetsTheName()

	/**
	 * THE CACHE ARM. A name cached while serving a caller who may read the
	 * object is not served to a caller who may not, neither from the in-memory
	 * cache nor from the distributed one.
	 *
	 * @return void
	 */
	public function testACachedNameIsOnlyServedToACallerWhoMayReadTheObject(): void {
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->moduleObject(self::MODULE_UUID, 'iBurgerzaken', self::ORG_OTHER),
		]);
		$this->readableByUser['admin'] = [self::MODULE_UUID];
		$this->readableByUser['outsider'] = [];

		$handler = $this->buildHandler();

		$this->uid = 'admin';
		$this->assertSame('iBurgerzaken', $handler->getMultipleObjectNames([self::MODULE_UUID])[self::MODULE_UUID] ?? null);

		// Same process, warm in-memory cache.
		$this->uid = 'outsider';
		$this->assertSame([], $handler->getMultipleObjectNames([self::MODULE_UUID]), 'The in-memory cache disclosed the name.');

		// A fresh process, warm distributed cache only.
		$this->assertSame([], $this->buildHandler()->getMultipleObjectNames([self::MODULE_UUID]), 'The distributed cache disclosed the name.');

		// And the distributed entry still serves a caller who may read it.
		$this->uid = 'admin';
		$this->assertSame('iBurgerzaken', $this->buildHandler()->getMultipleObjectNames([self::MODULE_UUID])[self::MODULE_UUID] ?? null);
	}//end testACachedNameIsOnlyServedToACallerWhoMayReadTheObject()

	/**
	 * getAllObjectNames() follows the same rule.
	 *
	 * @return void
	 */
	public function testGetAllObjectNamesFollowsReadRights(): void {
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->moduleObject(self::MODULE_UUID, 'iBurgerzaken', self::ORG_OTHER),
			$this->moduleObject(self::SECRET_UUID, 'Admin-only batch', self::ORG_CALLER),
		]);
		$this->readableByUser['admin'] = [self::MODULE_UUID, self::SECRET_UUID];
		$this->readableByUser['tilburg-demo'] = [self::MODULE_UUID];

		$handler = $this->buildHandler();
		$this->uid = 'admin';
		$handler->getMultipleObjectNames([self::MODULE_UUID, self::SECRET_UUID]);

		$this->uid = 'tilburg-demo';
		$this->assertSame([self::MODULE_UUID => 'iBurgerzaken'], $handler->getAllObjectNames());
	}//end testGetAllObjectNamesFollowsReadRights()

	/**
	 * A distributed entry written before this change (no read-path location) is
	 * a miss, not a name: it is resolved again from the database.
	 *
	 * @return void
	 */
	public function testALegacyDistributedEntryIsResolvedAgain(): void {
		$this->distributed['name_' . self::MODULE_UUID] = ['n' => 'Stale', 'o' => self::ORG_CALLER];
		$this->objectMapper->method('findMultiple')->willReturn([
			$this->moduleObject(self::MODULE_UUID, 'iBurgerzaken', self::ORG_OTHER),
		]);
		$this->readableByUser['tilburg-demo'] = [self::MODULE_UUID];

		$names = $this->buildHandler()->getMultipleObjectNames([self::MODULE_UUID]);

		$this->assertSame('iBurgerzaken', $names[self::MODULE_UUID] ?? null);
	}//end testALegacyDistributedEntryIsResolvedAgain()

	/**
	 * An object whose table cannot be established is not disclosed: there is no
	 * read rule to ask.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutATableIsNotDisclosed(): void {
		$object = new ObjectEntity();
		$object->setUuid(self::MODULE_UUID);
		$object->setName('Nowhere');
		$this->objectMapper->method('findMultiple')->willReturn([$object]);
		$this->readableByUser['tilburg-demo'] = [self::MODULE_UUID];

		$this->assertSame([], $this->buildHandler()->getMultipleObjectNames([self::MODULE_UUID]));
	}//end testAnObjectWithoutATableIsNotDisclosed()

	/**
	 * Organisation names keep their own rule (the caller's organisation scope):
	 * an organisation is not an object, and has no read path to ask.
	 *
	 * @return void
	 */
	public function testOrganisationNamesKeepTheOrganisationScope(): void {
		$own = new Organisation();
		$own->setUuid(self::ORG_CALLER);
		$own->setName('Gemeente Tilburg');
		$other = new Organisation();
		$other->setUuid(self::ORG_OTHER);
		$other->setName('Elders');

		$organisationMapper = $this->createMock(OrganisationMapper::class);
		$organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_CALLER);
		$organisationMapper->method('getOrganisationHierarchy')->willReturnCallback(fn (string $uuid): array => [$uuid]);
		$organisationMapper->method('findMultipleByUuid')->willReturn([$own, $other]);
		$this->organisationMapper = $organisationMapper;
		$this->objectMapper->method('findMultiple')->willReturn([]);

		$names = $this->buildHandler()->getMultipleObjectNames([self::ORG_CALLER, self::ORG_OTHER]);

		$this->assertSame([self::ORG_CALLER => 'Gemeente Tilburg'], $names);
		$this->assertSame([], $this->oracleCalls, 'An organisation name never asks the object read path.');
	}//end testOrganisationNamesKeepTheOrganisationScope()
}//end class
