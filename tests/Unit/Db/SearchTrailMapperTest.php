<?php

declare(strict_types=1);

/**
 * SearchTrailMapperTest
 *
 * Unit tests for the SearchTrailMapper class to verify search trail database operations.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * SearchTrail Mapper Test Suite
 *
 * Unit tests for search trail database operations focusing on
 * class structure and basic functionality.
 *
 * @coversDefaultClass SearchTrailMapper
 */
class SearchTrailMapperTest extends TestCase
{
    private SearchTrailMapper $searchTrailMapper;
    private IDBConnection|MockObject $db;
    private IRequest|MockObject $request;
    private IUserSession|MockObject $userSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->request = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
        
        $this->searchTrailMapper = new SearchTrailMapper(
            $this->db,
            $this->request,
            $this->userSession
        );
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(SearchTrailMapper::class, $this->searchTrailMapper);
    }

    /**
     * Test SearchTrail entity creation
     *
     * @return void
     */
    public function testSearchTrailEntityCreation(): void
    {
        $searchTrail = new SearchTrail();
        $searchTrail->setId(1);
        $searchTrail->setUuid('test-uuid-123');
        $searchTrail->setSearchTerm('test search');
        $searchTrail->setUser('testuser');
        $searchTrail->setUserAgent('Mozilla/5.0');
        $searchTrail->setIpAddress('192.168.1.1');
        $searchTrail->setCreated(new \DateTime('2024-01-01 00:00:00'));

        $this->assertEquals(1, $searchTrail->getId());
        $this->assertEquals('test-uuid-123', $searchTrail->getUuid());
        $this->assertEquals('test search', $searchTrail->getSearchTerm());
        $this->assertEquals('testuser', $searchTrail->getUser());
        $this->assertEquals('Mozilla/5.0', $searchTrail->getUserAgent());
        $this->assertEquals('192.168.1.1', $searchTrail->getIpAddress());
    }

    /**
     * Test SearchTrail entity JSON serialization
     *
     * @return void
     */
    public function testSearchTrailJsonSerialization(): void
    {
        $searchTrail = new SearchTrail();
        $searchTrail->setId(1);
        $searchTrail->setUuid('test-uuid-123');
        $searchTrail->setSearchTerm('test search');
        $searchTrail->setUser('testuser');

        $json = json_encode($searchTrail);
        $this->assertIsString($json);
        $this->assertStringContainsString('test-uuid-123', $json);
        $this->assertStringContainsString('test search', $json);
    }

    /**
     * Test SearchTrail entity string representation
     *
     * @return void
     */
    public function testSearchTrailToString(): void
    {
        $searchTrail = new SearchTrail();
        $searchTrail->setUuid('test-uuid-123');
        
        $this->assertEquals('test-uuid-123', (string)$searchTrail);
    }

    /**
     * Test SearchTrail entity string representation with ID fallback
     *
     * @return void
     */
    public function testSearchTrailToStringWithId(): void
    {
        $searchTrail = new SearchTrail();
        $searchTrail->setId(123);
        
        $this->assertEquals('SearchTrail #123', (string)$searchTrail);
    }

    /**
     * Test SearchTrail entity string representation fallback
     *
     * @return void
     */
    public function testSearchTrailToStringFallback(): void
    {
        $searchTrail = new SearchTrail();
        
        $this->assertEquals('Search Trail', (string)$searchTrail);
    }

    /**
     * Test find method with valid ID
     *
     * @return void
     */
    public function testFindWithValidId(): void
    {
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->with('openregister_search_trails')
            ->willReturnSelf();

        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expressionBuilder->expects($this->once())
            ->method('eq')
            ->willReturn('expr_eq');

        $queryBuilder->expects($this->once())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $queryBuilder->expects($this->once())
            ->method('createNamedParameter')
            ->willReturn(':param');

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 1,
                    'uuid' => 'test-uuid-123',
                    'search_term' => 'test search',
                    'user' => 'testuser',
                    'user_agent' => 'Mozilla/5.0',
                    'ip_address' => '192.168.1.1',
                    'created' => '2024-01-01 00:00:00'
                ],
                false
            );

        $result = $this->searchTrailMapper->find(1);
        $this->assertInstanceOf(SearchTrail::class, $result);
        $this->assertEquals(1, $result->getId());
        $this->assertEquals('test-uuid-123', $result->getUuid());
        $this->assertEquals('test search', $result->getSearchTerm());
    }

    /**
     * Test find method with non-existent ID
     *
     * @return void
     */
    public function testFindWithNonExistentId(): void
    {
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expressionBuilder->expects($this->once())
            ->method('eq')
            ->willReturn('expr_eq');

        $queryBuilder->expects($this->once())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $queryBuilder->expects($this->once())
            ->method('createNamedParameter')
            ->willReturn(':param');

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $this->expectException(DoesNotExistException::class);
        $this->searchTrailMapper->find(999);
    }

    /**
     * Test findAll method
     *
     * @return void
     */
    public function testFindAll(): void
    {
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 1,
                    'uuid' => 'test-uuid-123',
                    'search_term' => 'test search 1',
                    'user' => 'testuser1',
                    'user_agent' => 'Mozilla/5.0',
                    'ip_address' => '192.168.1.1',
                    'created' => '2024-01-01 00:00:00'
                ],
                [
                    'id' => 2,
                    'uuid' => 'test-uuid-456',
                    'search_term' => 'test search 2',
                    'user' => 'testuser2',
                    'user_agent' => 'Chrome/91.0',
                    'ip_address' => '192.168.1.2',
                    'created' => '2024-01-02 00:00:00'
                ],
                false
            );

        $result = $this->searchTrailMapper->findAll();
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(SearchTrail::class, $result[0]);
        $this->assertInstanceOf(SearchTrail::class, $result[1]);
    }

    /**
     * Test count method
     *
     * @return void
     */
    public function testCount(): void
    {
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $functionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $queryFunction = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        $functionBuilder->expects($this->once())
            ->method('count')
            ->willReturn($queryFunction);

        $queryBuilder->expects($this->once())
            ->method('func')
            ->willReturn($functionBuilder);

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->once())
            ->method('fetchOne')
            ->willReturn(42);

        $result = $this->searchTrailMapper->count();
        $this->assertEquals(42, $result);
    }

    /**
     * Test createSearchTrail method
     *
     * @return void
     */
    public function testCreateSearchTrail(): void
    {
        $searchQuery = ['q' => 'test search', 'filters' => []];
        $resultCount = 10;
        $totalResults = 100;
        $responseTime = 0.5;
        $executionType = 'sync';

        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('insert')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(1))
            ->method('setValue')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $queryBuilder->expects($this->once())
            ->method('getLastInsertId')
            ->willReturn(1);

        $result = $this->searchTrailMapper->createSearchTrail(
            $searchQuery,
            $resultCount,
            $totalResults,
            $responseTime,
            $executionType
        );
        $this->assertInstanceOf(SearchTrail::class, $result);
    }

    /**
     * Test getSearchStatistics method
     *
     * @return void
     */
    public function testGetSearchStatistics(): void
    {
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-01-31');

        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('addSelect')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('andWhere')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('createNamedParameter')
            ->willReturn('?');

        // Mock func() method
        $functionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $queryFunction = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        $queryBuilder->expects($this->any())
            ->method('func')
            ->willReturn($functionBuilder);

        $functionBuilder->expects($this->any())
            ->method('count')
            ->willReturn($queryFunction);

        $queryBuilder->expects($this->any())
            ->method('createFunction')
            ->willReturn('COALESCE(SUM(CASE WHEN total_results IS NOT NULL THEN total_results ELSE 0 END), 0)');

        // Mock expr() method
        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $queryBuilder->expects($this->any())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->any())
            ->method('gte')
            ->willReturn('created >= ?');

        $expressionBuilder->expects($this->any())
            ->method('lte')
            ->willReturn('created <= ?');

        $mockResult = $this->createMock(IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_searches' => 100,
                'total_results' => 500,
                'avg_results_per_search' => 5.0,
                'avg_response_time' => 0.5,
                'non_empty_searches' => 80
            ]);

        $result = $this->searchTrailMapper->getSearchStatistics($from, $to);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('total_searches', $result);
        $this->assertArrayHasKey('total_results', $result);
        $this->assertArrayHasKey('avg_results_per_search', $result);
        $this->assertArrayHasKey('avg_response_time', $result);
        $this->assertArrayHasKey('non_empty_searches', $result);
    }

    /**
     * Test getPopularSearchTerms method
     *
     * @return void
     */
    public function testGetPopularSearchTerms(): void
    {
        $limit = 5;
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-01-31');

        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('addSelect')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('groupBy')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('orderBy')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->willReturnSelf();

        // Mock func() method
        $functionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $queryFunction = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        $queryBuilder->expects($this->any())
            ->method('func')
            ->willReturn($functionBuilder);

        $functionBuilder->expects($this->any())
            ->method('count')
            ->willReturn($queryFunction);

        // Mock expr() method
        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $queryBuilder->expects($this->any())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->any())
            ->method('isNotNull')
            ->willReturn('search_term IS NOT NULL');

        $expressionBuilder->expects($this->any())
            ->method('gte')
            ->willReturn('created >= ?');

        $expressionBuilder->expects($this->any())
            ->method('lte')
            ->willReturn('created <= ?');

        $queryBuilder->expects($this->any())
            ->method('createNamedParameter')
            ->willReturn('?');

        $queryBuilder->expects($this->any())
            ->method('andWhere')
            ->willReturnSelf();

        $mockResult = $this->createMock(IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['search_term' => 'test', 'search_count' => 10, 'avg_results' => 5.0, 'avg_response_time' => 0.5],
                ['search_term' => 'example', 'search_count' => 8, 'avg_results' => 4.0, 'avg_response_time' => 0.4],
                ['search_term' => 'demo', 'search_count' => 5, 'avg_results' => 3.0, 'avg_response_time' => 0.3]
            ]);

        $result = $this->searchTrailMapper->getPopularSearchTerms($limit, $from, $to);
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals('test', $result[0]['term']);
        $this->assertEquals(10, $result[0]['count']);
    }

    /**
     * Test getUniqueSearchTermsCount method
     *
     * @return void
     */
    public function testGetUniqueSearchTermsCount(): void
    {
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-01-31');

        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('selectDistinct')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('createNamedParameter')
            ->willReturn('?');

        $queryBuilder->expects($this->any())
            ->method('andWhere')
            ->willReturnSelf();

        // Mock func() method
        $functionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $queryFunction = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        $queryBuilder->expects($this->any())
            ->method('func')
            ->willReturn($functionBuilder);

        $functionBuilder->expects($this->any())
            ->method('count')
            ->willReturn($queryFunction);

        // Mock expr() method
        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $queryBuilder->expects($this->any())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->any())
            ->method('gte')
            ->willReturn('created >= ?');

        $expressionBuilder->expects($this->any())
            ->method('lte')
            ->willReturn('created <= ?');

        $mockResult = $this->createMock(IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['search_term' => 'term1'],
                ['search_term' => 'term2'],
                ['search_term' => 'term3'],
                ['search_term' => 'term4'],
                ['search_term' => 'term5']
            ]);

        $result = $this->searchTrailMapper->getUniqueSearchTermsCount($from, $to);
        $this->assertEquals(5, $result);
    }

    /**
     * Test getUniqueUsersCount method
     *
     * @return void
     */
    public function testGetUniqueUsersCount(): void
    {
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-01-31');

        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('selectDistinct')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('createNamedParameter')
            ->willReturn('?');

        $queryBuilder->expects($this->any())
            ->method('andWhere')
            ->willReturnSelf();

        // Mock func() method
        $functionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $queryFunction = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        $queryBuilder->expects($this->any())
            ->method('func')
            ->willReturn($functionBuilder);

        $functionBuilder->expects($this->any())
            ->method('count')
            ->willReturn($queryFunction);

        // Mock expr() method
        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $queryBuilder->expects($this->any())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->any())
            ->method('gte')
            ->willReturn('created >= ?');

        $expressionBuilder->expects($this->any())
            ->method('lte')
            ->willReturn('created <= ?');

        $expressionBuilder->expects($this->any())
            ->method('isNotNull')
            ->willReturn('user IS NOT NULL');

        $expressionBuilder->expects($this->any())
            ->method('neq')
            ->willReturn('user != ?');

        $mockResult = $this->createMock(IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['user' => 'user1'],
                ['user' => 'user2'],
                ['user' => 'user3']
            ]);

        $result = $this->searchTrailMapper->getUniqueUsersCount($from, $to);
        $this->assertEquals(3, $result);
    }

    /**
     * Test clearLogs method
     *
     * @return void
     */
    public function testClearLogs(): void
    {
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('delete')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        // Mock expr() method
        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $queryBuilder->expects($this->any())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->any())
            ->method('isNotNull')
            ->willReturn('expires IS NOT NULL');

        $expressionBuilder->expects($this->any())
            ->method('lt')
            ->willReturn('expires < NOW()');

        $queryBuilder->expects($this->any())
            ->method('createFunction')
            ->willReturn('NOW()');

        $queryBuilder->expects($this->any())
            ->method('andWhere')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('executeStatement')
            ->willReturn(10);

        $result = $this->searchTrailMapper->clearLogs();
        $this->assertTrue($result);
    }

}//end class
