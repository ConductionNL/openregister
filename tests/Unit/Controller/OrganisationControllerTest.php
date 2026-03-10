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
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OrganisationController
 *
 * @package Unit\Controller
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
            $this->logger
        );
    }

    public function testIndexReturnsUserOrganisations(): void
    {
        $stats = ['organisations' => [], 'active' => null];
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn($stats);

        $result = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testIndexReturns500OnException(): void
    {
        $this->organisationService->method('getUserOrganisationStats')
            ->willThrowException(new Exception('Failed'));

        $result = $this->controller->index();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

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
    }

    public function testSetActiveReturnsBadRequestOnFailure(): void
    {
        $this->organisationService->method('setActiveOrganisation')->willReturn(false);

        $result = $this->controller->setActive('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testSetActiveReturnsBadRequestOnException(): void
    {
        $this->organisationService->method('setActiveOrganisation')
            ->willThrowException(new Exception('Invalid UUID'));

        $result = $this->controller->setActive('bad-uuid');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testGetActiveReturnsOrganisation(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'uuid-1']);

        $this->organisationService->method('getActiveOrganisation')->willReturn($org);

        $result = $this->controller->getActive();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('activeOrganisation', $data);
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
    }

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
    }

    public function testCreateReturnsBadRequestForEmptyName(): void
    {
        $result = $this->controller->create('  ');

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
    }

    public function testJoinReturnsSuccess(): void
    {
        $this->organisationService->method('joinOrganisation')->willReturn(true);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Successfully joined organisation', $data['message']);
    }

    public function testJoinReturnsBadRequestOnFailure(): void
    {
        $this->organisationService->method('joinOrganisation')->willReturn(false);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->join('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

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

    public function testShowReturnsForbiddenWhenNoAccess(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')->willReturn(false);

        $result = $this->controller->show('uuid-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    public function testShowReturnsOrganisation(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn(['uuid' => 'uuid-1']);

        $this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
        $this->organisationMapper->method('findByUuid')->willReturn($org);
        $this->organisationMapper->method('findChildrenChain')->willReturn([]);

        $result = $this->controller->show('uuid-1');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testShowReturns404OnException(): void
    {
        $this->organisationService->method('hasAccessToOrganisation')
            ->willThrowException(new Exception('Not found'));

        $result = $this->controller->show('nonexistent');

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testPatchDelegatesToUpdate(): void
    {
        // Patch should call update internally. If update throws, patch should too.
        $this->organisationService->method('hasAccessToOrganisation')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->patch('uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testSearchReturnsOrganisations(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('jsonSerialize')->willReturn([
            'uuid' => 'uuid-1',
            'name' => 'Org',
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
    }

    public function testSearchReturns500OnException(): void
    {
        $this->organisationMapper->method('findAll')
            ->willThrowException(new Exception('DB error'));
        $this->request->method('getParam')->willReturn(50);

        $result = $this->controller->search('');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

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
    }

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
    }
}
