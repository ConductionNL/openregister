<?php

/**
 * A new organisation never takes over an existing one through its slug.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Tests\Unit\Controller\Support\OrganisationControllerFactory;
use OC\DB\Exceptions\DbalException;
use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Db\Support\ConfigServices;
use OCA\OpenRegister\Exception\OrganisationSlugConflictException;
use OCA\OpenRegister\Service\ObjectSource\OrganisationActingUser;
use OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Runs the organisation create path, endpoint to SQL, against the migrated table.
 *
 * @covers \OCA\OpenRegister\Db\OrganisationMapper
 * @covers \OCA\OpenRegister\Service\OrganisationService
 * @covers \OCA\OpenRegister\Controller\OrganisationController
 * @uses \OCA\OpenRegister\Controller\Support\OrganisationMappers
 * @uses \OCA\OpenRegister\Controller\Support\OrganisationDomain
 * @uses \OCA\OpenRegister\Controller\Support\OrganisationAuditing
 * @uses \OCA\OpenRegister\Controller\Support\OrganisationControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 * @covers \OCA\OpenRegister\Exception\OrganisationSlugConflictException
 * @covers \OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider
 * @uses   \OCA\OpenRegister\AppInfo\ClassAliasMap
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\OrganisationCreatedEvent
 * @uses   \OCA\OpenRegister\Event\OrganisationUpdatedEvent
 */
class OrganisationSlugConflictTest extends TestCase {

	private const TABLE = 'openregister_organisations';

	private const FIRST_UUID = '11111111-1111-4111-8111-111111111111';

	private const SECOND_UUID = '22222222-2222-4222-8222-222222222222';

	private MigratedSqliteDatabase $database;

	private OrganisationMapper $mapper;

	private ?IUser $actingUser = null;

	/**
	 * The app config values the service reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $appValues = [];

	/**
	 * The request body the controller reads.
	 *
	 * @var array<string, mixed>
	 */
	private array $body = [];

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, [self::TABLE]);
		$this->mapper = $this->mapperOn(database: $this->database);
		$this->resetServiceStatics();
	}//end setUp()

	protected function tearDown(): void {
		$this->resetServiceStatics();
	}//end tearDown()

	/**
	 * Through the endpoint, a name that slugifies like an existing organisation's is refused
	 * with 409, and the existing organisation keeps its owner, members and uuid.
	 *
	 * The database names the violated index, as MySQL and PostgreSQL do.
	 */
	public function testTheEndpointRefusesANameThatTakesAnExistingSlug(): void {
		$this->database->nameTheIndexInUniqueErrors();
		$this->existingOrganisation();

		$this->actAs(userId: 'mallory');
		$response = $this->controller()->create(name: 'gemeente  A!');

		$this->assertSame(['gemeente-a'], $this->column(name: 'slug'));
		$this->assertSame(['alice'], $this->column(name: 'owner'));
		$this->assertSame([self::FIRST_UUID], $this->column(name: 'uuid'));
		$this->assertSame([['alice', 'bob']], array_map(fn ($users) => json_decode((string) $users, true), $this->column(name: 'users')));
		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'An organisation with this name already exists.'], $response->getData());
	}//end testTheEndpointRefusesANameThatTakesAnExistingSlug()

	/**
	 * A uuid in the request body is not written onto the existing organisation either.
	 */
	public function testTheEndpointDoesNotMoveTheExistingUuid(): void {
		$this->database->nameTheIndexInUniqueErrors();
		$this->existingOrganisation();

		$this->actAs(userId: 'mallory');
		$this->body = ['uuid' => self::SECOND_UUID];
		$response = $this->controller()->create(name: 'Gemeente A');

		$this->assertSame([self::FIRST_UUID], $this->column(name: 'uuid'));
		$this->assertSame(['alice'], $this->column(name: 'owner'));
		$this->assertSame(409, $response->getStatus());
	}//end testTheEndpointDoesNotMoveTheExistingUuid()

	/**
	 * On a database that names only the column (SQLite), the refusal is the same.
	 */
	public function testTheMapperRefusesATakenSlugWhateverTheDatabaseReports(): void {
		$this->existingOrganisation();

		$thrown = $this->saveCatching(organisation: $this->newOrganisation(slug: 'gemeente-a', owner: 'mallory'));

		$this->assertInstanceOf(OrganisationSlugConflictException::class, $thrown);
		$this->assertSame('gemeente-a', $thrown->getSlug());
		$this->assertSame(['alice'], $this->column(name: 'owner'));
		$this->assertSame([self::FIRST_UUID], $this->column(name: 'uuid'));
	}//end testTheMapperRefusesATakenSlugWhateverTheDatabaseReports()

	/**
	 * When another write takes the slug between the check and the insert, the unique
	 * index refuses the insert and save() answers the same conflict, never an update.
	 */
	public function testASlugTakenAfterTheCheckIsStillAConflict(): void {
		$this->database->nameTheIndexInUniqueErrors();
		$this->existingOrganisation();
		$racing = $this->mapperMissingTheFirstSlugLookup();

		$thrown = null;
		try {
			$racing->save($this->newOrganisation(slug: 'gemeente-a', owner: 'mallory'));
		} catch (\Throwable $e) {
			$thrown = $e;
		}

		$this->assertSame(['alice'], $this->column(name: 'owner'));
		$this->assertSame([self::FIRST_UUID], $this->column(name: 'uuid'));
		$this->assertInstanceOf(OrganisationSlugConflictException::class, $thrown);
		$this->assertNotNull($thrown->getPrevious());
	}//end testASlugTakenAfterTheCheckIsStillAConflict()

	/**
	 * A failed insert whose slug is still free is passed on as the database said it.
	 */
	public function testAnInsertFailureOnAnotherColumnIsPassedOn(): void {
		$this->existingOrganisation();
		$organisation = $this->newOrganisation(slug: 'gemeente-b', owner: 'mallory');
		$organisation->setName(null);

		$thrown = $this->saveCatching(organisation: $organisation);

		$this->assertNotNull($thrown);
		$this->assertNotInstanceOf(OrganisationSlugConflictException::class, $thrown);
		$this->assertSame(['gemeente-a'], $this->column(name: 'slug'));
	}//end testAnInsertFailureOnAnotherColumnIsPassedOn()

	/**
	 * A new organisation with a free slug, or with none, is inserted.
	 */
	public function testANewOrganisationWithAFreeOrNoSlugIsInserted(): void {
		$this->existingOrganisation();

		$this->mapper->save($this->newOrganisation(slug: 'gemeente-b', owner: 'carol'));
		$this->mapper->save($this->newOrganisation(slug: null, owner: 'dave'));
		$this->mapper->save($this->newOrganisation(slug: null, owner: 'erin'));

		$this->assertSame(['alice', 'carol', 'dave', 'erin'], $this->column(name: 'owner'));
	}//end testANewOrganisationWithAFreeOrNoSlugIsInserted()

	/**
	 * An organisation that already has an id is updated in place.
	 */
	public function testSavingAnExistingOrganisationUpdatesIt(): void {
		$existing = $this->existingOrganisation();

		$existing->setDescription('Renamed department');
		$this->mapper->save($existing);

		$this->assertSame(['Renamed department'], $this->column(name: 'description'));
		$this->assertSame([self::FIRST_UUID], $this->column(name: 'uuid'));
	}//end testSavingAnExistingOrganisationUpdatesIt()

	/**
	 * Creating the default organisation when its slug is taken uses that organisation
	 * as it is: its uuid and owner stay.
	 */
	public function testTheDefaultOrganisationAdoptsTheRowHoldingItsSlug(): void {
		$this->database->nameTheIndexInUniqueErrors();
		$existing = $this->newOrganisation(slug: 'default-organisation', owner: 'alice');
		$existing->setName('Default Organisation');
		$existing->setUuid(self::FIRST_UUID);
		$this->mapper->save($existing);

		$default = $this->service()->ensureDefaultOrganisation();

		$this->assertSame(self::FIRST_UUID, $default->getUuid());
		$this->assertSame([self::FIRST_UUID], $this->column(name: 'uuid'));
		$this->assertSame(['alice'], $this->column(name: 'owner'));
		$this->assertSame(self::FIRST_UUID, ($this->appValues['defaultOrganisation'] ?? ''));
	}//end testTheDefaultOrganisationAdoptsTheRowHoldingItsSlug()

	/**
	 * Creating through the organisation projection is refused the same way, so the
	 * projected fields are never written onto the existing organisation.
	 */
	public function testTheProjectionDoesNotWriteOverAnExistingOrganisation(): void {
		$this->database->nameTheIndexInUniqueErrors();
		$this->existingOrganisation();

		$this->actAs(userId: 'mallory');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn (): ?IUser => $this->actingUser);
		$provider = new OrganisationObjectSourceProvider(
			organisationMapper: $this->mapper,
			actingUser: new OrganisationActingUser(userSession: $userSession, groupManager: $this->createMock(IGroupManager::class)),
			logger: new NullLogger(),
			organisationService: $this->service()
		);

		$thrown = null;
		try {
			$provider->insert(register: new Register(), schema: new Schema(), data: ['name' => 'Gemeente-A', 'description' => 'Taken over']);
		} catch (\Throwable $e) {
			$thrown = $e;
		}

		$this->assertSame(['alice'], $this->column(name: 'owner'));
		$this->assertSame([''], $this->column(name: 'description'));
		$this->assertSame([self::FIRST_UUID], $this->column(name: 'uuid'));
		$this->assertInstanceOf(OrganisationSlugConflictException::class, $thrown);
	}//end testTheProjectionDoesNotWriteOverAnExistingOrganisation()

	/**
	 * When the save fails with a unique violation, the service passes the failure on:
	 * it does not look the slug up and move the requested uuid onto that organisation.
	 */
	public function testTheServiceDoesNotAdoptTheOrganisationHoldingTheSlug(): void {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->expects($this->once())->method('save')->willThrowException(
			$this->uniqueViolation(message: "Duplicate entry 'gemeente-a' for key 'openregister_organisations.organisations_slug_unique'")
		);
		$mapper->expects($this->never())->method('findBySlug');
		$this->mapper = $mapper;
		$this->actAs(userId: 'mallory');

		$this->expectException(DbalException::class);
		$this->service()->createOrganisation(name: 'Gemeente A', uuid: self::SECOND_UUID);
	}//end testTheServiceDoesNotAdoptTheOrganisationHoldingTheSlug()

	/**
	 * A database failure with this message.
	 *
	 * The server's DbalException has a private constructor and is built from a Doctrine
	 * exception by wrap(), which the test stub of the class does not have. Building it
	 * without the constructor works against both.
	 *
	 * @param string $message The failure's message.
	 *
	 * @return DbalException
	 */
	private function uniqueViolation(string $message): DbalException {
		$exception = (new \ReflectionClass(DbalException::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(\Exception::class, 'message'))->setValue($exception, $message);

		return $exception;
	}//end uniqueViolation()

	/**
	 * Alice's organisation "Gemeente A", with Bob as a member, created through the service.
	 *
	 * @return Organisation
	 */
	private function existingOrganisation(): Organisation {
		$this->actAs(userId: 'alice');
		$organisation = $this->service()->createOrganisation(name: 'Gemeente A', uuid: self::FIRST_UUID);
		$organisation->addUser('bob');

		return $this->mapper->save($organisation);
	}//end existingOrganisation()

	/**
	 * A new, unsaved organisation.
	 *
	 * @param string|null $slug  The slug.
	 * @param string      $owner The owner.
	 *
	 * @return Organisation
	 */
	private function newOrganisation(?string $slug, string $owner): Organisation {
		$organisation = new Organisation();
		$organisation->setName('Organisation of ' . $owner);
		$organisation->setSlug($slug);
		$organisation->setOwner($owner);
		$organisation->setUsers([$owner]);

		return $organisation;
	}//end newOrganisation()

	/**
	 * Save through the mapper and return what it threw, if anything.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return \Throwable|null
	 */
	private function saveCatching(Organisation $organisation): ?\Throwable {
		try {
			$this->mapper->save($organisation);
		} catch (\Throwable $e) {
			return $e;
		}

		return null;
	}//end saveCatching()

	/**
	 * One column of every organisation row, in id order.
	 *
	 * @param string $name The column.
	 *
	 * @return list<mixed>
	 */
	private function column(string $name): array {
		return $this->database->connection()->fetchFirstColumn('SELECT "' . $name . '" FROM ' . self::TABLE . ' ORDER BY id');
	}//end column()

	/**
	 * Make the given user the signed-in user.
	 *
	 * @param string $userId The user id.
	 *
	 * @return void
	 */
	private function actAs(string $userId): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->actingUser = $user;
	}//end actAs()

	/**
	 * The real organisation service over the real mapper.
	 *
	 * @return OrganisationService
	 */
	private function service(): OrganisationService {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn (): ?IUser => $this->actingUser);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appValues[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appValues[$key] = $value;
				return true;
			}
		);

		return new OrganisationService(
			organisationMapper: $this->mapper,
			userSession: $userSession,
			session: $this->createMock(ISession::class),
			config: $this->createMock(IConfig::class),
			appConfig: $appConfig,
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * The real organisation controller over the real service.
	 *
	 * @return OrganisationController
	 */
	private function controller(): OrganisationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->body);

		return OrganisationControllerFactory::create(
			appName: 'openregister',
			request: $request,
			organisationService: $this->service(),
			organisationMapper: $this->mapper,
			logger: new NullLogger(),
			tenantLifecycleService: $this->createMock(TenantLifecycleService::class),
			tenantUsageMapper: $this->createMock(TenantUsageMapper::class),
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			usageService: $this->createMock(OrganisationUsageService::class)
		);
	}//end controller()

	/**
	 * The real mapper on the migrated database.
	 *
	 * @param MigratedSqliteDatabase $database The database.
	 *
	 * @return OrganisationMapper
	 */
	private function mapperOn(MigratedSqliteDatabase $database): OrganisationMapper {
		return new OrganisationMapper(
			db: $database->idbConnection(),
			logger: new NullLogger(),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			configs: new ConfigServices($this->createMock(IAppConfig::class), $this->createMock(IConfig::class))
		);
	}//end mapperOn()

	/**
	 * A mapper whose first slug lookup misses, as when another write lands in between.
	 *
	 * @return OrganisationMapper
	 */
	private function mapperMissingTheFirstSlugLookup(): OrganisationMapper {
		return new class($this->database->idbConnection(), $this->createMock(IEventDispatcher::class), $this->createMock(IAppConfig::class), $this->createMock(IConfig::class)) extends OrganisationMapper {
			private int $lookups = 0;

			public function __construct($db, $dispatcher, $appConfig, $config) {
				parent::__construct(db: $db, logger: new NullLogger(), eventDispatcher: $dispatcher, configs: new ConfigServices($appConfig, $config));
			}

			public function findBySlug(string $slug): Organisation {
				$this->lookups++;
				if ($this->lookups === 1) {
					throw new DoesNotExistException('missed');
				}

				return parent::findBySlug(slug: $slug);
			}
		};
	}//end mapperMissingTheFirstSlugLookup()

	/**
	 * Clear the service's static organisation caches.
	 *
	 * @return void
	 */
	private function resetServiceStatics(): void {
		$reflection = new \ReflectionClass(OrganisationService::class);
		foreach (['defaultOrgCache' => null, 'defaultOrgCacheTs' => null, 'userOrgsCache' => []] as $property => $value) {
			if ($reflection->hasProperty($property) === true) {
				$reflection->getProperty($property)->setValue(null, $value);
			}
		}
	}//end resetServiceStatics()
}//end class
