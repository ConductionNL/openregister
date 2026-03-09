<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\GdprEntitiesController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GdprEntitiesControllerTest extends TestCase
{
    private GdprEntitiesController $controller;
    private IRequest&MockObject $request;
    private GdprEntityMapper&MockObject $entityMapper;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->entityMapper = $this->createMock(GdprEntityMapper::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new GdprEntitiesController(
            'openregister',
            $this->request,
            $this->entityMapper,
            $this->entityRelationMapper,
            $this->db,
            $this->logger
        );
    }

    public function testIndexException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', '', ''],
                ['type', '', ''],
                ['category', '', ''],
            ]);

        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->index();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testShowSuccess(): void
    {
        $entity = $this->createMock(GdprEntity::class);
        $entity->method('jsonSerialize')->willReturn(['id' => 1, 'type' => 'EMAIL']);
        $this->entityMapper->method('find')->willReturn($entity);
        $this->entityRelationMapper->method('findByEntityId')->willReturn([]);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('relations', $data);
    }

    public function testShowNotFound(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testShowException(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->show(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testGetTypesException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getTypes();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testGetCategoriesException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getCategories();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testGetStatsException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getStats();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $entity = $this->createMock(GdprEntity::class);
        $this->entityMapper->method('find')->willReturn($entity);

        $result = $this->controller->destroy(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testDestroyNotFound(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testDestroyException(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new \Exception('Delete error'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }
}
