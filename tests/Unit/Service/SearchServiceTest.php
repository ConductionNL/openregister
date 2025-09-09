<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Test class for SearchService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class SearchServiceTest extends TestCase
{
    private SearchService $searchService;
    private ObjectEntityMapper $objectEntityMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        // Create SearchService instance
        $this->searchService = new SearchService(
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->schemaMapper
        );
    }

    /**
     * Test search method with valid query
     */
    public function testSearchWithValidQuery(): void
    {
        $query = 'test search';
        $limit = 10;
        $offset = 0;

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('getId')->willReturn('1');
        $object1->method('getTitle')->willReturn('Test Object 1');
        $object1->method('getRegister')->willReturn('test-register');
        $object1->method('getSchema')->willReturn('test-schema');

        $object2 = $this->createMock(ObjectEntity::class);
        $object2->method('getId')->willReturn('2');
        $object2->method('getTitle')->willReturn('Test Object 2');
        $object2->method('getRegister')->willReturn('test-register');
        $object2->method('getSchema')->willReturn('test-schema');

        $objects = [$object1, $object2];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('search')
            ->with($query, $limit, $offset)
            ->willReturn($objects);

        $result = $this->searchService->search($query, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals($objects, $result['objects']);
    }

    /**
     * Test search method with empty query
     */
    public function testSearchWithEmptyQuery(): void
    {
        $query = '';
        $limit = 10;
        $offset = 0;

        $result = $this->searchService->search($query, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertCount(0, $result['objects']);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test search method with no results
     */
    public function testSearchWithNoResults(): void
    {
        $query = 'nonexistent';
        $limit = 10;
        $offset = 0;

        // Mock object entity mapper to return empty array
        $this->objectEntityMapper->expects($this->once())
            ->method('search')
            ->with($query, $limit, $offset)
            ->willReturn([]);

        $result = $this->searchService->search($query, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertCount(0, $result['objects']);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test search method with default parameters
     */
    public function testSearchWithDefaultParameters(): void
    {
        $query = 'test search';

        // Create mock objects
        $objects = [$this->createMock(ObjectEntity::class)];

        // Mock object entity mapper with default parameters
        $this->objectEntityMapper->expects($this->once())
            ->method('search')
            ->with($query, 20, 0) // default limit and offset
            ->willReturn($objects);

        $result = $this->searchService->search($query);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('total', $result);
    }

    /**
     * Test searchByRegister method
     */
    public function testSearchByRegister(): void
    {
        $query = 'test search';
        $registerId = 'test-register';
        $limit = 10;
        $offset = 0;

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('getId')->willReturn('1');
        $object1->method('getTitle')->willReturn('Test Object 1');

        $objects = [$object1];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('searchByRegister')
            ->with($query, $registerId, $limit, $offset)
            ->willReturn($objects);

        $result = $this->searchService->searchByRegister($query, $registerId, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals($objects, $result['objects']);
    }

    /**
     * Test searchBySchema method
     */
    public function testSearchBySchema(): void
    {
        $query = 'test search';
        $registerId = 'test-register';
        $schemaId = 'test-schema';
        $limit = 10;
        $offset = 0;

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('getId')->willReturn('1');
        $object1->method('getTitle')->willReturn('Test Object 1');

        $objects = [$object1];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('searchBySchema')
            ->with($query, $registerId, $schemaId, $limit, $offset)
            ->willReturn($objects);

        $result = $this->searchService->searchBySchema($query, $registerId, $schemaId, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals($objects, $result['objects']);
    }

    /**
     * Test searchRegisters method
     */
    public function testSearchRegisters(): void
    {
        $query = 'test register';
        $limit = 10;
        $offset = 0;

        // Create mock registers
        $register1 = $this->createMock(Register::class);
        $register1->method('getId')->willReturn('1');
        $register1->method('getTitle')->willReturn('Test Register 1');

        $register2 = $this->createMock(Register::class);
        $register2->method('getId')->willReturn('2');
        $register2->method('getTitle')->willReturn('Test Register 2');

        $registers = [$register1, $register2];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('search')
            ->with($query, $limit, $offset)
            ->willReturn($registers);

        $result = $this->searchService->searchRegisters($query, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals($registers, $result['registers']);
    }

    /**
     * Test searchSchemas method
     */
    public function testSearchSchemas(): void
    {
        $query = 'test schema';
        $registerId = 'test-register';
        $limit = 10;
        $offset = 0;

        // Create mock schemas
        $schema1 = $this->createMock(Schema::class);
        $schema1->method('getId')->willReturn('1');
        $schema1->method('getTitle')->willReturn('Test Schema 1');

        $schema2 = $this->createMock(Schema::class);
        $schema2->method('getId')->willReturn('2');
        $schema2->method('getTitle')->willReturn('Test Schema 2');

        $schemas = [$schema1, $schema2];

        // Mock schema mapper
        $this->schemaMapper->expects($this->once())
            ->method('search')
            ->with($query, $registerId, $limit, $offset)
            ->willReturn($schemas);

        $result = $this->searchService->searchSchemas($query, $registerId, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('schemas', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals($schemas, $result['schemas']);
    }

    /**
     * Test searchWithFilters method
     */
    public function testSearchWithFilters(): void
    {
        $query = 'test search';
        $filters = [
            'register' => 'test-register',
            'schema' => 'test-schema',
            'status' => 'published'
        ];
        $limit = 10;
        $offset = 0;

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('getId')->willReturn('1');
        $object1->method('getTitle')->willReturn('Test Object 1');

        $objects = [$object1];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('searchWithFilters')
            ->with($query, $filters, $limit, $offset)
            ->willReturn($objects);

        $result = $this->searchService->searchWithFilters($query, $filters, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals($objects, $result['objects']);
    }

    /**
     * Test searchWithFilters method with empty filters
     */
    public function testSearchWithFiltersWithEmptyFilters(): void
    {
        $query = 'test search';
        $filters = [];
        $limit = 10;
        $offset = 0;

        // Create mock objects
        $objects = [$this->createMock(ObjectEntity::class)];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('searchWithFilters')
            ->with($query, $filters, $limit, $offset)
            ->willReturn($objects);

        $result = $this->searchService->searchWithFilters($query, $filters, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('total', $result);
    }

    /**
     * Test getSearchSuggestions method
     */
    public function testGetSearchSuggestions(): void
    {
        $query = 'test';
        $limit = 5;

        // Create mock suggestions
        $suggestions = [
            'test object',
            'test register',
            'test schema',
            'test data',
            'test item'
        ];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('getSearchSuggestions')
            ->with($query, $limit)
            ->willReturn($suggestions);

        $result = $this->searchService->getSearchSuggestions($query, $limit);

        $this->assertIsArray($result);
        $this->assertEquals($suggestions, $result);
    }

    /**
     * Test getSearchSuggestions method with default limit
     */
    public function testGetSearchSuggestionsWithDefaultLimit(): void
    {
        $query = 'test';

        // Create mock suggestions
        $suggestions = ['test object', 'test register'];

        // Mock object entity mapper with default limit
        $this->objectEntityMapper->expects($this->once())
            ->method('getSearchSuggestions')
            ->with($query, 10) // default limit
            ->willReturn($suggestions);

        $result = $this->searchService->getSearchSuggestions($query);

        $this->assertIsArray($result);
        $this->assertEquals($suggestions, $result);
    }

    /**
     * Test getSearchSuggestions method with empty query
     */
    public function testGetSearchSuggestionsWithEmptyQuery(): void
    {
        $query = '';

        $result = $this->searchService->getSearchSuggestions($query);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test getSearchStatistics method
     */
    public function testGetSearchStatistics(): void
    {
        // Create mock statistics
        $statistics = [
            'total_objects' => 1000,
            'total_registers' => 50,
            'total_schemas' => 200,
            'search_count_today' => 25,
            'popular_queries' => ['test', 'data', 'object']
        ];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('getSearchStatistics')
            ->willReturn($statistics);

        $result = $this->searchService->getSearchStatistics();

        $this->assertIsArray($result);
        $this->assertEquals($statistics, $result);
    }
}
