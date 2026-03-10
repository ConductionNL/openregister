<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\TablesController;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TablesController
 *
 * @package Unit\Controller
 */
class TablesControllerTest extends TestCase
{
    private TablesController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private MagicMapper&MockObject $magicMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->magicMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new TablesController(
            'openregister',
            $this->request,
            $this->config,
            $this->magicMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->logger
        );
    }

    private function createRegister(int $id = 1, ?array $schemas = null): Register
    {
        $register = new Register();
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        $register->setTitle('Test Register');
        if ($schemas !== null) {
            // Set schemas directly via Reflection to avoid the named-arg bug
            // in Register::setSchemas() -> parent::setSchemas(schemas: ...).
            $schemasProp = $ref->getProperty('schemas');
            $schemasProp->setAccessible(true);
            $schemasProp->setValue($register, $schemas);
        }
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

    public function testSyncReturnsSuccessForNumericIds(): void
    {
        $register = $this->createRegister();
        $schema = $this->createSchema();

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->magicMapper->method('syncTableForRegisterSchema')
            ->willReturn([
                'metadataProperties' => 5,
                'regularProperties' => 10,
                'columnsAdded' => 2,
                'columnsAddedList' => ['col1', 'col2'],
                'columnsDropped' => 0,
                'columnsDroppedList' => [],
                'columnsDeRequired' => 0,
                'columnsDeRequiredList' => [],
                'columnsReRequired' => 0,
                'columnsReRequiredList' => [],
                'columnsUnchanged' => 13,
                'totalProperties' => 15,
            ]);

        $result = $this->controller->sync(1, 1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('Magic table synchronized successfully', $data['message']);
    }

    public function testSyncReturns500WhenRegisterNotFound(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Register not found'));

        $result = $this->controller->sync(999, 1);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to sync magic table', $data['error']);
    }

    public function testSyncReturns500WhenSchemaNotFound(): void
    {
        $register = $this->createRegister();
        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Schema not found'));

        $result = $this->controller->sync(1, 999);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to sync magic table', $data['error']);
    }

    public function testSyncReturns500OnException(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->sync(1, 1);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Failed to sync magic table', $data['error']);
    }

    public function testSyncWithStringNumericIds(): void
    {
        $register = $this->createRegister();
        $schema = $this->createSchema();

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->magicMapper->method('syncTableForRegisterSchema')
            ->willReturn([]);

        // Numeric strings still use find() via is_numeric() check.
        $result = $this->controller->sync('1', '1');

        $this->assertSame(200, $result->getStatus());
    }

    public function testSyncAllReturnsResults(): void
    {
        $register = $this->createRegister(1, [1, 2]);

        $schema = $this->createSchema();

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->magicMapper->method('syncTableForRegisterSchema')
            ->willReturn([]);

        $result = $this->controller->syncAll();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('synced', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('totalSynced', $data);
    }

    public function testSyncAllHandsIndividualErrors(): void
    {
        $register = $this->createRegister(1, [1]);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Schema sync failed'));

        $result = $this->controller->syncAll();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertGreaterThan(0, $data['totalErrors']);
    }

    public function testSyncAllReturns500OnGlobalException(): void
    {
        $this->registerMapper->method('findAll')
            ->willThrowException(new Exception('Global error'));

        $result = $this->controller->syncAll();

        $this->assertSame(500, $result->getStatus());
    }

    public function testSyncAllSkipsNonArraySchemas(): void
    {
        $register = $this->createRegister(1);

        $this->registerMapper->method('findAll')->willReturn([$register]);

        $result = $this->controller->syncAll();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(0, $data['totalSynced']);
    }
}
