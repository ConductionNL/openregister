<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\MigrationController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\MigrationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MigrationController
 *
 * @package Unit\Controller
 */
class MigrationControllerTest extends TestCase
{
    private MigrationController $controller;
    private IRequest&MockObject $request;
    private MigrationService&MockObject $migrationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->migrationService = $this->createMock(MigrationService::class);

        $this->controller = new MigrationController(
            'openregister',
            $this->request,
            $this->migrationService
        );
    }

    private function createRegister(int $id = 1): Register
    {
        $register = new Register();
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        $register->setTitle('Test Register');
        return $register;
    }

    private function createSchema(int $id = 1): Schema
    {
        $schema = new Schema();
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        $schema->setTitle('Test Schema');
        return $schema;
    }

    public function testStatusReturnsStorageStatus(): void
    {
        $register = $this->createRegister();
        $schema = $this->createSchema();
        $resolved = ['register' => $register, 'schema' => $schema];
        $status = ['storage' => 'blob', 'count' => 100];

        $this->migrationService->method('resolveRegisterAndSchema')
            ->willReturn($resolved);
        $this->migrationService->method('getStorageStatus')
            ->willReturn($status);

        $result = $this->controller->status('reg1', 'schema1');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $this->assertSame($status, $result->getData());
    }

    public function testStatusReturns500OnException(): void
    {
        $this->migrationService->method('resolveRegisterAndSchema')
            ->willThrowException(new Exception('Not found'));

        $result = $this->controller->status('bad', 'schema');

        $this->assertSame(500, $result->getStatus());
        $this->assertArrayHasKey('error', $result->getData());
    }

    public function testMigrateReturnsBadRequestWhenMissingParams(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, ''],
                ['schema', null, ''],
                ['direction', null, 'to-magic'],
                ['batchSize', 100, 100],
                ['dryRun', false, false],
            ]);

        $result = $this->controller->migrate();

        $this->assertSame(400, $result->getStatus());
    }

    public function testMigrateReturnsBadRequestForInvalidDirection(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, 'reg1'],
                ['schema', null, 'schema1'],
                ['direction', null, 'invalid'],
                ['batchSize', 100, 100],
                ['dryRun', false, false],
            ]);

        $result = $this->controller->migrate();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('direction', $data['error']);
    }

    public function testMigrateToMagicSuccess(): void
    {
        $register = $this->createRegister();
        $schema = $this->createSchema();
        $resolved = ['register' => $register, 'schema' => $schema];
        $report = ['migrated' => 50, 'failed' => 0];

        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, 'reg1'],
                ['schema', null, 'schema1'],
                ['direction', null, 'to-magic'],
                ['batchSize', 100, 100],
                ['dryRun', false, false],
            ]);

        $this->migrationService->method('resolveRegisterAndSchema')
            ->willReturn($resolved);
        $this->migrationService->expects($this->once())
            ->method('migrateToMagicTable')
            ->willReturn($report);

        $result = $this->controller->migrate();

        $this->assertSame(200, $result->getStatus());
        $this->assertSame($report, $result->getData());
    }

    public function testMigrateToBlobSuccess(): void
    {
        $register = $this->createRegister();
        $schema = $this->createSchema();
        $resolved = ['register' => $register, 'schema' => $schema];
        $report = ['migrated' => 30, 'failed' => 0];

        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, 'reg1'],
                ['schema', null, 'schema1'],
                ['direction', null, 'to-blob'],
                ['batchSize', 100, 50],
                ['dryRun', false, false],
            ]);

        $this->migrationService->method('resolveRegisterAndSchema')
            ->willReturn($resolved);
        $this->migrationService->expects($this->once())
            ->method('migrateToBlobStorage')
            ->willReturn($report);

        $result = $this->controller->migrate();

        $this->assertSame(200, $result->getStatus());
    }

    public function testMigrateReturns500OnException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, 'reg1'],
                ['schema', null, 'schema1'],
                ['direction', null, 'to-magic'],
                ['batchSize', 100, 100],
                ['dryRun', false, false],
            ]);

        $this->migrationService->method('resolveRegisterAndSchema')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->migrate();

        $this->assertSame(500, $result->getStatus());
    }
}
