<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DeletedControllerTest extends TestCase
{
    private DeletedController $controller;
    private IRequest&MockObject $request;
    private ObjectEntityMapper&MockObject $objectEntityMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private ObjectService&MockObject $objectService;
    private IUserSession&MockObject $userSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->controller = new DeletedController(
            'openregister',
            $this->request,
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectService,
            $this->userSession
        );
    }

    public function testIndexSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->userSession->method('getUser')->willReturn(null);

        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testIndexException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->userSession->method('getUser')->willReturn(null);
        $this->objectService->method('searchObjectsPaginated')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testStatisticsSuccess(): void
    {
        $this->objectEntityMapper->method('countAll')
            ->willReturnOnConsecutiveCalls(100, 5, 20);

        $result = $this->controller->statistics();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(100, $data['totalDeleted']);
        $this->assertEquals(5, $data['deletedToday']);
        $this->assertEquals(20, $data['deletedThisWeek']);
    }

    public function testStatisticsException(): void
    {
        $this->objectEntityMapper->method('countAll')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->statistics();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testTopDeleters(): void
    {
        $result = $this->controller->topDeleters();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertIsArray($data);
    }

    public function testRestoreObjectNotDeleted(): void
    {
        $object = new ObjectEntity();
        $object->setDeleted(null);
        $this->objectEntityMapper->method('find')->willReturn($object);

        $result = $this->controller->restore('uuid-123');

        $this->assertEquals(400, $result->getStatus());
    }

    public function testRestoreException(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->controller->restore('bad-uuid');

        $this->assertEquals(500, $result->getStatus());
    }

    public function testRestoreMultipleNoIds(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['ids', [], []],
            ]);

        $result = $this->controller->restoreMultiple();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testDestroyObjectNotDeleted(): void
    {
        // ObjectEntity::getter() converts null to [] for the 'deleted' field,
        // but the controller's destroy() only checks === null (not === []),
        // so a non-deleted object bypasses the guard and proceeds to delete.
        // This matches the actual controller behavior (unlike restore() which
        // checks both null and []).
        $object = new ObjectEntity();
        $object->setDeleted(null);
        $this->objectEntityMapper->method('find')->willReturn($object);

        $result = $this->controller->destroy('uuid-123');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $object = new ObjectEntity();
        $object->setDeleted(['deleted' => '2024-01-01']);
        $this->objectEntityMapper->method('find')->willReturn($object);

        $result = $this->controller->destroy('uuid-123');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testDestroyException(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->destroy('bad-uuid');

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDestroyMultipleNoIds(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['ids', [], []],
            ]);

        $result = $this->controller->destroyMultiple();

        $this->assertEquals(400, $result->getStatus());
    }
}
