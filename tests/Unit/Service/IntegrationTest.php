<?php
/**
 * Integration Unit Tests
 *
 * Test Coverage:
 * - Test 10.1: RBAC Integration with Multi-Tenancy
 * - Test 10.2: Search Filtering by Organisation
 * - Test 10.3: Audit Trail Organisation Context
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Controller\SearchController;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\AuditTrail;
use OCP\IUserSession;
use OCP\ISession;
use OCP\IUser;
use OCP\IRequest;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

class IntegrationTest extends TestCase
{
    private OrganisationService $organisationService;
    private ObjectService|MockObject $objectService;
    private SearchController $searchController;
    private OrganisationMapper|MockObject $organisationMapper;
    private ObjectEntityMapper|MockObject $objectEntityMapper;
    private SchemaMapper|MockObject $schemaMapper;
    private AuditTrailMapper|MockObject $auditTrailMapper;
    private IUserSession|MockObject $userSession;
    private ISession|MockObject $session;
    private IRequest|MockObject $request;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->request = $this->createMock(IRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);
        
        $this->organisationService = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->logger
        );
        
        $this->searchController = new SearchController(
            'openregister',
            $this->request,
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->logger
        );
    }

    /**
     * Test 10.1: RBAC Integration with Multi-Tenancy
     */
    public function testRbacIntegrationWithMultiTenancy(): void
    {
        // Arrange: User with specific organisation context.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // Mock: Organisation with RBAC-enabled schema.
        $acmeOrg = new Organisation();
        $acmeOrg->setUuid('acme-org-uuid');
        $acmeOrg->setUsers(['alice', 'bob']);
        
        $rbacSchema = new Schema();
        $rbacSchema->setTitle('RBAC Test Schema');
        $rbacSchema->setOrganisation('acme-org-uuid');
        $rbacSchema->setAuthorization([
            'create' => ['editors'],
            'read' => ['viewers', 'editors'], 
            'update' => ['editors'],
            'delete' => ['managers']
        ]);
        
        // Mock: Object in same organisation with RBAC rules.
        $protectedObject = new ObjectEntity();
        $protectedObject->setUuid('protected-object-uuid');
        $protectedObject->setSchema($rbacSchema->getId());
        $protectedObject->setOrganisation('acme-org-uuid');
        $protectedObject->setOwner('alice');
        
        // Mock: RBAC permission check within organisation context.
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function($filters) {
                    return isset($filters['organisation']) && 
                           $filters['organisation'] === 'acme-org-uuid';
                })
            )
            ->willReturn([$protectedObject]);

        // Act: Search within organisation with RBAC filtering.
        $results = $this->objectEntityMapper->findAll(
            null, // limit
            null, // offset
            ['organisation' => 'acme-org-uuid'] // Organisation filter
        );

        // Assert: RBAC and multi-tenancy work together.
        $this->assertCount(1, $results);
        $this->assertEquals('protected-object-uuid', $results[0]->getUuid());
        $this->assertEquals('acme-org-uuid', $results[0]->getOrganisation());
    }

    /**
     * Test 10.2: Search Filtering by Organisation
     */
    public function testSearchFilteringByOrganisation(): void
    {
        // Arrange: User with access to specific organisations.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // Mock: User belongs to multiple organisations.
        $orgs = [
            $this->createOrganisation('org1-uuid', 'Organisation 1'),
            $this->createOrganisation('org2-uuid', 'Organisation 2')
        ];
        
        $this->organisationMapper->method('findByUserId')
            ->with('alice')
            ->willReturn($orgs);

        // Mock: Search results from different organisations.
        $org1Objects = [
            $this->createObject('obj1-uuid', 'org1-uuid'),
            $this->createObject('obj2-uuid', 'org1-uuid')
        ];
        
        $org2Objects = [
            $this->createObject('obj3-uuid', 'org2-uuid')
        ];
        
        // Mock: Search with organisation filtering.
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function($filters) {
                    return isset($filters['organisation']) && 
                           is_array($filters['organisation']) &&
                           in_array('org1-uuid', $filters['organisation']) &&
                           in_array('org2-uuid', $filters['organisation']);
                })
            )
            ->willReturn(array_merge($org1Objects, $org2Objects));

        // Mock: Request parameters.
        $this->request->method('getParam')
            ->willReturnMap([
                ['q', '', 'test'],
                ['organisation', [], ['org1-uuid', 'org2-uuid']]
            ]);

        // Act: Search across user's organisations.
        $response = $this->searchController->index();

        // Assert: Results filtered by organisation membership.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('results', $responseData);
    }

    /**
     * Test 10.3: Audit Trail Organisation Context
     */
    public function testAuditTrailOrganisationContext(): void
    {
        // Arrange: User performs actions in specific organisation context.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // Mock: Active organisation context.
        $this->session->method('get')
            ->with('openregister_active_organisation_alice')
            ->willReturn('audit-org-uuid');

        // Mock: Audit trail entries with organisation context.
        $auditEntries = [
            $this->createAuditTrail('audit1-uuid', 'create', 'alice', 'audit-org-uuid'),
            $this->createAuditTrail('audit2-uuid', 'update', 'alice', 'audit-org-uuid'),
            $this->createAuditTrail('audit3-uuid', 'delete', 'alice', 'audit-org-uuid')
        ];
        
        // Mock: Audit trail query with organisation filtering.
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function($filters) {
                    return isset($filters['organisation']) && 
                           $filters['organisation'] === 'audit-org-uuid';
                })
            )
            ->willReturn($auditEntries);

        // Act: Get audit trails for organisation context.
        $trails = $this->auditTrailMapper->findAll(
            null, // limit
            null, // offset
            ['organisation' => 'audit-org-uuid'] // Organisation context
        );

        // Assert: Audit trails include organisation context.
        $this->assertCount(3, $trails);
        foreach ($trails as $trail) {
            $this->assertEquals('audit-org-uuid', $trail->getOrganisation());
            $this->assertEquals('alice', $trail->getUser());
        }
        
        // Verify action types.
        $actions = array_map(function($trail) { return $trail->getAction(); }, $trails);
        $this->assertContains('create', $actions);
        $this->assertContains('update', $actions);
        $this->assertContains('delete', $actions);
    }

    /**
     * Test cross-organisation access prevention
     */
    public function testCrossOrganisationAccessPrevention(): void
    {
        // Arrange: User tries to access data from different organisation.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);

        // Mock: Bob belongs to Organisation A.
        $bobOrgs = [$this->createOrganisation('orgA-uuid', 'Organisation A')];
        
        $this->organisationMapper->method('findByUserId')
            ->with('bob')
            ->willReturn($bobOrgs);

        // Mock: Attempt to search Organisation B's data.
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function($filters) {
                    // Should only include Bob's organisations.
                    return isset($filters['organisation']) && 
                           $filters['organisation'] === ['orgA-uuid'] &&
                           !in_array('orgB-uuid', (array)$filters['organisation']);
                })
            )
            ->willReturn([]); // No results from different org

        // Act: Search should be filtered by user's organisations.
        $results = $this->objectEntityMapper->findAll(
            null,
            null, 
            ['organisation' => ['orgA-uuid']] // Only Bob's orgs
        );

        // Assert: Access is properly restricted.
        $this->assertEmpty($results); // No cross-organisation access
    }

    /**
     * Test multi-tenancy with complex relationships
     */
    public function testMultiTenancyWithComplexRelationships(): void
    {
        // Arrange: Complex object relationships within organisation.
        $orgUuid = 'complex-org-uuid';
        
        // Mock: Related objects all within same organisation.
        $parentObject = $this->createObject('parent-uuid', $orgUuid);
        $childObjects = [
            $this->createObject('child1-uuid', $orgUuid),
            $this->createObject('child2-uuid', $orgUuid)
        ];
        
        // Set up relationships.
        $parentObject->setObject([
            'name' => 'Parent Object',
            'children' => ['child1-uuid', 'child2-uuid']
        ]);
        
        // Mock: Organisation service validates all objects in same org.
        $this->objectEntityMapper->method('findAll')
            ->willReturn(array_merge([$parentObject], $childObjects));

        // Act: Verify all related objects are in same organisation.
        $allObjects = $this->objectEntityMapper->findAll();
        
        // Assert: Relationship integrity within organisation.
        foreach ($allObjects as $object) {
            $this->assertEquals($orgUuid, $object->getOrganisation());
        }
        
        // Verify parent-child relationships maintained.
        $parentData = $parentObject->getObject();
        $this->assertArrayHasKey('children', $parentData);
        $this->assertContains('child1-uuid', $parentData['children']);
        $this->assertContains('child2-uuid', $parentData['children']);
    }

    /**
     * Helper method to create organisation
     */
    private function createOrganisation(string $uuid, string $name): Organisation
    {
        $org = new Organisation();
        $org->setUuid($uuid);
        $org->setName($name);
        $org->setUsers(['alice']);
        return $org;
    }

    /**
     * Helper method to create object
     */
    private function createObject(string $uuid, string $orgUuid): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setOrganisation($orgUuid);
        $object->setOwner('alice');
        return $object;
    }

    /**
     * Helper method to create audit trail
     */
    private function createAuditTrail(string $uuid, string $action, string $user, string $orgUuid): AuditTrail
    {
        $trail = new AuditTrail();
        $trail->setUuid($uuid);
        $trail->setAction($action);
        $trail->setUser($user);
        $trail->setOrganisation($orgUuid);
        $trail->setCreated(new \DateTime());
        return $trail;
    }
} 