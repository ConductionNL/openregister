<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
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

    /**
     * Helper to set up objectService for resolveRegisterSchemaIds success.
     */
    private function setupResolveSuccess(int $registerId = 1, int $schemaId = 2): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn($registerId);
        $this->objectService->method('getSchema')->willReturn($schemaId);
    }

    // ========================================================================
    // delete() tests
    // ========================================================================

    public function testDeleteMissingUuids(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('uuids', $data['error']);
    }

    public function testDeleteEmptyUuidsArray(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn(['uuids' => []]);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDeleteUuidsNotArray(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn(['uuids' => 'not-an-array']);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDeleteSuccess(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('deleteObjects')->willReturn(['uuid1', 'uuid2']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1', 'uuid2', 'uuid3'],
        ]);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['deleted_count']);
        $this->assertEquals(['uuid1', 'uuid2'], $data['deleted_uuids']);
        $this->assertEquals(3, $data['requested_count']);
        $this->assertEquals(1, $data['skipped_count']);
        $this->assertEquals('Bulk delete operation completed successfully', $data['message']);
    }

    public function testDeleteAllSuccessful(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('deleteObjects')->willReturn(['uuid1', 'uuid2']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1', 'uuid2'],
        ]);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(0, $data['skipped_count']);
    }

    public function testDeleteException(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('deleteObjects')
            ->willThrowException(new \Exception('Delete error'));

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->delete('1', '2');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Delete error', $data['error']);
        $this->assertStringContainsString('Bulk delete operation failed', $data['error']);
    }

    public function testDeleteRegisterNotFound(): void
    {
        $this->objectService->method('setRegister')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->delete('nonexistent', '2');

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Register not found', $data['error']);
    }

    public function testDeleteSchemaNotFound(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->delete('1', 'nonexistent');

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Schema not found', $data['error']);
    }

    // ========================================================================
    // publish() tests
    // ========================================================================

    public function testPublishMissingUuids(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testPublishEmptyUuidsArray(): void
    {
        $this->request->method('getParams')->willReturn(['uuids' => []]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testPublishUuidsNotArray(): void
    {
        $this->request->method('getParams')->willReturn(['uuids' => 'string-value']);

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
        $this->assertEquals(['uuid1'], $data['published_uuids']);
        $this->assertEquals(1, $data['requested_count']);
        $this->assertEquals(0, $data['skipped_count']);
        $this->assertEquals('Bulk publish operation completed successfully', $data['message']);
    }

    public function testPublishWithSkippedUuids(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1', 'uuid2', 'uuid3'],
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(1, $data['published_count']);
        $this->assertEquals(3, $data['requested_count']);
        $this->assertEquals(2, $data['skipped_count']);
    }

    public function testPublishWithValidDatetime(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => '2024-01-01T12:00:00Z',
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        // datetime_used should be formatted as Y-m-d H:i:s
        $this->assertEquals('2024-01-01 12:00:00', $data['datetime_used']);
    }

    public function testPublishWithDatetimeTrue(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => true,
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['datetime_used']);
    }

    public function testPublishWithDatetimeFalse(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => false,
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['datetime_used']);
    }

    public function testPublishWithDatetimeNull(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => null,
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testPublishInvalidDatetime(): void
    {
        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => 'not-a-date',
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid datetime format', $data['error']);
    }

    public function testPublishException(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjects')
            ->willThrowException(new \Exception('Publish error'));

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Bulk publish operation failed', $data['error']);
        $this->assertStringContainsString('Publish error', $data['error']);
    }

    public function testPublishDefaultDatetimeWhenNotProvided(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjects')->willReturn(['uuid1']);

        // No datetime key in params at all - defaults to true
        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->publish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['datetime_used']);
    }

    // ========================================================================
    // depublish() tests
    // ========================================================================

    public function testDepublishSuccess(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('depublishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['depublished_count']);
        $this->assertEquals(['uuid1'], $data['depublished_uuids']);
        $this->assertEquals(1, $data['requested_count']);
        $this->assertEquals(0, $data['skipped_count']);
        $this->assertEquals('Bulk depublish operation completed successfully', $data['message']);
    }

    public function testDepublishWithSkippedUuids(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('depublishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1', 'uuid2', 'uuid3'],
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(1, $data['depublished_count']);
        $this->assertEquals(3, $data['requested_count']);
        $this->assertEquals(2, $data['skipped_count']);
    }

    public function testDepublishMissingUuids(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDepublishEmptyUuidsArray(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn(['uuids' => []]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDepublishUuidsNotArray(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn(['uuids' => 'not-array']);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testDepublishWithValidDatetime(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('depublishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => '2024-06-15T10:30:00Z',
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('2024-06-15 10:30:00', $data['datetime_used']);
    }

    public function testDepublishWithDatetimeTrue(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('depublishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => true,
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['datetime_used']);
    }

    public function testDepublishWithDatetimeFalse(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('depublishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => false,
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['datetime_used']);
    }

    public function testDepublishWithDatetimeNull(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('depublishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => null,
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testDepublishInvalidDatetime(): void
    {
        $this->setupResolveSuccess();

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
            'datetime' => 'invalid-date-string',
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid datetime format', $data['error']);
    }

    public function testDepublishDefaultDatetimeWhenNotProvided(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('depublishObjects')->willReturn(['uuid1']);

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['datetime_used']);
    }

    public function testDepublishRegisterNotFound(): void
    {
        $this->objectService->method('setRegister')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->depublish('nonexistent', '2');

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Register not found', $data['error']);
    }

    public function testDepublishSchemaNotFound(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->depublish('1', 'nonexistent');

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Schema not found', $data['error']);
    }

    public function testDepublishException(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('depublishObjects')
            ->willThrowException(new \Exception('Depublish error'));

        $this->request->method('getParams')->willReturn([
            'uuids' => ['uuid1'],
        ]);

        $result = $this->controller->depublish('1', '2');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Bulk depublish operation failed', $data['error']);
        $this->assertStringContainsString('Depublish error', $data['error']);
    }

    // ========================================================================
    // save() tests
    // ========================================================================

    public function testSaveSuccess(): void
    {
        $this->setupResolveSuccess();
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
        $this->assertEquals(2, $data['requested_count']);
        $this->assertEquals('Bulk save operation completed successfully', $data['message']);
    }

    public function testSaveMissingObjects(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('objects', $data['error']);
    }

    public function testSaveEmptyObjectsArray(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn(['objects' => []]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testSaveObjectsNotArray(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn(['objects' => 'not-an-array']);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testSaveRegisterNotFound(): void
    {
        $this->objectService->method('setRegister')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1']],
        ]);

        $result = $this->controller->save('nonexistent', '2');

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Register not found', $data['error']);
    }

    public function testSaveSchemaNotFound(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1']],
        ]);

        $result = $this->controller->save('1', 'nonexistent');

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Schema not found', $data['error']);
    }

    public function testSaveException(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('saveObjects')
            ->willThrowException(new \Exception('Save error'));

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1']],
        ]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Bulk save operation failed', $data['error']);
        $this->assertStringContainsString('Save error', $data['error']);
    }

    public function testSaveMixedSchemaMode(): void
    {
        // When schema resolves to 0, it means mixed-schema mode
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(0);
        $this->objectService->expects($this->once())
            ->method('saveObjects')
            ->with(
                $this->equalTo([['name' => 'obj1']]),
                $this->equalTo(1),
                $this->isNull(),  // schema should be null for mixed mode
                $this->equalTo(true),
                $this->equalTo(true),
                $this->equalTo(true),
                $this->equalTo(false)
            )
            ->willReturn([
                'statistics' => ['saved' => 1, 'updated' => 0],
            ]);

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1']],
        ]);

        $result = $this->controller->save('1', '0');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['saved_count']);
    }

    public function testSaveWithStatisticsMissingSavedKey(): void
    {
        $this->setupResolveSuccess();
        // Return statistics without 'saved' key
        $this->objectService->method('saveObjects')->willReturn([
            'statistics' => ['updated' => 3],
        ]);

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1']],
        ]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(3, $data['saved_count']); // 0 + 3
    }

    public function testSaveWithStatisticsMissingUpdatedKey(): void
    {
        $this->setupResolveSuccess();
        // Return statistics without 'updated' key
        $this->objectService->method('saveObjects')->willReturn([
            'statistics' => ['saved' => 5],
        ]);

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1']],
        ]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(5, $data['saved_count']); // 5 + 0
    }

    public function testSaveWithEmptyStatistics(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('saveObjects')->willReturn([
            'statistics' => [],
        ]);

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1']],
        ]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(0, $data['saved_count']);
    }

    // ========================================================================
    // publishSchema() tests
    // ========================================================================

    public function testPublishSchemaInvalidId(): void
    {
        $result = $this->controller->publishSchema('1', 'not-numeric');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid schema ID', $data['error']);
    }

    public function testPublishSchemaSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjectsBySchema')->willReturn([
            'published_count' => 5,
            'published_uuids' => ['u1', 'u2', 'u3', 'u4', 'u5'],
            'schema_id' => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->publishSchema('1', '1');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(5, $data['published_count']);
        $this->assertEquals(['u1', 'u2', 'u3', 'u4', 'u5'], $data['published_uuids']);
        $this->assertEquals(1, $data['schema_id']);
        $this->assertFalse($data['publish_all']);
        $this->assertEquals('Schema objects publishing completed successfully', $data['message']);
    }

    public function testPublishSchemaWithPublishAllTrue(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjectsBySchema')->willReturn([
            'published_count' => 10,
            'published_uuids' => ['u1'],
            'schema_id' => 2,
        ]);

        $this->request->method('getParams')->willReturn(['publishAll' => true]);

        $result = $this->controller->publishSchema('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['publish_all']);
    }

    public function testPublishSchemaException(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('publishObjectsBySchema')
            ->willThrowException(new \Exception('Schema publish error'));

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->publishSchema('1', '1');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Schema objects publishing failed', $data['error']);
        $this->assertStringContainsString('Schema publish error', $data['error']);
    }

    // ========================================================================
    // deleteSchema() tests
    // ========================================================================

    public function testDeleteSchemaInvalidId(): void
    {
        $result = $this->controller->deleteSchema('1', 'abc');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid schema ID', $data['error']);
    }

    public function testDeleteSchemaSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObjectsBySchema')->willReturn([
            'deleted_count' => 3,
            'deleted_uuids' => ['u1', 'u2', 'u3'],
            'schema_id' => 2,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->deleteSchema('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(3, $data['deleted_count']);
        $this->assertEquals(['u1', 'u2', 'u3'], $data['deleted_uuids']);
        $this->assertEquals(2, $data['schema_id']);
        $this->assertFalse($data['hard_delete']);
        $this->assertEquals('Schema objects deletion completed successfully', $data['message']);
    }

    public function testDeleteSchemaWithHardDelete(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObjectsBySchema')->willReturn([
            'deleted_count' => 2,
            'deleted_uuids' => ['u1', 'u2'],
            'schema_id' => 3,
        ]);

        $this->request->method('getParams')->willReturn(['hardDelete' => true]);

        $result = $this->controller->deleteSchema('1', '3');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['hard_delete']);
    }

    public function testDeleteSchemaException(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObjectsBySchema')
            ->willThrowException(new \Exception('Schema delete error'));

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->deleteSchema('1', '2');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Schema objects deletion failed', $data['error']);
        $this->assertStringContainsString('Schema delete error', $data['error']);
    }

    // ========================================================================
    // deleteSchemaObjects() tests
    // ========================================================================

    public function testDeleteSchemaObjectsSuccess(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('deleteObjectsBySchema')->willReturn([
            'deleted_count' => 4,
            'deleted_uuids' => ['u1', 'u2', 'u3', 'u4'],
            'schema_id' => 2,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->deleteSchemaObjects('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(4, $data['deleted_count']);
        $this->assertEquals(['u1', 'u2', 'u3', 'u4'], $data['deleted_uuids']);
        $this->assertEquals(1, $data['register_id']);
        $this->assertEquals(2, $data['schema_id']);
        $this->assertFalse($data['hard_delete']);
        $this->assertEquals('Objects deletion completed successfully', $data['message']);
    }

    public function testDeleteSchemaObjectsWithHardDelete(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('deleteObjectsBySchema')->willReturn([
            'deleted_count' => 1,
            'deleted_uuids' => ['u1'],
            'schema_id' => 2,
        ]);

        $this->request->method('getParams')->willReturn(['hardDelete' => true]);

        $result = $this->controller->deleteSchemaObjects('1', '2');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['hard_delete']);
    }

    public function testDeleteSchemaObjectsRegisterNotFound(): void
    {
        $this->objectService->method('setRegister')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->deleteSchemaObjects('nonexistent', '2');

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Register not found', $data['error']);
    }

    public function testDeleteSchemaObjectsSchemaNotFound(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->deleteSchemaObjects('1', 'nonexistent');

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Schema not found', $data['error']);
    }

    public function testDeleteSchemaObjectsException(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('deleteObjectsBySchema')
            ->willThrowException(new \Exception('Delete objects error'));

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->deleteSchemaObjects('1', '2');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Objects deletion failed', $data['error']);
        $this->assertStringContainsString('Delete objects error', $data['error']);
    }

    // ========================================================================
    // deleteRegister() tests
    // ========================================================================

    public function testDeleteRegisterInvalidId(): void
    {
        $result = $this->controller->deleteRegister('abc');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid register ID', $data['error']);
    }

    public function testDeleteRegisterSuccess(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('deleteObjectsByRegister')->willReturn([
            'deleted_count' => 3,
            'deleted_uuids' => ['u1', 'u2', 'u3'],
            'register_id' => 1,
        ]);

        $result = $this->controller->deleteRegister('1');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(3, $data['deleted_count']);
        $this->assertEquals(['u1', 'u2', 'u3'], $data['deleted_uuids']);
        $this->assertEquals(1, $data['register_id']);
        $this->assertEquals('Register objects deletion completed successfully', $data['message']);
    }

    public function testDeleteRegisterException(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('deleteObjectsByRegister')
            ->willThrowException(new \Exception('Register delete error'));

        $result = $this->controller->deleteRegister('1');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Register objects deletion failed', $data['error']);
        $this->assertStringContainsString('Register delete error', $data['error']);
    }

    // ========================================================================
    // validateSchema() tests
    // ========================================================================

    public function testValidateSchemaInvalidId(): void
    {
        $result = $this->controller->validateSchema('abc');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid schema ID', $data['error']);
    }

    public function testValidateSchemaSuccess(): void
    {
        $validationResult = ['valid' => 10, 'invalid' => 2, 'errors' => []];
        $this->objectService->method('validateObjectsBySchema')
            ->willReturn($validationResult);

        $result = $this->controller->validateSchema('1');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(10, $data['valid']);
        $this->assertEquals(2, $data['invalid']);
    }

    public function testValidateSchemaException(): void
    {
        $this->objectService->method('validateObjectsBySchema')
            ->willThrowException(new \Exception('Validation error'));

        $result = $this->controller->validateSchema('1');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Schema validation failed', $data['error']);
        $this->assertStringContainsString('Validation error', $data['error']);
    }

    // ========================================================================
    // Additional edge case tests
    // ========================================================================

    public function testDeleteReturnsJsonResponse(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->delete('1', '2');

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    public function testPublishReturnsJsonResponse(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->publish('1', '2');

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    public function testDepublishReturnsJsonResponse(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->depublish('1', '2');

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    public function testSaveReturnsJsonResponse(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->save('1', '2');

        $this->assertInstanceOf(JSONResponse::class, $result);
    }
}
