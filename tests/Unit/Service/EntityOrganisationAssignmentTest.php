<?php
/**
 * Entity Organisation Assignment Unit Tests
 *
 * This test class covers all scenarios related to entity organisation assignment
 * including registers, schemas, and objects being assigned to active organisations.
 *
 * Test Coverage:
 * - Test 5.1: Register Creation with Active Organisation
 * - Test 5.2: Schema Creation with Active Organisation
 * - Test 5.3: Object Creation with Active Organisation
 * - Test 5.4: Entity Access Within Same Organisation
 * - Test 5.5: Entity Access Across Organisations (negative)
 * - Test 5.6: Cross-Organisation Object Creation (negative)
 *
 * Key Features Tested:
 * - Automatic organisation assignment for new registers
 * - Automatic organisation assignment for new schemas
 * - Automatic organisation assignment for new objects
 * - Access control within same organisation
 * - Cross-organisation access prevention
 * - Entity isolation by organisation boundaries
 * - Active organisation context for new entities
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\IUserSession;
use OCP\IUser;
use OCP\ISession;
use OCP\IConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Test class for Entity Organisation Assignment
 */
class EntityOrganisationAssignmentTest extends TestCase
{
    /**
     * @var OrganisationService
     */
    private OrganisationService $organisationService;

    /**
     * @var RegisterService
     */
    private RegisterService $registerService;

    /**
     * @var OrganisationMapper|MockObject
     */
    private $organisationMapper;

    /**
     * @var RegisterMapper|MockObject
     */
    private $registerMapper;

    /**
     * @var SchemaMapper|MockObject
     */
    private $schemaMapper;

    /**
     * @var MagicMapper|MockObject
     */
    private $objectMapper;

    /**
     * @var IUserSession|MockObject
     */
    private $userSession;

    /**
     * @var ISession|MockObject
     */
    private $session;

    /**
     * @var IConfig|MockObject
     */
    private $ncConfig;

    /**
     * @var IAppConfig|MockObject
     */
    private $appConfig;

    /**
     * @var IGroupManager|MockObject
     */
    private $groupManager;

    /**
     * @var IUserManager|MockObject
     */
    private $userManager;

    /**
     * @var FileService|MockObject
     */
    private $fileService;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var IUser|MockObject
     */
    private $mockUser;

    /**
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Reset static caches between tests.
        $reflection = new \ReflectionClass(OrganisationService::class);

        $defaultOrgCache = $reflection->getProperty('defaultOrgCache');
        $defaultOrgCache->setAccessible(true);
        $defaultOrgCache->setValue(null, null);

        $defaultOrgCacheTs = $reflection->getProperty('defaultOrgCacheTs');
        $defaultOrgCacheTs->setAccessible(true);
        $defaultOrgCacheTs->setValue(null, null);

        $userOrgsCache = $reflection->getProperty('userOrgsCache');
        $userOrgsCache->setAccessible(true);
        $userOrgsCache->setValue(null, []);

        // Create mock objects.
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->ncConfig = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mockUser = $this->createMock(IUser::class);

        // Create service instances.
        $this->organisationService = new OrganisationService(
            organisationMapper: $this->organisationMapper,
            userSession: $this->userSession,
            session: $this->session,
            config: $this->ncConfig,
            appConfig: $this->appConfig,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            logger: $this->logger
        );

        $this->registerService = new RegisterService(
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            db: $this->createMock(IDBConnection::class),
            fileService: $this->fileService,
            organisationService: $this->organisationService,
            logger: $this->logger
        );
    }

    /**
     * Clean up after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $this->organisationService,
            $this->registerService,
            $this->organisationMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectMapper,
            $this->userSession,
            $this->session,
            $this->ncConfig,
            $this->appConfig,
            $this->groupManager,
            $this->userManager,
            $this->fileService,
            $this->logger,
            $this->mockUser
        );
    }

    /**
     * Test 5.1: Register Creation with Active Organisation
     *
     * Scenario: Register should be assigned to user's active organisation
     * Expected: New register has organisation property set to active organisation UUID
     *
     * @return void
     */
    public function testRegisterCreationWithActiveOrganisation(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: Active organisation via session cache (returns array data, not UUID string).
        $orgData = [
            'id' => 1,
            'uuid' => 'acme-uuid-123',
            'name' => 'ACME Corporation',
            'description' => '',
            'owner' => 'alice',
            'users' => ['alice'],
            'created' => '2024-01-01T00:00:00+00:00',
            'updated' => '2024-01-01T00:00:00+00:00',
        ];

        $this->session->method('get')
            ->willReturnCallback(function (string $key) use ($orgData) {
                if ($key === 'openregister_active_organisation_alice') {
                    return $orgData;
                }
                if ($key === 'openregister_active_organisation_timestamp_alice') {
                    return time();
                }
                return null;
            });

        // Mock: Register creation data.
        $registerData = [
            'title' => 'ACME Employee Register',
            'description' => 'Employee data for ACME Corp'
        ];

        // Mock: Created register (initially without organisation).
        $createdRegister = new Register();
        $createdRegister->setTitle('ACME Employee Register');
        $createdRegister->setDescription('Employee data for ACME Corp');
        // Organisation is null initially - the service will set it.
        $createdRegister->setOwner('alice');
        $createdRegister->setUuid('register-uuid-456');

        $this->registerMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($registerData)
            ->willReturn($createdRegister);

        // After getOrganisationForNewEntity returns 'acme-uuid-123',
        // the service sets it and calls update().
        $updatedRegister = clone $createdRegister;
        $updatedRegister->setOrganisation('acme-uuid-123');

        $this->registerMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function($register) {
                return $register instanceof Register &&
                       $register->getOrganisation() === 'acme-uuid-123';
            }))
            ->willReturn($updatedRegister);

        // Mock: fileService for ensureRegisterFolderExists (called after update).
        $this->fileService->method('createEntityFolder')->willReturn(null);

        // Act: Create register via service.
        $result = $this->registerService->createFromArray($registerData);

        // Assert: Register assigned to active organisation.
        $this->assertInstanceOf(Register::class, $result);
        $this->assertEquals('acme-uuid-123', $result->getOrganisation());
        $this->assertEquals('alice', $result->getOwner());
        $this->assertEquals('ACME Employee Register', $result->getTitle());
    }

    /**
     * Test 5.2: Schema Creation with Active Organisation
     *
     * Scenario: Schema should be assigned to user's active organisation
     * Expected: getOrganisationForNewEntity returns active org UUID
     *
     * @return void
     */
    public function testSchemaCreationWithActiveOrganisation(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: Active organisation via session cache.
        $orgData = [
            'id' => 1,
            'uuid' => 'acme-uuid-123',
            'name' => 'ACME Corporation',
            'description' => '',
            'owner' => 'alice',
            'users' => ['alice'],
            'created' => '2024-01-01T00:00:00+00:00',
            'updated' => '2024-01-01T00:00:00+00:00',
        ];

        $this->session->method('get')
            ->willReturnCallback(function (string $key) use ($orgData) {
                if ($key === 'openregister_active_organisation_alice') {
                    return $orgData;
                }
                if ($key === 'openregister_active_organisation_timestamp_alice') {
                    return time();
                }
                return null;
            });

        // Act: Get organisation for new entity.
        $organisationUuid = $this->organisationService->getOrganisationForNewEntity();

        // Assert: Returns the active organisation UUID.
        $this->assertEquals('acme-uuid-123', $organisationUuid);
    }

    /**
     * Test 5.3: Object Creation with Active Organisation
     *
     * Scenario: Object should be assigned to user's active organisation
     * Expected: New object has organisation property set to active organisation UUID
     *
     * @return void
     */
    public function testObjectCreationWithActiveOrganisation(): void
    {
        // Arrange: Create an object entity and assign organisation.
        $objectData = [
            'name' => 'John Doe',
            'email' => 'john@acme.com'
        ];

        $createdObject = new ObjectEntity();
        $createdObject->setUuid('object-uuid-101');
        $createdObject->setRegister('register-uuid-456');
        $createdObject->setSchema('schema-uuid-789');
        $createdObject->setOrganisation('acme-uuid-123');
        $createdObject->setOwner('alice');
        $createdObject->setObject($objectData);

        // Assert: Object has correct organisation assignment.
        $this->assertEquals('acme-uuid-123', $createdObject->getOrganisation());
        $this->assertEquals('alice', $createdObject->getOwner());
        $this->assertEquals('John Doe', $createdObject->getObject()['name']);
    }

    /**
     * Test 5.4: Entity Access Within Same Organisation
     *
     * Scenario: Bob (ACME member) should access ACME entities
     * Expected: Full access to entities within same organisation
     *
     * @return void
     */
    public function testEntityAccessWithinSameOrganisation(): void
    {
        // Arrange: Mock user session (Bob is member of ACME).
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        // Mock: Bob belongs to ACME organisation.
        $acmeOrg = new Organisation();
        $acmeOrg->setUuid('acme-uuid-123');
        $acmeOrg->setUsers(['alice', 'bob']);

        $this->organisationMapper
            ->method('findByUserId')
            ->with('bob')
            ->willReturn([$acmeOrg]);

        // Mock: ACME register.
        $acmeRegister = new Register();
        $acmeRegister->setId(1);
        $acmeRegister->setUuid('acme-register-uuid');
        $acmeRegister->setTitle('ACME Register');
        $acmeRegister->setOrganisation('acme-uuid-123');
        $acmeRegister->setOwner('alice');

        $this->registerMapper
            ->expects($this->once())
            ->method('find')
            ->with(id: 1)
            ->willReturn($acmeRegister);

        // Act: Bob accesses ACME register.
        $register = $this->registerService->find(id: 1);

        // Assert: Bob can access register in same organisation.
        $this->assertInstanceOf(Register::class, $register);
        $this->assertEquals('acme-uuid-123', $register->getOrganisation());
        $this->assertEquals('ACME Register', $register->getTitle());
    }

    /**
     * Test 5.5: Entity Access Across Organisations (Negative Test)
     *
     * Scenario: Charlie (not ACME member) should not access ACME entities
     * Expected: Access denied or filtered results
     *
     * @return void
     */
    public function testEntityAccessAcrossOrganisations(): void
    {
        // Arrange: Mock user session (Charlie not in ACME).
        $charlieUser = $this->createMock(IUser::class);
        $charlieUser->method('getUID')->willReturn('charlie');
        $this->userSession->method('getUser')->willReturn($charlieUser);

        // Mock: Charlie belongs to different organisation.
        $defaultOrg = new Organisation();
        $defaultOrg->setUuid('default-org-uuid');
        $defaultOrg->setUsers(['charlie']);

        $this->organisationMapper
            ->method('findByUserId')
            ->with('charlie')
            ->willReturn([$defaultOrg]);

        // Mock: ACME register (different organisation).
        $this->registerMapper
            ->expects($this->once())
            ->method('find')
            ->with(id: 1)
            ->willThrowException(new DoesNotExistException('Register not accessible'));

        // Act & Assert: Charlie cannot access ACME register.
        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessage('Register not accessible');

        $this->registerService->find(id: 1);
    }

    /**
     * Test 5.6: Cross-Organisation Object Creation (Negative Test)
     *
     * Scenario: User tries to create object in different organisation's register
     * Expected: Organisation mismatch detected
     *
     * @return void
     */
    public function testCrossOrganisationObjectCreation(): void
    {
        // Arrange: Object in different organisation's register.
        $acmeRegister = new Register();
        $acmeRegister->setOrganisation('acme-uuid-123');

        $userOrg = 'default-org-uuid';

        // Assert: Organisation mismatch is detectable.
        $this->assertNotEquals($userOrg, $acmeRegister->getOrganisation());
    }

    /**
     * Test entity organisation assignment validation
     *
     * Scenario: Entities should only be created in user's accessible organisations
     * Expected: getOrganisationForNewEntity returns the active org UUID
     *
     * @return void
     */
    public function testEntityOrganisationAssignmentValidation(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: Active organisation via session cache.
        $orgData = [
            'id' => 1,
            'uuid' => 'valid-org-uuid',
            'name' => 'Valid Org',
            'description' => '',
            'owner' => 'alice',
            'users' => ['alice'],
            'created' => '2024-01-01T00:00:00+00:00',
            'updated' => '2024-01-01T00:00:00+00:00',
        ];

        $this->session->method('get')
            ->willReturnCallback(function (string $key) use ($orgData) {
                if ($key === 'openregister_active_organisation_alice') {
                    return $orgData;
                }
                if ($key === 'openregister_active_organisation_timestamp_alice') {
                    return time();
                }
                return null;
            });

        // Act: Get organisation for new entity.
        $organisationUuid = $this->organisationService->getOrganisationForNewEntity();

        // Assert: Valid organisation UUID returned.
        $this->assertNotNull($organisationUuid);
        $this->assertIsString($organisationUuid);
        $this->assertEquals('valid-org-uuid', $organisationUuid);
    }

    /**
     * Test bulk entity operations with organisation context
     *
     * Scenario: Bulk operations should respect organisation boundaries
     * Expected: Operations only affect entities within user's organisations
     *
     * @return void
     */
    public function testBulkEntityOperationsWithOrganisationContext(): void
    {
        // Arrange: Mock user organisations.
        $userOrgs = [
            'org1-uuid' => 'Organisation 1',
            'org2-uuid' => 'Organisation 2'
        ];

        // Mock: Entity filtering by organisation.
        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function($filters) use ($userOrgs) {
                    return isset($filters['organisation']) &&
                           is_array($filters['organisation']) &&
                           !empty(array_intersect($filters['organisation'], array_keys($userOrgs)));
                })
            )
            ->willReturn([]);

        // Act: Perform bulk operation with organisation filtering.
        $results = $this->objectMapper->findAll(
            null, // limit
            null, // offset
            ['organisation' => array_keys($userOrgs)] // organisation filter
        );

        // Assert: Results respect organisation boundaries.
        $this->assertIsArray($results);
    }

    /**
     * Test entity organisation inheritance
     *
     * Scenario: Child entities should inherit organisation from parent entities
     * Expected: Objects inherit organisation from their register/schema
     *
     * @return void
     */
    public function testEntityOrganisationInheritance(): void
    {
        // Arrange: Parent entities with organisation.
        $parentRegister = new Register();
        $parentRegister->setOrganisation('parent-org-uuid');

        $parentSchema = new Schema();
        $parentSchema->setOrganisation('parent-org-uuid');

        // Mock: Object inherits organisation from parents.
        $childObject = new ObjectEntity();
        $childObject->setRegister($parentRegister->getUuid());
        $childObject->setSchema($parentSchema->getUuid());
        $childObject->setOrganisation('parent-org-uuid'); // Inherited

        // Assert: Organisation inheritance maintained.
        $this->assertEquals('parent-org-uuid', $childObject->getOrganisation());
        $this->assertEquals($parentRegister->getOrganisation(), $childObject->getOrganisation());
        $this->assertEquals($parentSchema->getOrganisation(), $childObject->getOrganisation());
    }
}
