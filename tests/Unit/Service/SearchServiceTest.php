<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SearchService;
use PHPUnit\Framework\TestCase;
use OCP\IURLGenerator;

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
    private IURLGenerator $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->urlGenerator = $this->createMock(IURLGenerator::class);

        // Create SearchService instance
        $this->searchService = new SearchService(
            $this->urlGenerator
        );
    }

    /**
     * Test mergeFacets method
     */
    public function testMergeFacets(): void
    {
        $existingAggregation = [
            ['_id' => 'facet1', 'count' => 5],
            ['_id' => 'facet2', 'count' => 3]
        ];
        
        $newAggregation = [
            ['_id' => 'facet1', 'count' => 2],
            ['_id' => 'facet3', 'count' => 4]
        ];

        $result = $this->searchService->mergeFacets($existingAggregation, $newAggregation);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        
        // Check that facet1 count is merged (5 + 2 = 7)
        $facet1 = array_filter($result, fn($item) => $item['_id'] === 'facet1');
        $this->assertCount(1, $facet1);
        $this->assertEquals(7, reset($facet1)['count']);
    }

    /**
     * Test sortResultArray method
     */
    public function testSortResultArray(): void
    {
        $a = ['_score' => 0.8, 'title' => 'A'];
        $b = ['_score' => 0.9, 'title' => 'B'];

        $result = $this->searchService->sortResultArray($a, $b);

        $this->assertIsInt($result);
        // Higher score should come first (descending order)
        $this->assertLessThan(0, $result);
    }

    /**
     * Test createMongoDBSearchFilter method
     */
    public function testCreateMongoDBSearchFilter(): void
    {
        $filters = [
            'title' => 'test',
            'status' => 'published',
            'date' => '2024-01-01'
        ];
        
        $fieldsToSearch = ['title', 'description'];

        $result = $this->searchService->createMongoDBSearchFilter($filters, $fieldsToSearch);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
    }

    /**
     * Test createMySQLSearchConditions method
     */
    public function testCreateMySQLSearchConditions(): void
    {
        $filters = [
            '_search' => 'test',
            'status' => 'published'
        ];
        
        $fieldsToSearch = ['title', 'description'];

        $result = $this->searchService->createMySQLSearchConditions($filters, $fieldsToSearch);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test unsetSpecialQueryParams method
     */
    public function testUnsetSpecialQueryParams(): void
    {
        $filters = [
            'title' => 'test',
            '.limit' => 10,
            '.page' => 1,
            '.sort' => 'title',
            '_search' => 'query'
        ];

        $result = $this->searchService->unsetSpecialQueryParams($filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('.limit', $result);
        $this->assertArrayHasKey('.page', $result);
        $this->assertArrayHasKey('.sort', $result);
        $this->assertArrayNotHasKey('_search', $result);
        $this->assertArrayHasKey('title', $result);
    }

    /**
     * Test createMySQLSearchParams method
     */
    public function testCreateMySQLSearchParams(): void
    {
        $filters = [
            'title' => 'test',
            'status' => 'published',
            '_search' => 'query'
        ];

        $result = $this->searchService->createMySQLSearchParams($filters);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('search', $result);
    }

    /**
     * Test createSortForMySQL method
     */
    public function testCreateSortForMySQL(): void
    {
        $filters = [
            'title' => 'test',
            '_order' => ['title' => 'ASC', 'status' => 'DESC']
        ];

        $result = $this->searchService->createSortForMySQL($filters);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * Test createSortForMongoDB method
     */
    public function testCreateSortForMongoDB(): void
    {
        $filters = [
            'title' => 'test',
            '_order' => ['title' => 'ASC', 'status' => 'DESC']
        ];

        $result = $this->searchService->createSortForMongoDB($filters);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * Test parseQueryString method
     */
    public function testParseQueryString(): void
    {
        $queryString = 'title=test&status=published';

        $result = $this->searchService->parseQueryString($queryString);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('test', $result['title']);
        $this->assertEquals('published', $result['status']);
    }

    /**
     * Test parseQueryString method with empty string
     */
    public function testParseQueryStringWithEmptyString(): void
    {
        $result = $this->searchService->parseQueryString('');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}