<?php

declare(strict_types=1);

/**
 * OpenRegister Facet Service Test
 *
 * This file contains tests for the FacetService in the OpenRegister application.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\FacetService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for FacetService
 *
 * This class tests the centralized faceting operations including
 * smart fallback strategies, response caching, and performance optimization.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class FacetServiceTest extends TestCase
{

    /** @var FacetService */
    private FacetService $facetService;

    /** @var MockObject|ObjectEntityMapper */
    private $objectEntityMapper;

    /** @var MockObject|SchemaMapper */
    private $schemaMapper;

    /** @var MockObject|RegisterMapper */
    private $registerMapper;

    /** @var MockObject|ICacheFactory */
    private $cacheFactory;

    /** @var MockObject|IUserSession */
    private $userSession;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Mock cache factory to return a mock cache
        $mockCache = $this->createMock(IMemcache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($mockCache);

        $this->facetService = new FacetService(
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->cacheFactory,
            $this->userSession,
            $this->logger
        );
    }

    /**
     * Test constructor
     *
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(FacetService::class, $this->facetService);
    }

    /**
     * Test getFacetsForQuery method with basic functionality
     *
     * @return void
     */
    public function testGetFacetsBasic(): void
    {
        $query = [
            '@self' => [
                'register' => 'test-register',
                'schema' => 'test-schema'
            ],
            'status' => 'active',
            '_facets' => ['category', 'status']
        ];

        // Mock the schema and register
        $mockSchema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $mockRegister = $this->createMock(\OCA\OpenRegister\Db\Register::class);

        $this->schemaMapper->method('find')->willReturn($mockSchema);
        $this->registerMapper->method('find')->willReturn($mockRegister);

        // Mock object entity mapper to return empty results
        $this->objectEntityMapper->method('getSimpleFacets')->willReturn([]);

        $result = $this->facetService->getFacetsForQuery($query);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('performance_metadata', $result);
    }

    /**
     * Test getFacetsForQuery method with cache hit
     *
     * @return void
     */
    public function testGetFacetsWithCacheHit(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $filters = ['status' => 'active'];
        $facetFields = ['category'];

        // Mock user session
        $mockUser = $this->createMock(\OCP\IUser::class);
        $mockUser->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($mockUser);

        // Mock object entity mapper to return facets
        $this->objectEntityMapper->method('getSimpleFacets')->willReturn([
            'category' => ['value1' => 5, 'value2' => 3]
        ]);

        $result = $this->facetService->getFacetsForQuery([
            '@self' => [
                'register' => $register,
                'schema' => $schema
            ],
            'status' => 'active',
            '_facets' => $facetFields
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('performance_metadata', $result);
        $this->assertArrayHasKey('category', $result['facets']);
        $this->assertEquals(['value1' => 5, 'value2' => 3], $result['facets']['category']);
    }

    /**
     * Test getFacetsForQuery method with empty results fallback
     *
     * @return void
     */
    public function testGetFacetsWithEmptyResultsFallback(): void
    {
        $query = [
            '@self' => [
                'register' => 'test-register',
                'schema' => 'test-schema'
            ],
            'status' => 'nonexistent',
            '_facets' => ['category']
        ];

        // Mock the schema and register
        $mockSchema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $mockRegister = $this->createMock(\OCA\OpenRegister\Db\Register::class);

        $this->schemaMapper->method('find')->willReturn($mockSchema);
        $this->registerMapper->method('find')->willReturn($mockRegister);

        // Mock object entity mapper to return empty results for filtered query
        $this->objectEntityMapper->method('getSimpleFacets')->willReturn([]);

        $result = $this->facetService->getFacetsForQuery($query);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('performance_metadata', $result);
    }

}
