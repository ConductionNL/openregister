<?php

declare(strict_types=1);

/**
 * ArchivalController Unit Tests
 *
 * Tests for the archival and destruction workflow controller endpoints.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ArchivalController
 */
class ArchivalControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private DestructionService&MockObject $destructionService;
    private LegalHoldService&MockObject $legalHoldService;
    private MagicMapper&MockObject $objectMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private LoggerInterface&MockObject $logger;
    private ArchivalController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request            = $this->createMock(IRequest::class);
        $this->destructionService = $this->createMock(DestructionService::class);
        $this->legalHoldService   = $this->createMock(LegalHoldService::class);
        $this->objectMapper       = $this->getMockBuilder(MagicMapper::class)
            ->disableOriginalConstructor()
            ->addMethods(['findByUuid'])
            ->getMock();
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->controller = new ArchivalController(
            'openregister',
            $this->request,
            $this->destructionService,
            $this->legalHoldService,
            $this->objectMapper,
            $this->userSession,
            $this->groupManager,
            $this->logger
        );
    }

    // ==================================================================================
    // HELPER: configure user session for archivist role
    // ==================================================================================

    /**
     * Create a mock ObjectEntity with magic method support.
     *
     * ObjectEntity uses __call for getters/setters, so we must use addMethods.
     *
     * @param array $methods The magic methods to add to the mock.
     *
     * @return MockObject The mocked ObjectEntity.
     */
    private function createObjectEntityMock(): MockObject
    {
        return $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize', 'getObject'])
            ->addMethods(['getUuid', 'getRetention'])
            ->getMock();
    }

    /**
     * Set up the user session so the current user is an authenticated archivist.
     *
     * @param string $uid The user ID.
     */
    private function setUpArchivist(string $uid = 'archivist-1'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturn(true);
        $this->groupManager->method('isAdmin')->willReturn(false);
    }

    /**
     * Set up the user session so the current user is an admin (not in archivist group).
     *
     * @param string $uid The user ID.
     */
    private function setUpAdmin(string $uid = 'admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->groupManager->method('isAdmin')->willReturn(true);
    }

    /**
     * Set up the user session with no user (unauthenticated).
     */
    private function setUpUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
    }

    /**
     * Set up an authenticated user without archivist or admin role.
     *
     * @param string $uid The user ID.
     */
    private function setUpRegularUser(string $uid = 'user-1'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->groupManager->method('isAdmin')->willReturn(false);
    }

    // ==================================================================================
    // AUTHORIZATION CHECKS (shared by all endpoints)
    // ==================================================================================

    /**
     * Test that unauthenticated requests return 401.
     */
    public function testUnauthenticatedReturns401(): void
    {
        $this->setUpUnauthenticated();

        $response = $this->controller->listDestructionLists();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Niet geauthenticeerd', $response->getData()['error']);
    }

    /**
     * Test that a regular user (not archivist, not admin) gets 403.
     */
    public function testRegularUserReturns403(): void
    {
        $this->setUpRegularUser();

        $response = $this->controller->listDestructionLists();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('archivaris', $response->getData()['error']);
    }

    /**
     * Test that an admin user is authorized.
     */
    public function testAdminIsAuthorized(): void
    {
        $this->setUpAdmin();

        $response = $this->controller->listDestructionLists();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    // ==================================================================================
    // LIST DESTRUCTION LISTS
    // ==================================================================================

    /**
     * Test listing destruction lists returns OK with empty results.
     */
    public function testListDestructionListsOk(): void
    {
        $this->setUpArchivist();
        $this->request->method('getParam')->willReturn(null);

        $response = $this->controller->listDestructionLists();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertSame(0, $data['total']);
    }

    /**
     * Test listing destruction lists passes the status filter.
     */
    public function testListDestructionListsWithStatusFilter(): void
    {
        $this->setUpArchivist();
        $this->request->method('getParam')->willReturn('approved');

        $response = $this->controller->listDestructionLists();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('approved', $response->getData()['filter']);
    }

    // ==================================================================================
    // GET DESTRUCTION LIST
    // ==================================================================================

    /**
     * Test getting a specific destruction list by ID.
     */
    public function testGetDestructionListOk(): void
    {
        $this->setUpArchivist();

        $object = $this->createObjectEntityMock();
        $object->method('jsonSerialize')->willReturn(['uuid' => 'dl-1', 'status' => 'in_review']);

        $this->objectMapper
            ->method('findByUuid')
            ->with('dl-1')
            ->willReturn($object);

        $response = $this->controller->getDestructionList('dl-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('dl-1', $response->getData()['uuid']);
    }

    /**
     * Test getting a non-existent destruction list returns 404.
     */
    public function testGetDestructionListNotFound(): void
    {
        $this->setUpArchivist();

        $this->objectMapper
            ->method('findByUuid')
            ->willThrowException(new \Exception('Not found'));

        $response = $this->controller->getDestructionList('non-existent');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    // ==================================================================================
    // APPROVE DESTRUCTION LIST
    // ==================================================================================

    /**
     * Test approving a destruction list successfully.
     */
    public function testApproveDestructionListOk(): void
    {
        $this->setUpArchivist();

        $object = $this->createObjectEntityMock();
        $object->method('getObject')->willReturn([
            'status' => DestructionService::STATUS_IN_REVIEW,
            'items'  => ['obj-1', 'obj-2'],
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->with('dl-1')
            ->willReturn($object);

        $this->request->method('getParams')->willReturn([
            'action' => 'approve_all',
        ]);

        $this->destructionService
            ->method('approveList')
            ->willReturn([
                'status' => DestructionService::STATUS_APPROVED,
                'items'  => ['obj-1', 'obj-2'],
            ]);

        $response = $this->controller->approveDestructionList('dl-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(DestructionService::STATUS_APPROVED, $response->getData()['status']);
    }

    /**
     * Test approving with partial exclusion.
     */
    public function testApproveDestructionListPartial(): void
    {
        $this->setUpArchivist();

        $object = $this->createObjectEntityMock();
        $object->method('getObject')->willReturn([
            'status' => DestructionService::STATUS_IN_REVIEW,
            'items'  => ['obj-1', 'obj-2', 'obj-3'],
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->with('dl-1')
            ->willReturn($object);

        $this->request->method('getParams')->willReturn([
            'action'           => 'approve_partial',
            'excluded'         => ['obj-2'],
            'exclusionReasons' => ['Not ready for destruction'],
        ]);

        $this->destructionService
            ->method('approveList')
            ->willReturn([
                'status' => DestructionService::STATUS_APPROVED,
                'items'  => ['obj-1', 'obj-3'],
            ]);

        $response = $this->controller->approveDestructionList('dl-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    /**
     * Test dual-approval conflict (same user tries second approval).
     */
    public function testApproveDestructionListDualApprovalConflict(): void
    {
        $this->setUpArchivist();

        $object = $this->createObjectEntityMock();
        $object->method('getObject')->willReturn([
            'status' => DestructionService::STATUS_AWAITING_SECOND,
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->with('dl-1')
            ->willReturn($object);

        $this->request->method('getParams')->willReturn([]);

        // Service returns same status (awaiting_second), indicating same-user rejection.
        $this->destructionService
            ->method('approveList')
            ->willReturn([
                'status' => DestructionService::STATUS_AWAITING_SECOND,
            ]);

        $response = $this->controller->approveDestructionList('dl-1');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertStringContainsString('andere archivaris', $response->getData()['error']);
    }

    /**
     * Test approving a non-existent list returns 500.
     */
    public function testApproveDestructionListException(): void
    {
        $this->setUpArchivist();

        $this->objectMapper
            ->method('findByUuid')
            ->willThrowException(new \Exception('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->approveDestructionList('non-existent');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertStringContainsString('Failed to approve', $response->getData()['error']);
    }

    // ==================================================================================
    // REJECT DESTRUCTION LIST
    // ==================================================================================

    /**
     * Test rejecting a destruction list successfully.
     */
    public function testRejectDestructionListOk(): void
    {
        $this->setUpArchivist();

        $object = $this->createObjectEntityMock();
        $object->method('getObject')->willReturn([
            'status' => DestructionService::STATUS_IN_REVIEW,
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->with('dl-1')
            ->willReturn($object);

        $this->request->method('getParams')->willReturn([
            'reason' => 'Onjuiste documenten in de lijst',
        ]);

        $this->destructionService
            ->method('rejectList')
            ->willReturn([
                'status' => DestructionService::STATUS_REJECTED,
                'reason' => 'Onjuiste documenten in de lijst',
            ]);

        $response = $this->controller->rejectDestructionList('dl-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(DestructionService::STATUS_REJECTED, $response->getData()['status']);
    }

    /**
     * Test rejecting without a reason returns 400.
     */
    public function testRejectDestructionListMissingReason(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->rejectDestructionList('dl-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('reden', $response->getData()['error']);
    }

    /**
     * Test rejecting with an empty reason returns 400.
     */
    public function testRejectDestructionListEmptyReason(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'reason' => '   ',
        ]);

        $response = $this->controller->rejectDestructionList('dl-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Test rejecting a non-existent list returns 500.
     */
    public function testRejectDestructionListException(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'reason' => 'Test reason',
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->willThrowException(new \Exception('Not found'));

        $response = $this->controller->rejectDestructionList('non-existent');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertStringContainsString('Failed to reject', $response->getData()['error']);
    }

    // ==================================================================================
    // CREATE LEGAL HOLD
    // ==================================================================================

    /**
     * Test creating a legal hold on a single object.
     */
    public function testCreateLegalHoldSingleObjectOk(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'objectId' => 'obj-1',
            'reason'   => 'Lopende rechtszaak',
        ]);

        $object = $this->createObjectEntityMock();
        $object->method('getUuid')->willReturn('obj-1');
        $object->method('getRetention')->willReturn([
            'legalHold' => ['active' => true, 'reason' => 'Lopende rechtszaak'],
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->with('obj-1')
            ->willReturn($object);

        $this->legalHoldService
            ->method('placeHold')
            ->willReturn($object);

        $response = $this->controller->createLegalHold();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Bewaarplicht geplaatst', $data['message']);
        $this->assertSame('obj-1', $data['objectId']);
    }

    /**
     * Test creating a bulk legal hold on a schema.
     */
    public function testCreateLegalHoldBulkOk(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'schemaId'   => '5',
            'registerId' => '3',
            'reason'     => 'Audit vereist',
        ]);

        $response = $this->controller->createLegalHold();

        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        $this->assertStringContainsString('achtergrondtaak', $response->getData()['message']);
    }

    /**
     * Test bulk legal hold without registerId returns 400.
     */
    public function testCreateLegalHoldBulkMissingRegisterId(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'schemaId' => '5',
            'reason'   => 'Audit vereist',
        ]);

        $response = $this->controller->createLegalHold();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('registerId', $response->getData()['error']);
    }

    /**
     * Test creating a legal hold without reason returns 400.
     */
    public function testCreateLegalHoldMissingReason(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'objectId' => 'obj-1',
        ]);

        $response = $this->controller->createLegalHold();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('reden', $response->getData()['error']);
    }

    /**
     * Test creating a legal hold without objectId or schemaId returns 400.
     */
    public function testCreateLegalHoldMissingIds(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'reason' => 'Lopende rechtszaak',
        ]);

        $response = $this->controller->createLegalHold();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('objectId', $response->getData()['error']);
    }

    /**
     * Test creating a legal hold when an exception occurs.
     */
    public function testCreateLegalHoldException(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'objectId' => 'obj-1',
            'reason'   => 'Lopende rechtszaak',
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->willThrowException(new \Exception('Object not found'));

        $response = $this->controller->createLegalHold();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertStringContainsString('Failed to create legal hold', $response->getData()['error']);
    }

    // ==================================================================================
    // RELEASE LEGAL HOLD
    // ==================================================================================

    /**
     * Test releasing a legal hold successfully.
     */
    public function testReleaseLegalHoldOk(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'reason' => 'Rechtszaak afgerond',
        ]);

        $object = $this->createObjectEntityMock();
        $object->method('getUuid')->willReturn('obj-1');
        $object->method('getRetention')->willReturn([
            'legalHold' => ['active' => false],
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->with('obj-1')
            ->willReturn($object);

        $this->legalHoldService
            ->method('releaseHold')
            ->willReturn($object);

        $response = $this->controller->releaseLegalHold('obj-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Bewaarplicht opgeheven', $data['message']);
        $this->assertSame('obj-1', $data['objectId']);
    }

    /**
     * Test releasing a legal hold without reason returns 400.
     */
    public function testReleaseLegalHoldMissingReason(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->releaseLegalHold('obj-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('reden', $response->getData()['error']);
    }

    /**
     * Test releasing a legal hold when an exception occurs.
     */
    public function testReleaseLegalHoldException(): void
    {
        $this->setUpArchivist();

        $this->request->method('getParams')->willReturn([
            'reason' => 'Rechtszaak afgerond',
        ]);

        $this->objectMapper
            ->method('findByUuid')
            ->willThrowException(new \Exception('Object not found'));

        $response = $this->controller->releaseLegalHold('obj-1');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertStringContainsString('Failed to release legal hold', $response->getData()['error']);
    }

    // ==================================================================================
    // LIST LEGAL HOLDS
    // ==================================================================================

    /**
     * Test listing legal holds returns OK with empty results.
     */
    public function testListLegalHoldsOk(): void
    {
        $this->setUpArchivist();

        $response = $this->controller->listLegalHolds();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertSame(0, $data['total']);
    }

    // ==================================================================================
    // LIST CERTIFICATES
    // ==================================================================================

    /**
     * Test listing certificates returns OK with empty results.
     */
    public function testListCertificatesOk(): void
    {
        $this->setUpArchivist();

        $response = $this->controller->listCertificates();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertSame(0, $data['total']);
    }
}
