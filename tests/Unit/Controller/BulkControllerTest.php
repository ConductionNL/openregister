<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BulkControllerTest extends TestCase
{
    private BulkController $controller;
    private IRequest&MockObject $request;
    private ObjectService&MockObject $objectService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->controller = new BulkController(
            'openregister',
            $this->request,
            $this->objectService
        );
    }

    public function testDeleteMissingUuids(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDeleteSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('deleteObjects')->willReturn(['uuid1', 'uuid2']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1', 'uuid2', 'uuid3'],
        ]);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['deleted_count']);
        $this->assertEquals(1, $data['skipped_count']);
    }

    public function testDeleteException(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('deleteObjects')
            ->willThrowException(new \Exception('Delete error'));

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testPublishMissingUuids(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testPublishSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['published_count']);
    }

    public function testPublishInvalidDatetime(): void
    {
        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => 'not-a-date',
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDepublishSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('depublishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testDepublishMissingUuids(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testSaveSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObjects')->willReturn([
            'statistics' => ['saved' => 2, 'updated' => 1],
        ]);

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1'], ['name' => 'obj2']],
        ]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(3, $data['saved_count']);
    }

    public function testSaveMissingObjects(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testPublishSchemaInvalidId(): void
    {
        $result = $this->controller->publishSchema('1', 'not-numeric');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testPublishSchemaSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjectsBySchema')->willReturn([
            'published_count' => 5,
            'published_uuids' => ['u1', 'u2'],
            'schema_id' => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->publishSchema('1', '1');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testDeleteSchemaInvalidId(): void
    {
        $result = $this->controller->deleteSchema('1', 'abc');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDeleteRegisterInvalidId(): void
    {
        $result = $this->controller->deleteRegister('abc');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDeleteRegisterSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('deleteObjectsByRegister')->willReturn([
            'deleted_count' => 3,
            'deleted_uuids' => ['u1'],
            'register_id' => 1,
        ]);

        $result = $this->controller->deleteRegister('1');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testValidateSchemaInvalidId(): void
    {
        $result = $this->controller->validateSchema('abc');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testValidateSchemaSuccess(): void
    {
        $this->objectService->method('validateObjectsBySchema')
            ->willReturn(['valid' => 10, 'invalid' => 2]);

        $result = $this->controller->validateSchema('1');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }
}
