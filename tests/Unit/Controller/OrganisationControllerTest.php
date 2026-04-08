<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OrganisationController
 *
 * @package Unit\Controller
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class OrganisationControllerTest extends TestCase
{
    private OrganisationController $controller;
    private IRequest&MockObject $request;
    private OrganisationService&MockObject $organisationService;
    private OrganisationMapper&MockObject $organisationMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new OrganisationController(
            'openregister',
            $this->request,
            $this->organisationService,
            $this->organisationMapper,
            $this->logger,
            $this->createMock(TenantLifecycleService::class),
            $this->createMock(TenantUsageMapper::class)
        );
    }

    /**
     * Create a real Organisation entity for update tests
     *
     * @return Organisation
     */
    private function createRealOrganisation(): Organisation
    {
        $org = new Organisation();
        $org->setUuid('uuid-1');
        $org->setName('Original Name');
        $org->setSlug('original-name');
        $org->setDescription('Original description');
        $org->setActive(true);
        return $org;
    }

    // ──────────────────────────────────────────────
    // index()
    // ──────────────────────────────────────────────

    public function testIndexReturnsUserOrganisations(): void
    {
        $stats = ['organisations' => [], 'active' => null];
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn($stats);

        $result = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame($stats, $data);
    }

    public function testIndexReturns500OnException(): void
    {
        $this->organisationService->method('getUserOrganisationStats')
            ->willThrowException(new Exception('Failed'));

        $result = $this->controller->index();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to retrieve organisations', $data['error']);
    }

    // ──────────────────────────────────────────────
    // setActive()
    // ──────────────────────────────────────────────

    public function testSetActiveReturnsSuccess(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'uuid-1', 'name' => 'Org']);

        $this->organisationService->method('setActiveOrganisation')->willReturn(true);
        $this->organisationService->method('getActiveOrganisation')->willReturn($org);

        $result = $this->controller->setActive('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Active organisation set successfully', $data['message']);
        $this->assertSame(['uuid' => 'uuid-1', 'name' => 'Org'], $data['activeOrganisation']);
    }

    public function testSetActiveSuccessWithNullActiveOrg(): void
    {
        $this->organisationService->method('setActiveOrganisation')->willReturn(true);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $result = $this->controller->setActive('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Active organisation set successfully', $data['message']);
        $this->assertNull($data['activeOrganisation']);
    }

    public function testSetActiveReturnsBadRequestOnFailure(): void
    {
        $this->organisationService->method('setActiveOrganisation')->willReturn(false);

        $result = $this->controller->setActive('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to set active organisation', $data['error']);
    }

    public function testSetActiveReturnsBadRequestOnException(): void
    {
        $this->organisationService->method('setActiveOrganisation')
            ->willThrowException(new Exception('Invalid UUID'));

        $result = $this->controller->setActive('bad-uuid');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Invalid UUID', $data['error']);
    }

    // ──────────────────────────────────────────────
    // getActive()
    // ──────────────────────────────────────────────

    public function testGetActiveReturnsOrganisation(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'uuid-1']);

        $this->organisationService->method('getActiveOrganisation')->willReturn($org);

        $result = $this->controller->getActive();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('activeOrganisation', $data);
        $this->assertSame(['uuid' => 'uuid-1'], $data['activeOrganisation']);
    }

    public function testGetActiveReturnsNullWhenNoActive(): void
    {
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $result = $this->controller->getActive();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertNull($data['activeOrganisation']);
    }

    public function testGetActiveReturns500OnException(): void
    {
        $this->organisationService->method('getActiveOrganisation')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->getActive();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to retrieve active organisation', $data['error']);
    }

    // ──────────────────────────────────────────────
    // create()
    // ──────────────────────────────────────────────

    public function testCreateReturnsCreatedOrganisation(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'uuid-1', 'name' => 'New Org']);

        $this->organisationService->method('createOrganisation')->willReturn($org);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create('New Org', 'Description');

        $this->assertSame(Http::STATUS_CREATED, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Organisation created successfully', $data['message']);
        $this->assertSame(['uuid' => 'uuid-1', 'name' => 'New Org'], $data['organisation']);
    }

    public function testCreateWithUuidFromRequestBody(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'custom-uuid', 'name' => 'Org']);

        $this->request->method('getParams')->willReturn(['uuid' => 'custom-uuid']);
        $this->organisationService->expects($this->once())
            ->method('createOrganisation')
            ->with('My Org', 'Desc', true, 'custom-uuid')
            ->willReturn($org);

        $result = $this->controller->create('My Org', 'Desc');

        $this->assertSame(Http::STATUS_CREATED, $result->getStatus());
    }

    public function testCreateReturnsBadRequestForEmptyName(): void
    {
        $result = $this->controller->create('  ');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Organisation name is required', $data['error']);
    }

    public function testCreateReturnsBadRequestForEmptyStringName(): void
    {
        $result = $this->controller->create('');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Organisation name is required', $data['error']);
    }

    public function testCreateReturnsBadRequestOnException(): void
    {
        $this->organisationService->method('createOrganisation')
            ->willThrowException(new Exception('Duplicate'));
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create('Org Name');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Duplicate', $data['error']);
    }

    // ──────────────────────────────────────────────
    // join()
    // ──────────────────────────────────────────────

    public function testJoinReturnsSuccess(): void
    {
        $this->organisationService->method('joinOrganisation')->willReturn(true);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Successfully joined organisation', $data['message']);
    }

    public function testJoinWithUserIdPassesUserIdToService(): void
    {
        $this->request->method('getParams')->willReturn(['userId' => 'target-user']);
        $this->organisationService->expects($this->once())
            ->method('joinOrganisation')
            ->with('uuid-1', 'target-user')
            ->willReturn(true);

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testJoinReturnsBadRequestOnFailure(): void
    {
        $this->organisationService->method('joinOrganisation')->willReturn(false);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to join organisation', $data['error']);
    }

    public function testJoinReturnsBadRequestOnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->organisationService->method('joinOrganisation')
            ->willThrowException(new Exception('Organisation not found'));

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Organisation not found', $data['error']);
    }

    public function testJoinExceptionWithUserId(): void
    {
        $this->request->method('getParams')->willReturn(['userId' => 'some-user']);
        $this->organisationService->method('joinOrganisation')
            ->willThrowException(new Exception('Join failed'));

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Join failed', $data['error']);
    }

    // ──────────────────────────────────────────────
    // leave()
    // ──────────────────────────────────────────────

    public function testLeaveReturnsSuccess(): void
    {
        $this->organisationService->method('leaveOrganisation')->willReturn(true);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->leave('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Successfully left organisation', $data['message']);
    }

    public function testLeaveWithUserIdReturnsDifferentMessage(): void
    {
        $this->organisationService->method('leaveOrganisation')->willReturn(true);
        $this->request->method('getParams')->willReturn(['userId' => 'user1']);

        $result = $this->controller->leave('uuid-1');

        $data = $result->getData();
        $this->assertSame('Successfully removed user from organisation', $data['message']);
    }

    public function testLeaveReturnsBadRequestOnFailure(): void
    {
        $this->organisationService->method('leaveOrganisation')->willReturn(false);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->leave('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to leave organisation', $data['error']);
    }

    public function testLeaveReturnsBadRequestOnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->organisationService->method('leaveOrganisation')
            ->willThrowException(new Exception('Cannot leave'));

        $result = $this->controller->leave('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Cannot leave', $data['error']);
    }

    public function testLeaveExceptionWithUserId(): void
    {
        $this->request->method('getParams')->willReturn(['userId' => 'user1']);
        $this->organisationService->method('leaveOrganisation')
            ->willThrowException(new Exception('Leave failed'));

        $result = $this->controller->leave('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    // ──────────────────────────────────────────────
    // show()
    // ──────────────────────────────────────────────

    public function testShowReturnsForbiddenWhenNoAccess(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')->willReturn(false);

        $result = $this->controller->show('uuid-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Access denied to this organisation', $data['error']);
    }

    public function testShowReturnsOrganisation(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'uuid-1', 'name' => 'Test Org']);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->organisationMapper->method('findChildrenChain')->willReturn([]);

        $result = $this->controller->show('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('organisation', $data);
        $this->assertSame('uuid-1', $data['organisation']['uuid']);
    }

    public function testShowReturnsOrganisationWithChildren(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'uuid-1', 'children' => ['child-1']]);
        $org->expects($this->once())->method('setChildren')->with([['uuid' => 'child-1']]);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->organisationMapper->method('findChildrenChain')->willReturn([['uuid' => 'child-1']]);

        $result = $this->controller->show('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testShowReturns404OnException(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')
            ->willThrowException(new Exception('Not found'));

        $result = $this->controller->show('nonexistent');

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Organisation not found', $data['error']);
    }

    // ──────────────────────────────────────────────
    // update() — uses real Organisation entities
    // ──────────────────────────────────────────────

    public function testUpdateReturnsForbiddenWhenNoAccess(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')->willReturn(false);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Access denied to this organisation', $data['error']);
    }

    public function testUpdateSuccessWithNameOnly(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn(['name' => 'Updated Name']);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Name should be updated.
        $this->assertSame('Updated Name', $org->getName());
        // Slug should be auto-generated from name since no slug provided.
        $this->assertSame('updated-name', $org->getSlug());
    }

    public function testUpdateSuccessWithNameAndSlug(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => 'New Name',
            'slug' => 'custom-slug',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('New Name', $org->getName());
        // handleSlugUpdate overrides with explicit slug.
        $this->assertSame('custom-slug', $org->getSlug());
    }

    public function testUpdateSuccessWithDescription(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'description' => 'New description',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('New description', $org->getDescription());
    }

    public function testUpdateSuccessWithActiveFieldTrue(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'active' => true,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Note: isActive() uses null coalescing so returns true as default.
        $this->assertTrue($org->isActive());
    }

    public function testUpdateSuccessWithActiveFieldEmptyString(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'active' => '',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        // Controller calls setActive('') which processes to setActive(true) (default for empty).
        // The response should be OK regardless of internal state.
        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testUpdateSuccessWithActiveFieldFalse(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'active' => false,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testUpdateSuccessWithQuotaFields(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'storageQuota'   => 1000,
            'bandwidthQuota' => 2000,
            'requestQuota'   => 3000,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame(1000, $org->getStorageQuota());
        $this->assertSame(2000, $org->getBandwidthQuota());
        $this->assertSame(3000, $org->getRequestQuota());
    }

    public function testUpdateSuccessWithArrayFields(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'groups'        => ['group1', 'group2'],
            'authorization' => ['read' => true, 'write' => false],
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame(['group1', 'group2'], $org->getGroups());
        $this->assertSame(['read' => true, 'write' => false], $org->getAuthorization());
    }

    public function testUpdateIgnoresNonArrayGroups(): void
    {
        $org = $this->createRealOrganisation();
        $org->setGroups(['original']);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'groups' => 'not-an-array',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Groups should not have been overwritten.
        $this->assertSame(['original'], $org->getGroups());
    }

    public function testUpdateIgnoresNonArrayAuthorization(): void
    {
        $org = $this->createRealOrganisation();
        $org->setAuthorization(['original' => true]);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'authorization' => 'not-an-array',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Authorization should not have been overwritten.
        $this->assertSame(['original' => true], $org->getAuthorization());
    }

    public function testUpdateSuccessWithParentSet(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'parent' => 'parent-uuid',
        ]);
        $this->organisationMapper->expects($this->once())
            ->method('validateParentAssignment')
            ->with('uuid-1', 'parent-uuid');
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('parent-uuid', $org->getParent());
    }

    public function testUpdateSuccessWithParentNull(): void
    {
        $org = $this->createRealOrganisation();
        $org->setParent('old-parent');

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'parent' => null,
        ]);
        $this->organisationMapper->expects($this->once())
            ->method('validateParentAssignment')
            ->with('uuid-1', null);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertNull($org->getParent());
    }

    public function testUpdateSuccessWithParentEmptyString(): void
    {
        $org = $this->createRealOrganisation();
        $org->setParent('old-parent');

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'parent' => '',
        ]);
        // Empty string normalizes to null.
        $this->organisationMapper->expects($this->once())
            ->method('validateParentAssignment')
            ->with('uuid-1', null);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertNull($org->getParent());
    }

    public function testUpdateReturnsBadRequestOnCircularParent(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'parent' => 'uuid-1',
        ]);
        $this->organisationMapper->method('validateParentAssignment')
            ->willThrowException(new Exception('Circular reference detected'));

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Circular reference detected', $data['error']);
    }

    public function testUpdateWithNoParentKeyDoesNotCallValidation(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => 'Updated',
        ]);
        $this->organisationMapper->expects($this->never())->method('validateParentAssignment');
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testUpdateReturnsBadRequestOnException(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Failed to update organisation', $data['error']);
        $this->assertStringContainsString('DB error', $data['error']);
    }

    public function testUpdateStripsRouteFromRequestData(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            '_route'      => 'openregister.organisation.update',
            'description' => 'Test',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('Test', $org->getDescription());
    }

    public function testUpdateWithEmptyNameDoesNotSetName(): void
    {
        $org = $this->createRealOrganisation();
        $originalName = $org->getName();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => '  ',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Name should not have changed.
        $this->assertSame($originalName, $org->getName());
    }

    public function testUpdateWithEmptySlugDoesNotOverride(): void
    {
        $org = $this->createRealOrganisation();
        $originalSlug = $org->getSlug();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'slug' => '',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Slug should not have changed.
        $this->assertSame($originalSlug, $org->getSlug());
    }

    public function testUpdateWithAllFields(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name'           => 'Full Update Org',
            'description'    => 'Full description',
            'slug'           => 'full-update-org',
            'active'         => true,
            'storageQuota'   => 500,
            'bandwidthQuota' => 1000,
            'requestQuota'   => 200,
            'groups'         => ['admin'],
            'authorization'  => ['admin' => true],
            'parent'         => 'parent-uuid',
        ]);
        $this->organisationMapper->method('validateParentAssignment');
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('Full Update Org', $org->getName());
        $this->assertSame('Full description', $org->getDescription());
        $this->assertSame('full-update-org', $org->getSlug());
        $this->assertTrue($org->isActive());
        $this->assertSame(500, $org->getStorageQuota());
        $this->assertSame(1000, $org->getBandwidthQuota());
        $this->assertSame(200, $org->getRequestQuota());
        $this->assertSame(['admin'], $org->getGroups());
        $this->assertSame(['admin' => true], $org->getAuthorization());
        $this->assertSame('parent-uuid', $org->getParent());
    }

    public function testUpdateWithNameButNullSlugAutoGenerates(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => 'My New Org Name',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('My New Org Name', $org->getName());
        // Slug should be auto-generated.
        $this->assertSame('my-new-org-name', $org->getSlug());
    }

    public function testUpdateWithNullNameDoesNotSetName(): void
    {
        $org = $this->createRealOrganisation();
        $originalName = $org->getName();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => null,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame($originalName, $org->getName());
    }

    public function testUpdateWithNullDescriptionDoesNotSet(): void
    {
        $org = $this->createRealOrganisation();
        $originalDesc = $org->getDescription();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'description' => null,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame($originalDesc, $org->getDescription());
    }

    public function testUpdateWithEmptyDescription(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'description' => '',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Empty string is not null so setDescription should be called.
        $this->assertSame('', $org->getDescription());
    }

    public function testUpdateWithNullActiveDoesNotSetActive(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'active' => null,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // active=null should not trigger setActive, so isActive() still returns default true.
        $this->assertTrue($org->isActive());
    }

    public function testUpdateWithOnlySingleQuotaField(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'storageQuota' => 999,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame(999, $org->getStorageQuota());
        $this->assertNull($org->getBandwidthQuota());
        $this->assertNull($org->getRequestQuota());
    }

    public function testUpdateSaveThrowsException(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn(['description' => 'Test']);
        $this->organisationMapper->method('save')
            ->willThrowException(new Exception('Save failed'));

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Failed to update organisation', $data['error']);
        $this->assertStringContainsString('Save failed', $data['error']);
    }

    public function testUpdateWithNameSpecialCharactersGeneratesSlug(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => 'Org!@#$With%^&Special*Chars',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $slug = $org->getSlug();
        // Slug should be lowercase, alphanumeric with hyphens.
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
        // Should not have leading/trailing hyphens.
        $this->assertStringStartsNotWith('-', $slug);
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testUpdateWithEmptyRequestDataNoChanges(): void
    {
        $org = $this->createRealOrganisation();
        $originalName = $org->getName();
        $originalDesc = $org->getDescription();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Nothing should have changed.
        $this->assertSame($originalName, $org->getName());
        $this->assertSame($originalDesc, $org->getDescription());
    }

    public function testUpdateWithNameEmptySlugAutoGenerates(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => 'New Name',
            'slug' => '',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('New Name', $org->getName());
        // Since slug is empty, handleNameAndSlugUpdate should auto-generate.
        $this->assertSame('new-name', $org->getSlug());
    }

    // ──────────────────────────────────────────────
    // patch()
    // ──────────────────────────────────────────────

    public function testPatchDelegatesToUpdate(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->patch('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testPatchSuccessfulUpdate(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn(['description' => 'Patched desc']);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->patch('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('Patched desc', $org->getDescription());
    }

    public function testPatchReturnsForbiddenWhenNoAccess(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')->willReturn(false);

        $result = $this->controller->patch('uuid-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    // ──────────────────────────────────────────────
    // search()
    // ──────────────────────────────────────────────

    public function testSearchReturnsOrganisations(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn([
            'uuid'  => 'uuid-1',
            'name'  => 'Org',
            'users' => ['user1'],
            'owner' => 'user1',
        ]);

        $this->organisationMapper->method('findAll')->willReturn([$org]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('organisations', $data);
        // Users and owner should be stripped.
        $this->assertArrayNotHasKey('users', $data['organisations'][0]);
        $this->assertArrayNotHasKey('owner', $data['organisations'][0]);
        $this->assertSame(50, $data['limit']);
        $this->assertSame(0, $data['offset']);
        $this->assertSame(1, $data['count']);
    }

    public function testSearchWithQueryUsesFindByName(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn([
            'uuid'  => 'uuid-1',
            'name'  => 'Search Result',
            'users' => [],
            'owner' => 'admin',
        ]);

        $this->organisationMapper->expects($this->once())
            ->method('findByName')
            ->with('Search', 50, 0)
            ->willReturn([$org]);
        $this->organisationMapper->expects($this->never())->method('findAll');

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('Search');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['organisations']);
    }

    public function testSearchWithWhitespaceOnlyQueryUsesFindAll(): void
    {
        $this->organisationMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);
        $this->organisationMapper->expects($this->never())->method('findByName');

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('   ');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEmpty($data['organisations']);
    }

    public function testSearchClampsLimitToMax100(): void
    {
        $this->organisationMapper->expects($this->once())
            ->method('findAll')
            ->with(100, 0)
            ->willReturn([]);

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 200],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(100, $data['limit']);
    }

    public function testSearchClampsLimitToMin1(): void
    {
        $this->organisationMapper->expects($this->once())
            ->method('findAll')
            ->with(1, 0)
            ->willReturn([]);

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 0],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(1, $data['limit']);
    }

    public function testSearchClampsNegativeOffsetToZero(): void
    {
        $this->organisationMapper->expects($this->once())
            ->method('findAll')
            ->with(50, 0)
            ->willReturn([]);

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, -10],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(0, $data['offset']);
    }

    public function testSearchWithCustomPagination(): void
    {
        $this->organisationMapper->expects($this->once())
            ->method('findAll')
            ->with(25, 50)
            ->willReturn([]);

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 25],
                ['_offset', 0, 50],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(25, $data['limit']);
        $this->assertSame(50, $data['offset']);
    }

    public function testSearchReturns500OnException(): void
    {
        $this->organisationMapper->method('findAll')
            ->willThrowException(new Exception('DB error'));
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Search failed', $data['error']);
    }

    public function testSearchWithEmptyResultsReturnsEmptyArray(): void
    {
        $this->organisationMapper->method('findAll')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame([], $data['organisations']);
        $this->assertSame(0, $data['count']);
    }

    public function testSearchByNameException(): void
    {
        $this->organisationMapper->method('findByName')
            ->willThrowException(new Exception('Search error'));
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('test query');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    // ──────────────────────────────────────────────
    // clearCache()
    // ──────────────────────────────────────────────

    public function testClearCacheReturnsSuccess(): void
    {
        $result = $this->controller->clearCache();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Cache cleared successfully', $data['message']);
    }

    public function testClearCacheReturns500OnException(): void
    {
        $this->organisationService->method('clearCache')
            ->willThrowException(new Exception('Cache error'));

        $result = $this->controller->clearCache();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to clear cache', $data['error']);
    }

    // ──────────────────────────────────────────────
    // stats()
    // ──────────────────────────────────────────────

    public function testStatsReturnsStatistics(): void
    {
        $stats = ['total' => 5];
        $this->organisationMapper->method('getStatistics')->willReturn($stats);

        $result = $this->controller->stats();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame($stats, $data['statistics']);
    }

    public function testStatsReturns500OnException(): void
    {
        $this->organisationMapper->method('getStatistics')
            ->willThrowException(new Exception('Stats error'));

        $result = $this->controller->stats();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to retrieve statistics', $data['error']);
    }

    // ──────────────────────────────────────────────
    // Additional coverage tests
    // ──────────────────────────────────────────────

    public function testCreateWithDefaultDescription(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'uuid-1', 'name' => 'Org']);

        $this->request->method('getParams')->willReturn([]);
        $this->organisationService->expects($this->once())
            ->method('createOrganisation')
            ->with('Valid Org', '', true, '')
            ->willReturn($org);

        $result = $this->controller->create('Valid Org');

        $this->assertSame(Http::STATUS_CREATED, $result->getStatus());
    }

    public function testCreateWithTabOnlyName(): void
    {
        $result = $this->controller->create("\t");

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Organisation name is required', $data['error']);
    }

    public function testJoinWithNullUserIdInParams(): void
    {
        $this->request->method('getParams')->willReturn(['userId' => null]);
        $this->organisationService->expects($this->once())
            ->method('joinOrganisation')
            ->with('uuid-1', null)
            ->willReturn(true);

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testJoinExceptionWithoutUserIdInParams(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->organisationService->method('joinOrganisation')
            ->willThrowException(new Exception('Org not found'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Org not found', $data['error']);
    }

    public function testLeaveWithNullUserIdReturnsLeftMessage(): void
    {
        $this->organisationService->method('leaveOrganisation')->willReturn(true);
        $this->request->method('getParams')->willReturn(['userId' => null]);

        $result = $this->controller->leave('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        // userId is null so message should be "left" not "removed".
        $this->assertSame('Successfully left organisation', $data['message']);
    }

    public function testLeaveExceptionWithNullUserId(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->organisationService->method('leaveOrganisation')
            ->willThrowException(new Exception('Cannot leave'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->leave('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testShowExceptionFromFindByUuid(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Not in DB'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->show('nonexistent-uuid');

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Organisation not found', $data['error']);
    }

    public function testShowExceptionFromFindChildrenChain(): void
    {
        $org = $this->createMock(Organisation::class);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->organisationMapper->method('findChildrenChain')
            ->willThrowException(new Exception('Children query failed'));

        $result = $this->controller->show('uuid-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testUpdateWithNameAndWhitespaceOnlySlugAutoGenerates(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => 'Test Org',
            'slug' => '   ',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('Test Org', $org->getName());
        // Whitespace-only slug should trigger auto-generation from name.
        $this->assertSame('test-org', $org->getSlug());
    }

    public function testUpdateWithNullGroupsDoesNotSet(): void
    {
        $org = $this->createRealOrganisation();
        $org->setGroups(['existing']);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'groups' => null,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Null groups should not override existing.
        $this->assertSame(['existing'], $org->getGroups());
    }

    public function testUpdateWithNullAuthorizationDoesNotSet(): void
    {
        $org = $this->createRealOrganisation();
        $org->setAuthorization(['existing' => true]);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'authorization' => null,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame(['existing' => true], $org->getAuthorization());
    }

    public function testUpdateWithBandwidthQuotaOnly(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'bandwidthQuota' => 5000,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame(5000, $org->getBandwidthQuota());
        $this->assertNull($org->getStorageQuota());
        $this->assertNull($org->getRequestQuota());
    }

    public function testUpdateWithRequestQuotaOnly(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'requestQuota' => 100,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame(100, $org->getRequestQuota());
        $this->assertNull($org->getStorageQuota());
        $this->assertNull($org->getBandwidthQuota());
    }

    public function testUpdateWithNullQuotaFieldsDoesNotSet(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'storageQuota'   => null,
            'bandwidthQuota' => null,
            'requestQuota'   => null,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertNull($org->getStorageQuota());
        $this->assertNull($org->getBandwidthQuota());
        $this->assertNull($org->getRequestQuota());
    }

    public function testUpdateWithNameContainingLeadingTrailingSpaces(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => '  Trimmed Name  ',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('Trimmed Name', $org->getName());
        $this->assertSame('trimmed-name', $org->getSlug());
    }

    public function testUpdateWithDescriptionLeadingTrailingSpaces(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'description' => '  Trimmed description  ',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('Trimmed description', $org->getDescription());
    }

    public function testUpdateWithSlugLeadingTrailingSpaces(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'slug' => '  custom-slug  ',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('custom-slug', $org->getSlug());
    }

    public function testUpdateFindByUuidThrowsException(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Entity not found'));

        $result = $this->controller->update('nonexistent');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Failed to update organisation', $data['error']);
        $this->assertStringContainsString('Entity not found', $data['error']);
    }

    public function testUpdateWithVeryLongNameTruncatesSlug(): void
    {
        $org = $this->createRealOrganisation();
        $longName = str_repeat('a', 200);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => $longName,
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame($longName, $org->getName());
        // Slug should be truncated to 100 characters max.
        $slug = $org->getSlug();
        $this->assertLessThanOrEqual(100, strlen($slug));
    }

    public function testUpdateWithNameContainingUnicodeChars(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => 'Gemeente Utrecht',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $slug = $org->getSlug();
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    public function testUpdateParentValidationSuccessWithNewParent(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'parent' => 'new-parent-uuid',
        ]);
        $this->organisationMapper->expects($this->once())
            ->method('validateParentAssignment')
            ->with('uuid-1', 'new-parent-uuid');
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('new-parent-uuid', $org->getParent());
    }

    public function testSearchWithMultipleResults(): void
    {
        $org1 = $this->createMock(Organisation::class);
        $org1->method('jsonSerialize')->willReturn([
            'uuid'  => 'uuid-1',
            'name'  => 'Org One',
            'users' => ['user1'],
            'owner' => 'user1',
        ]);
        $org2 = $this->createMock(Organisation::class);
        $org2->method('jsonSerialize')->willReturn([
            'uuid'  => 'uuid-2',
            'name'  => 'Org Two',
            'users' => ['user2'],
            'owner' => 'user2',
        ]);

        $this->organisationMapper->method('findAll')->willReturn([$org1, $org2]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(2, $data['organisations']);
        $this->assertSame(2, $data['count']);
        // Verify privacy stripping on all results.
        foreach ($data['organisations'] as $orgData) {
            $this->assertArrayNotHasKey('users', $orgData);
            $this->assertArrayNotHasKey('owner', $orgData);
        }
    }

    public function testSearchByNameWithCustomPagination(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn([
            'uuid'  => 'uuid-1',
            'name'  => 'Found',
            'users' => [],
            'owner' => 'admin',
        ]);

        $this->organisationMapper->expects($this->once())
            ->method('findByName')
            ->with('Found', 10, 5)
            ->willReturn([$org]);

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 10],
                ['_offset', 0, 5],
            ]);

        $result = $this->controller->search('Found');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(10, $data['limit']);
        $this->assertSame(5, $data['offset']);
    }

    public function testSearchWithNegativeLimitClampsToOne(): void
    {
        $this->organisationMapper->expects($this->once())
            ->method('findAll')
            ->with(1, 0)
            ->willReturn([]);

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, -5],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(1, $data['limit']);
    }

    public function testPatchWithNameAndDescription(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name'        => 'Patched Name',
            'description' => 'Patched description',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->patch('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('Patched Name', $org->getName());
        $this->assertSame('Patched description', $org->getDescription());
    }

    public function testPatchExceptionReturnsBadRequest(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Org not found'));

        $result = $this->controller->patch('nonexistent');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Failed to update organisation', $data['error']);
    }

    public function testIndexReturnsComplexStats(): void
    {
        $stats = [
            'organisations' => [
                ['uuid' => 'uuid-1', 'name' => 'Org 1'],
                ['uuid' => 'uuid-2', 'name' => 'Org 2'],
            ],
            'active'        => ['uuid' => 'uuid-1', 'name' => 'Org 1'],
            'totalCount'    => 2,
        ];
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn($stats);

        $result = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(2, $data['totalCount']);
        $this->assertCount(2, $data['organisations']);
    }

    public function testIndexExceptionLogsError(): void
    {
        $this->organisationService->method('getUserOrganisationStats')
            ->willThrowException(new Exception('Service failure'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->index();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testSetActiveExceptionLogsError(): void
    {
        $this->organisationService->method('setActiveOrganisation')
            ->willThrowException(new Exception('Set active failed'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->setActive('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testGetActiveExceptionLogsError(): void
    {
        $this->organisationService->method('getActiveOrganisation')
            ->willThrowException(new Exception('Session error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->getActive();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testCreateExceptionLogsError(): void
    {
        $this->organisationService->method('createOrganisation')
            ->willThrowException(new Exception('Create error'));
        $this->request->method('getParams')->willReturn([]);

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->create('Valid Name');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testShowExceptionLogsError(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')
            ->willThrowException(new Exception('Access check error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->show('uuid-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testUpdateExceptionLogsError(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testSearchExceptionLogsError(): void
    {
        $this->organisationMapper->method('findAll')
            ->willThrowException(new Exception('Search DB error'));
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testClearCacheExceptionLogsError(): void
    {
        $this->organisationService->method('clearCache')
            ->willThrowException(new Exception('Cache clear error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->clearCache();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testStatsExceptionLogsError(): void
    {
        $this->organisationMapper->method('getStatistics')
            ->willThrowException(new Exception('Stats DB error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->stats();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testUpdateWithActiveFieldTruthyString(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'active' => '1',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testUpdateWithActiveFieldZeroString(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'active' => '0',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testUpdateWithEmptyGroupsArray(): void
    {
        $org = $this->createRealOrganisation();
        $org->setGroups(['existing']);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'groups' => [],
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        // Empty array is still an array, so it should override.
        $this->assertSame([], $org->getGroups());
    }

    public function testUpdateWithEmptyAuthorizationArray(): void
    {
        $org = $this->createRealOrganisation();
        $org->setAuthorization(['existing' => true]);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'authorization' => [],
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame([], $org->getAuthorization());
    }

    public function testUpdateWithMultipleRouteAndRegularParams(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            '_route'      => 'openregister.organisation.update',
            'name'        => 'Clean Name',
            'description' => 'Clean description',
        ]);
        $this->organisationMapper->method('save')->willReturn($org);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('Clean Name', $org->getName());
        $this->assertSame('Clean description', $org->getDescription());
    }

    public function testStatsReturnsDetailedStatistics(): void
    {
        $stats = [
            'total'     => 10,
            'active'    => 8,
            'inactive'  => 2,
            'withUsers' => 7,
        ];
        $this->organisationMapper->method('getStatistics')->willReturn($stats);

        $result = $this->controller->stats();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('statistics', $data);
        $this->assertSame(10, $data['statistics']['total']);
        $this->assertSame(8, $data['statistics']['active']);
    }

    public function testClearCacheCallsService(): void
    {
        $this->organisationService->expects($this->once())
            ->method('clearCache');

        $result = $this->controller->clearCache();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testUpdateParentCircularReferenceLogsWarning(): void
    {
        $org = $this->createRealOrganisation();

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'parent' => 'child-uuid',
        ]);
        $this->organisationMapper->method('validateParentAssignment')
            ->willThrowException(new Exception('Circular reference'));

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testUpdateReturnsSavedOrganisationData(): void
    {
        $org = $this->createRealOrganisation();

        $savedOrg = $this->createMock(Organisation::class);
        $savedOrg->method('jsonSerialize')->willReturn([
            'uuid' => 'uuid-1',
            'name' => 'Saved Name',
        ]);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->request->method('getParams')->willReturn([
            'name' => 'Saved Name',
        ]);
        $this->organisationMapper->method('save')->willReturn($savedOrg);

        $result = $this->controller->update('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('uuid-1', $data['uuid']);
        $this->assertSame('Saved Name', $data['name']);
    }

    public function testCreateWithUuidNotInParams(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'auto-gen', 'name' => 'Org']);

        $this->request->method('getParams')->willReturn(['someOtherKey' => 'value']);
        $this->organisationService->expects($this->once())
            ->method('createOrganisation')
            ->with('Org', 'Desc', true, '')
            ->willReturn($org);

        $result = $this->controller->create('Org', 'Desc');

        $this->assertSame(Http::STATUS_CREATED, $result->getStatus());
    }

    public function testSearchQueryTrimsWhitespace(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn([
            'uuid'  => 'uuid-1',
            'name'  => 'Trimmed',
            'users' => [],
            'owner' => 'admin',
        ]);

        $this->organisationMapper->expects($this->once())
            ->method('findByName')
            ->with('Search Term', 50, 0)
            ->willReturn([$org]);

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $result = $this->controller->search(' Search Term ');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }
}
