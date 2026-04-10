<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\GdprEntitiesController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
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

    /**
     * Helper: create a mock IQueryBuilder that supports chained calls.
     * $fetchRows is an array of row arrays to return from fetch().
     * $fetchOneValue is a scalar to return from fetchOne().
     */
    private function createMockQueryBuilder(array $fetchRows = [], mixed $fetchOneValue = null): IQueryBuilder&MockObject
    {
        $qb = $this->createMock(IQueryBuilder::class);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq_expr');
        $expr->method('iLike')->willReturn('ilike_expr');
        $qb->method('expr')->willReturn($expr);

        $queryFunction = $this->createMock(IQueryFunction::class);
        $queryFunction->method('__toString')->willReturn('COUNT(*)');

        $funcBuilder = $this->createMock(IFunctionBuilder::class);
        $funcBuilder->method('count')->willReturn($queryFunction);
        $qb->method('func')->willReturn($funcBuilder);

        $qb->method('select')->willReturnSelf();
        $qb->method('selectDistinct')->willReturnSelf();
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturn('param');
        $qb->method('createFunction')->willReturn($queryFunction);
        $qb->method('getSQL')->willReturn('SQL');

        $result = $this->createMock(IResult::class);

        // Build fetch sequence: each row, then false.
        $fetchSequence = array_merge($fetchRows, [false]);
        $result->method('fetch')->willReturnOnConsecutiveCalls(...$fetchSequence);

        if ($fetchOneValue !== null) {
            $result->method('fetchOne')->willReturn($fetchOneValue);
        }

        $result->method('closeCursor')->willReturn(true);

        $qb->method('executeQuery')->willReturn($result);

        return $qb;
    }

    // =========================================================================
    // index() tests
    // =========================================================================

    public function testIndexSuccessNoFilters(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 10],
                ['offset', 0, 0],
                ['search', '', ''],
                ['type', '', ''],
                ['category', '', ''],
            ]);

        $entityRow = [
            'id'             => '1',
            'uuid'           => 'abc-123',
            'type'           => 'EMAIL',
            'value'          => 'test@example.com',
            'category'       => 'contact',
            'detected_at'    => '2024-01-01',
            'updated_at'     => '2024-01-02',
            'relation_count' => '3',
        ];

        $mainQb  = $this->createMockQueryBuilder([$entityRow]);
        $subQb   = $this->createMockQueryBuilder();
        $countQb = $this->createMockQueryBuilder([], '5');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($mainQb, $subQb, $countQb);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals(5, $data['count']);
        $this->assertEquals(10, $data['limit']);
        $this->assertEquals(0, $data['offset']);

        $entity = $data['data'][0];
        $this->assertEquals(1, $entity['id']);
        $this->assertEquals('abc-123', $entity['uuid']);
        $this->assertEquals('EMAIL', $entity['type']);
        $this->assertEquals('test@example.com', $entity['value']);
        $this->assertEquals('contact', $entity['category']);
        $this->assertEquals('2024-01-01', $entity['detectedAt']);
        $this->assertEquals('2024-01-02', $entity['updatedAt']);
        $this->assertEquals(3, $entity['relationCount']);
    }

    public function testIndexSuccessWithSearchFilter(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', '', 'test'],
                ['type', '', ''],
                ['category', '', ''],
            ]);

        $mainQb  = $this->createMockQueryBuilder([]);
        $subQb   = $this->createMockQueryBuilder();
        $countQb = $this->createMockQueryBuilder([], '0');

        $mainQb->expects($this->atLeastOnce())->method('andWhere');
        $countQb->expects($this->atLeastOnce())->method('andWhere');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($mainQb, $subQb, $countQb);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEmpty($data['data']);
        $this->assertEquals(0, $data['count']);
    }

    public function testIndexSuccessWithTypeFilter(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', '', ''],
                ['type', '', 'EMAIL'],
                ['category', '', ''],
            ]);

        $mainQb  = $this->createMockQueryBuilder([]);
        $subQb   = $this->createMockQueryBuilder();
        $countQb = $this->createMockQueryBuilder([], '0');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($mainQb, $subQb, $countQb);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testIndexSuccessWithCategoryFilter(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', '', ''],
                ['type', '', ''],
                ['category', '', 'personal'],
            ]);

        $mainQb  = $this->createMockQueryBuilder([]);
        $subQb   = $this->createMockQueryBuilder();
        $countQb = $this->createMockQueryBuilder([], '0');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($mainQb, $subQb, $countQb);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testIndexSuccessWithAllFilters(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 25],
                ['offset', 0, 10],
                ['search', '', 'john'],
                ['type', '', 'NAME'],
                ['category', '', 'identity'],
            ]);

        $row = [
            'id'             => '5',
            'uuid'           => 'def-456',
            'type'           => 'NAME',
            'value'          => 'John Doe',
            'category'       => 'identity',
            'detected_at'    => '2024-03-01',
            'updated_at'     => '2024-03-02',
            'relation_count' => '1',
        ];

        $mainQb  = $this->createMockQueryBuilder([$row]);
        $subQb   = $this->createMockQueryBuilder();
        $countQb = $this->createMockQueryBuilder([], '1');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($mainQb, $subQb, $countQb);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(25, $data['limit']);
        $this->assertEquals(10, $data['offset']);
        $this->assertEquals('John Doe', $data['data'][0]['value']);
    }

    public function testIndexSuccessMultipleRows(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', '', ''],
                ['type', '', ''],
                ['category', '', ''],
            ]);

        $row1 = [
            'id'             => '1',
            'uuid'           => 'uuid-1',
            'type'           => 'EMAIL',
            'value'          => 'a@b.com',
            'category'       => 'contact',
            'detected_at'    => '2024-01-01',
            'updated_at'     => '2024-01-02',
            'relation_count' => '2',
        ];
        $row2 = [
            'id'             => '2',
            'uuid'           => 'uuid-2',
            'type'           => 'PHONE',
            'value'          => '0612345678',
            'category'       => 'contact',
            'detected_at'    => '2024-02-01',
            'updated_at'     => '2024-02-02',
            'relation_count' => '0',
        ];

        $mainQb  = $this->createMockQueryBuilder([$row1, $row2]);
        $subQb   = $this->createMockQueryBuilder();
        $countQb = $this->createMockQueryBuilder([], '2');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($mainQb, $subQb, $countQb);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
        $this->assertEquals(2, $data['count']);
        $this->assertEquals(1, $data['data'][0]['id']);
        $this->assertEquals(2, $data['data'][1]['id']);
    }

    public function testIndexSuccessEmptyResults(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', '', ''],
                ['type', '', ''],
                ['category', '', ''],
            ]);

        $mainQb  = $this->createMockQueryBuilder([]);
        $subQb   = $this->createMockQueryBuilder();
        $countQb = $this->createMockQueryBuilder([], '0');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($mainQb, $subQb, $countQb);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEmpty($data['data']);
        $this->assertEquals(0, $data['count']);
        $this->assertEquals(50, $data['limit']);
        $this->assertEquals(0, $data['offset']);
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

    public function testIndexExceptionMessageContent(): void
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
            ->willThrowException(new \Exception('Connection lost'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->index();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Connection lost', $data['message']);
    }

    public function testIndexDefaultLimitAndOffset(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', '', ''],
                ['type', '', ''],
                ['category', '', ''],
            ]);

        $mainQb  = $this->createMockQueryBuilder([]);
        $subQb   = $this->createMockQueryBuilder();
        $countQb = $this->createMockQueryBuilder([], '0');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($mainQb, $subQb, $countQb);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertEquals(50, $data['limit']);
        $this->assertEquals(0, $data['offset']);
    }

    // =========================================================================
    // show() tests
    // =========================================================================

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

    public function testShowSuccessWithRelations(): void
    {
        $entity = $this->createMock(GdprEntity::class);
        $entity->method('jsonSerialize')->willReturn([
            'id'   => 1,
            'type' => 'EMAIL',
            'value' => 'test@example.com',
        ]);

        $relation = $this->createMock(\OCA\OpenRegister\Db\EntityRelation::class);
        $relation->method('jsonSerialize')->willReturn([
            'id'        => 10,
            'entityId'  => 1,
            'objectId'  => 42,
        ]);

        $this->entityMapper->method('find')->willReturn($entity);
        $this->entityRelationMapper->method('findByEntityId')->willReturn([$relation]);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['relations']);
        $this->assertEquals(10, $data['relations'][0]['id']);
    }

    public function testShowSuccessWithMultipleRelations(): void
    {
        $entity = $this->createMock(GdprEntity::class);
        $entity->method('jsonSerialize')->willReturn(['id' => 1]);

        $relation1 = $this->createMock(\OCA\OpenRegister\Db\EntityRelation::class);
        $relation1->method('jsonSerialize')->willReturn(['id' => 10]);

        $relation2 = $this->createMock(\OCA\OpenRegister\Db\EntityRelation::class);
        $relation2->method('jsonSerialize')->willReturn(['id' => 11]);

        $this->entityMapper->method('find')->willReturn($entity);
        $this->entityRelationMapper->method('findByEntityId')->willReturn([$relation1, $relation2]);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['relations']);
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

    public function testShowNotFoundMessage(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Entity not found', $data['message']);
    }

    public function testShowException(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->show(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testShowExceptionMessageContent(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new \Exception('Unexpected error'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->show(42);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Unexpected error', $data['message']);
    }

    public function testShowSuccessEntityDataPassedThrough(): void
    {
        $entityData = [
            'id'       => 5,
            'type'     => 'BSN',
            'value'    => '123456789',
            'category' => 'identity',
        ];

        $entity = $this->createMock(GdprEntity::class);
        $entity->method('jsonSerialize')->willReturn($entityData);
        $this->entityMapper->method('find')->with(5)->willReturn($entity);
        $this->entityRelationMapper->method('findByEntityId')->with(5)->willReturn([]);

        $result = $this->controller->show(5);

        $data = $result->getData();
        $this->assertEquals($entityData, $data['data']);
        $this->assertEmpty($data['relations']);
    }

    // =========================================================================
    // getTypes() tests
    // =========================================================================

    public function testGetTypesSuccess(): void
    {
        $qb = $this->createMockQueryBuilder([
            ['type' => 'EMAIL'],
            ['type' => 'NAME'],
            ['type' => 'PHONE'],
        ]);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->controller->getTypes();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['EMAIL', 'NAME', 'PHONE'], $data['data']);
    }

    public function testGetTypesSuccessEmpty(): void
    {
        $qb = $this->createMockQueryBuilder([]);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->controller->getTypes();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEmpty($data['data']);
    }

    public function testGetTypesSingleType(): void
    {
        $qb = $this->createMockQueryBuilder([
            ['type' => 'BSN'],
        ]);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->controller->getTypes();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals('BSN', $data['data'][0]);
    }

    public function testGetTypesException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getTypes();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testGetTypesExceptionLogsError(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Type query failed'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->getTypes();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Failed to get entity types', $data['message']);
    }

    // =========================================================================
    // getCategories() tests
    // =========================================================================

    public function testGetCategoriesSuccess(): void
    {
        $qb = $this->createMockQueryBuilder([
            ['category' => 'contact'],
            ['category' => 'identity'],
            ['category' => 'financial'],
        ]);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->controller->getCategories();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['contact', 'identity', 'financial'], $data['data']);
    }

    public function testGetCategoriesSuccessEmpty(): void
    {
        $qb = $this->createMockQueryBuilder([]);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->controller->getCategories();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEmpty($data['data']);
    }

    public function testGetCategoriesSingleCategory(): void
    {
        $qb = $this->createMockQueryBuilder([
            ['category' => 'medical'],
        ]);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->controller->getCategories();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals('medical', $data['data'][0]);
    }

    public function testGetCategoriesException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getCategories();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testGetCategoriesExceptionLogsError(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Category query failed'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->getCategories();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Failed to get entity categories', $data['message']);
    }

    // =========================================================================
    // getStats() tests
    // =========================================================================

    public function testGetStatsSuccess(): void
    {
        $totalQb = $this->createMockQueryBuilder([], '15');
        $typeQb = $this->createMockQueryBuilder([
            ['type' => 'EMAIL', 'count' => '8'],
            ['type' => 'PHONE', 'count' => '5'],
            ['type' => 'NAME', 'count' => '2'],
        ]);
        $catQb = $this->createMockQueryBuilder([
            ['category' => 'contact', 'count' => '13'],
            ['category' => 'identity', 'count' => '2'],
        ]);
        $relQb = $this->createMockQueryBuilder([], '42');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($totalQb, $typeQb, $catQb, $relQb);

        $result = $this->controller->getStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(15, $data['data']['totalEntities']);
        $this->assertEquals(42, $data['data']['totalRelations']);
        $this->assertEquals(['EMAIL' => 8, 'PHONE' => 5, 'NAME' => 2], $data['data']['byType']);
        $this->assertEquals(['contact' => 13, 'identity' => 2], $data['data']['byCategory']);
    }

    public function testGetStatsSuccessEmpty(): void
    {
        $totalQb = $this->createMockQueryBuilder([], '0');
        $typeQb  = $this->createMockQueryBuilder([]);
        $catQb   = $this->createMockQueryBuilder([]);
        $relQb   = $this->createMockQueryBuilder([], '0');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($totalQb, $typeQb, $catQb, $relQb);

        $result = $this->controller->getStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(0, $data['data']['totalEntities']);
        $this->assertEquals(0, $data['data']['totalRelations']);
        $this->assertEmpty($data['data']['byType']);
        $this->assertEmpty($data['data']['byCategory']);
    }

    public function testGetStatsException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getStats();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testGetStatsExceptionLogsError(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Stats query failed'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->getStats();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Failed to get entity statistics', $data['message']);
    }

    public function testGetStatsResponseStructure(): void
    {
        $totalQb = $this->createMockQueryBuilder([], '10');
        $typeQb = $this->createMockQueryBuilder([
            ['type' => 'EMAIL', 'count' => '10'],
        ]);
        $catQb = $this->createMockQueryBuilder([
            ['category' => 'contact', 'count' => '10'],
        ]);
        $relQb = $this->createMockQueryBuilder([], '20');

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($totalQb, $typeQb, $catQb, $relQb);

        $result = $this->controller->getStats();

        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('totalEntities', $data['data']);
        $this->assertArrayHasKey('totalRelations', $data['data']);
        $this->assertArrayHasKey('byType', $data['data']);
        $this->assertArrayHasKey('byCategory', $data['data']);
    }

    // =========================================================================
    // destroy() tests
    // =========================================================================

    public function testDestroySuccess(): void
    {
        $entity = $this->createMock(GdprEntity::class);
        $this->entityMapper->method('find')->willReturn($entity);

        $result = $this->controller->destroy(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Entity deleted successfully', $data['message']);
    }

    public function testDestroyNotFound(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testDestroyNotFoundMessage(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Entity not found', $data['message']);
    }

    public function testDestroyException(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new \Exception('Delete error'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testDestroyExceptionOnDelete(): void
    {
        $entity = $this->createMock(GdprEntity::class);
        $this->entityMapper->method('find')->willReturn($entity);
        $this->entityMapper->method('delete')
            ->willThrowException(new \Exception('Delete failed'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Delete failed', $data['message']);
    }

    public function testDestroyExceptionLogsError(): void
    {
        $this->entityMapper->method('find')
            ->willThrowException(new \Exception('Unexpected'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->destroy(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Unexpected', $data['message']);
    }

    public function testDestroyCallsDeleteOnMapper(): void
    {
        $entity = $this->createMock(GdprEntity::class);
        $this->entityMapper->method('find')->with(7)->willReturn($entity);
        $this->entityMapper->expects($this->once())->method('delete')->with($entity);

        $result = $this->controller->destroy(7);

        $this->assertEquals(200, $result->getStatus());
    }
}
