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

use InvalidArgumentException;
use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Db\DestructionList;
use OCA\OpenRegister\Db\DestructionListMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SelectionList;
use OCA\OpenRegister\Db\SelectionListMapper;
use OCA\OpenRegister\Service\ArchivalService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test class for ArchivalController
 */
class ArchivalControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private ArchivalService&MockObject $archivalService;
    private SelectionListMapper&MockObject $selectionListMapper;
    private DestructionListMapper&MockObject $destructionListMapper;
    private ObjectService&MockObject $objectService;
    private IUserSession&MockObject $userSession;
    private ArchivalController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request               = $this->createMock(IRequest::class);
        $this->archivalService       = $this->createMock(ArchivalService::class);
        $this->selectionListMapper   = $this->createMock(SelectionListMapper::class);
        $this->destructionListMapper = $this->createMock(DestructionListMapper::class);
        $this->objectService         = $this->createMock(ObjectService::class);
        $this->userSession           = $this->createMock(IUserSession::class);

        $this->controller = new ArchivalController(
            'openregister',
            $this->request,
            $this->archivalService,
            $this->selectionListMapper,
            $this->destructionListMapper,
            $this->objectService,
            $this->userSession
        );
    }

    // ==================================================================================
    // SELECTION LIST CRUD
    // ==================================================================================

    /**
     * Test listing selection lists returns OK.
     */
    public function testListSelectionListsOk(): void
    {
        $list1 = new SelectionList();
        $list1->setUuid('sl-1');
        $list1->setCategory('B1');

        $this->selectionListMapper
            ->method('findAll')
            ->willReturn([$list1]);

        $response = $this->controller->listSelectionLists();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(1, $data['total']);
    }

    /**
     * Test getting a selection list returns OK.
     */
    public function testGetSelectionListOk(): void
    {
        $list = new SelectionList();
        $list->setUuid('sl-1');
        $list->setCategory('B1');

        $this->selectionListMapper
            ->method('findByUuid')
            ->with('sl-1')
            ->willReturn($list);

        $response = $this->controller->getSelectionList('sl-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    /**
     * Test getting a non-existent selection list returns 404.
     */
    public function testGetSelectionListNotFound(): void
    {
        $this->selectionListMapper
            ->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $response = $this->controller->getSelectionList('non-existent');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * Test creating a selection list.
     */
    public function testCreateSelectionListOk(): void
    {
        $this->request
            ->method('getParams')
            ->willReturn([
                'category'       => 'B1',
                'retentionYears' => 5,
                'action'         => 'vernietigen',
                'description'    => 'Short retention',
            ]);

        $created = new SelectionList();
        $created->setUuid('sl-new');
        $created->setCategory('B1');

        $this->selectionListMapper
            ->method('createEntry')
            ->willReturn($created);

        $response = $this->controller->createSelectionList();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }

    /**
     * Test creating a selection list without category returns 400.
     */
    public function testCreateSelectionListMissingCategory(): void
    {
        $this->request
            ->method('getParams')
            ->willReturn([
                'retentionYears' => 5,
                'action'         => 'vernietigen',
            ]);

        $response = $this->controller->createSelectionList();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Test deleting a selection list.
     */
    public function testDeleteSelectionListOk(): void
    {
        $list = new SelectionList();
        $list->setUuid('sl-1');

        $this->selectionListMapper
            ->method('findByUuid')
            ->willReturn($list);

        $response = $this->controller->deleteSelectionList('sl-1');

        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }

    // ==================================================================================
    // RETENTION METADATA
    // ==================================================================================

    /**
     * Test getting retention metadata for an object.
     */
    public function testGetRetentionOk(): void
    {
        $object = new ObjectEntity();
        $object->setRetention(['archiefnominatie' => 'vernietigen']);

        $this->objectService
            ->method('find')
            ->with('obj-1')
            ->willReturn($object);

        $response = $this->controller->getRetention('obj-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('vernietigen', $data['retention']['archiefnominatie']);
    }

    /**
     * Test getting retention for non-existent object returns 404.
     */
    public function testGetRetentionNotFound(): void
    {
        $this->objectService
            ->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $response = $this->controller->getRetention('non-existent');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    // ==================================================================================
    // DESTRUCTION LIST ENDPOINTS
    // ==================================================================================

    /**
     * Test listing destruction lists.
     */
    public function testListDestructionListsOk(): void
    {
        $this->request->method('getParam')->willReturn(null);

        $list = new DestructionList();
        $list->setUuid('dl-1');

        $this->destructionListMapper
            ->method('findAll')
            ->willReturn([$list]);

        $response = $this->controller->listDestructionLists();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    /**
     * Test generating a destruction list when no objects are due.
     */
    public function testGenerateDestructionListEmpty(): void
    {
        $this->archivalService
            ->method('generateDestructionList')
            ->willReturn(null);

        $response = $this->controller->generateDestructionList();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('message', $data);
    }

    /**
     * Test generating a destruction list when objects are found.
     */
    public function testGenerateDestructionListCreated(): void
    {
        $list = new DestructionList();
        $list->setUuid('dl-new');
        $list->setObjects(['obj-1']);

        $this->archivalService
            ->method('generateDestructionList')
            ->willReturn($list);

        $response = $this->controller->generateDestructionList();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }

    /**
     * Test approving a destruction list.
     */
    public function testApproveDestructionListOk(): void
    {
        $list = new DestructionList();
        $list->setUuid('dl-1');
        $list->setStatus(DestructionList::STATUS_PENDING_REVIEW);

        $this->destructionListMapper
            ->method('findByUuid')
            ->with('dl-1')
            ->willReturn($list);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->archivalService
            ->method('approveDestructionList')
            ->willReturn([
                'destroyed' => 5,
                'errors'    => 0,
                'list'      => $list,
            ]);

        $response = $this->controller->approveDestructionList('dl-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(5, $data['destroyed']);
    }

    /**
     * Test approving without authentication returns 401.
     */
    public function testApproveDestructionListUnauthorized(): void
    {
        $list = new DestructionList();
        $list->setUuid('dl-1');

        $this->destructionListMapper
            ->method('findByUuid')
            ->willReturn($list);

        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->approveDestructionList('dl-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    /**
     * Test rejecting objects from destruction list.
     */
    public function testRejectFromDestructionListOk(): void
    {
        $list = new DestructionList();
        $list->setUuid('dl-1');
        $list->setStatus(DestructionList::STATUS_PENDING_REVIEW);

        $this->destructionListMapper
            ->method('findByUuid')
            ->with('dl-1')
            ->willReturn($list);

        $this->request
            ->method('getParam')
            ->with('objects', [])
            ->willReturn(['obj-1', 'obj-2']);

        $updatedList = new DestructionList();
        $updatedList->setUuid('dl-1');
        $updatedList->setObjects(['obj-3']);

        $this->archivalService
            ->method('rejectFromDestructionList')
            ->willReturn($updatedList);

        $response = $this->controller->rejectFromDestructionList('dl-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    /**
     * Test rejecting with empty objects array returns 400.
     */
    public function testRejectFromDestructionListEmptyObjects(): void
    {
        $list = new DestructionList();
        $list->setUuid('dl-1');

        $this->destructionListMapper
            ->method('findByUuid')
            ->willReturn($list);

        $this->request
            ->method('getParam')
            ->with('objects', [])
            ->willReturn([]);

        $response = $this->controller->rejectFromDestructionList('dl-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }
}
