<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BulkControllerTest extends TestCase
{
    private BulkController $controller;
    private IRequest&MockObject $request;
    private ObjectService&MockObject $objectService;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->controller = new BulkController(
            'openregister',
            $this->request,
            $this->objectService,
            $this->registerMapper,
            $this->schemaMapper,
            $this->userSession,
            $this->groupManager
        );
    }

    /**
     * Helper: stub the user session as an admin user so manage-permission gates pass.
     */
    private function stubAdminUser(string $userId = 'admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with($userId)->willReturn(true);
    }

    /**
     * Helper: stub the schemaMapper to return a default schema entity so the
     * manage-permission gate on deleteSchema/deleteSchemaObjects has something
     * to inspect (admin bypass still does the work).
     */
    private function stubSchemaLookup(?int $schemaId = null): void
    {
        $schema = new Schema();
        if ($schemaId !== null) {
            $ref = new \ReflectionClass($schema);
            $prop = $ref->getProperty('id');
            $prop->setAccessible(true);
            $prop->setValue($schema, $schemaId);
        }
        $this->schemaMapper->method('find')->willReturn($schema);
    }

    /**
     * Helper: stub the registerMapper to return a default register entity so
     * the manage-permission gate on deleteRegister has something to inspect.
     */
    private function stubRegisterLookup(?int $registerId = null): void
    {
        $register = new Register();
        if ($registerId !== null) {
            $ref = new \ReflectionClass($register);
            $prop = $ref->getProperty('id');
            $prop->setAccessible(true);
            $prop->setValue($register, $registerId);
        }
        $this->registerMapper->method('find')->willReturn($register);
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
        $this->objectService->method('deleteObjects')->willReturn([
            'deleted_uuids' => ['uuid1', 'uuid2'],
            'skipped_uuids' => ['uuid3'],
            'cascade_count' => 0,
        ]);

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
        $this->assertEquals(['uuid3'], $data['skipped_uuids']);
        $this->assertEquals(0, $data['cascade_count']);
        $this->assertEquals(2, $data['total_affected']);
        $this->assertEquals('Bulk delete operation completed successfully', $data['message']);
    }

    public function testDeleteAllSuccessful(): void
    {
        $this->setupResolveSuccess();
        $this->objectService->method('deleteObjects')->willReturn([
            'deleted_uuids' => ['uuid1', 'uuid2'],
            'skipped_uuids' => [],
            'cascade_count' => 0,
        ]);

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


    // publish/depublish tests removed — endpoints deprecated per deprecate-published-metadata spec

    // ========================================================================
    // save() tests
    // ========================================================================

    public function testSaveSuccess(): void
    {
        // AUTHORIZATION (wave-11 WF1): caller must be admin or have manage-permission.
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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

    public function testSaveForbiddenWithoutManagePermission(): void
    {
        // AUTHORIZATION (wave-11 WF1): non-admin without manage-permission gets 403.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('regular-user');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $schema = new \OCA\OpenRegister\Db\Schema();
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->setupResolveSuccess();

        $this->request->method('getParams')->willReturn([
            'objects' => [['name' => 'obj1']],
        ]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('permission', $data['error']);
    }

    public function testSaveMissingObjects(): void
    {
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('objects', $data['error']);
    }

    public function testSaveEmptyObjectsArray(): void
    {
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn(['objects' => []]);

        $result = $this->controller->save('1', '2');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testSaveObjectsNotArray(): void
    {
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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

    // publishSchema() tests removed — deprecated per deprecate-published-metadata spec

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

    public function testDeleteSchemaUnauthorizedRejected(): void
    {
        // Wave-3 C5 regression guard: non-admin without manage-permission
        // cannot mass-delete schema objects.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
        $this->stubSchemaLookup(2);
        $this->objectService->expects($this->never())->method('deleteObjectsBySchema');

        $result = $this->controller->deleteSchema('1', '2');

        $this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    public function testDeleteSchemaSuccess(): void
    {
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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
        $this->stubAdminUser();
        $this->stubSchemaLookup(3);
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
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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

    public function testDeleteSchemaObjectsUnauthorizedRejected(): void
    {
        // Wave-3 C5 regression guard.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
        $this->setupResolveSuccess();
        $this->stubSchemaLookup(2);
        $this->objectService->expects($this->never())->method('deleteObjectsBySchema');

        $result = $this->controller->deleteSchemaObjects('1', '2');

        $this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    public function testDeleteSchemaObjectsSuccess(): void
    {
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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
        $this->stubAdminUser();
        $this->stubSchemaLookup(2);
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

    public function testDeleteRegisterUnauthorizedRejected(): void
    {
        // Wave-3 C5 regression guard.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
        $this->stubRegisterLookup(1);
        $this->objectService->expects($this->never())->method('deleteObjectsByRegister');

        $result = $this->controller->deleteRegister('1');

        $this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    public function testDeleteRegisterSuccess(): void
    {
        $this->stubAdminUser();
        $this->stubRegisterLookup(1);
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
        $this->stubAdminUser();
        $this->stubRegisterLookup(1);
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
    // runSchemaValidation() tests
    // ========================================================================

    public function testValidateSchemaInvalidId(): void
    {
        $result = $this->controller->runSchemaValidation('abc');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid schema ID', $data['error']);
    }

    public function testValidateSchemaSuccess(): void
    {
        $validationResult = ['valid' => 10, 'invalid' => 2, 'errors' => []];
        $this->objectService->method('validateObjectsBySchema')
            ->willReturn($validationResult);

        $result = $this->controller->runSchemaValidation('1');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(10, $data['valid']);
        $this->assertEquals(2, $data['invalid']);
    }

    public function testValidateSchemaException(): void
    {
        $this->objectService->method('validateObjectsBySchema')
            ->willThrowException(new \Exception('Validation error'));

        $result = $this->controller->runSchemaValidation('1');

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

    public function testSaveReturnsJsonResponse(): void
    {
        $this->setupResolveSuccess();
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->save('1', '2');

        $this->assertInstanceOf(JSONResponse::class, $result);
    }
}
