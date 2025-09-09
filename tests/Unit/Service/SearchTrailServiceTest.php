<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SearchTrail;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SearchTrailService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class SearchTrailServiceTest extends TestCase
{
    private SearchTrailService $searchTrailService;
    private SearchTrailMapper $searchTrailMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->searchTrailMapper = $this->createMock(SearchTrailMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        // Create SearchTrailService instance
        $this->searchTrailService = new SearchTrailService(
            $this->searchTrailMapper,
            $this->registerMapper,
            $this->schemaMapper
        );
    }

    /**
     * Test createSearchTrail method with valid query
     */
    public function testCreateSearchTrailWithValidQuery(): void
    {
        $query = [
            'name' => 'test',
            'type' => 'object',
            'register' => 'test-register'
        ];

        // Create mock search trail
        $searchTrail = $this->createMock(SearchTrail::class);
        $searchTrail->id = 1;

        // Mock search trail mapper
        $this->searchTrailMapper->expects($this->once())
            ->method('createSearchTrail')
            ->willReturn($searchTrail);

        $result = $this->searchTrailService->createSearchTrail($query, 5, 10, 0.5, 'sync');

        $this->assertEquals($searchTrail, $result);
    }

    /**
     * Test createSearchTrail method with empty query
     */
    public function testCreateSearchTrailWithEmptyQuery(): void
    {
        $query = [];

        // Create mock search trail
        $searchTrail = $this->createMock(SearchTrail::class);
        $searchTrail->id = 1;

        // Mock search trail mapper
        $this->searchTrailMapper->expects($this->once())
            ->method('createSearchTrail')
            ->willReturn($searchTrail);

        $result = $this->searchTrailService->createSearchTrail($query, 5, 10, 0.5, 'sync');

        $this->assertEquals($searchTrail, $result);
    }

    /**
     * Test createSearchTrail method with system parameters
     */
    public function testCreateSearchTrailWithSystemParameters(): void
    {
        $query = [
            'name' => 'test',
            '_system_param' => 'should_be_ignored',
            '_another_system' => 'also_ignored',
            'type' => 'object'
        ];

        // Create mock search trail
        $searchTrail = $this->createMock(SearchTrail::class);
        $searchTrail->id = 1;

        // Mock search trail mapper
        $this->searchTrailMapper->expects($this->once())
            ->method('createSearchTrail')
            ->willReturn($searchTrail);

        $result = $this->searchTrailService->createSearchTrail($query, 5, 10, 0.5, 'sync');

        $this->assertEquals($searchTrail, $result);
    }

    /**
     * Test createSearchTrail method with complex query
     */
    public function testCreateSearchTrailWithComplexQuery(): void
    {
        $query = [
            'name' => 'test object',
            'type' => 'object',
            'register' => 'test-register',
            'schema' => 'test-schema',
            'filters' => [
                'status' => 'active',
                'category' => 'important'
            ],
            'sort' => 'name',
            'limit' => 10,
            'offset' => 0
        ];

        // Create mock search trail
        $searchTrail = $this->createMock(SearchTrail::class);
        $searchTrail->id = 1;

        // Mock search trail mapper
        $this->searchTrailMapper->expects($this->once())
            ->method('createSearchTrail')
            ->willReturn($searchTrail);

        $result = $this->searchTrailService->createSearchTrail($query, 5, 10, 0.5, 'sync');

        $this->assertEquals($searchTrail, $result);
    }

    /**
     * Test clearExpiredSearchTrails method
     */
    public function testClearExpiredSearchTrails(): void
    {
        // Mock search trail mapper
        $this->searchTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willReturn(true);

        $result = $this->searchTrailService->clearExpiredSearchTrails();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('deleted', $result);
        $this->assertArrayHasKey('cleanup_date', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test clearExpiredSearchTrails method with no expired trails
     */
    public function testClearExpiredSearchTrailsWithNoExpiredTrails(): void
    {
        // Mock search trail mapper to return false (no trails to delete)
        $this->searchTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willReturn(false);

        $result = $this->searchTrailService->clearExpiredSearchTrails();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('deleted', $result);
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['deleted']);
    }

    /**
     * Test clearExpiredSearchTrails method with exception
     */
    public function testClearExpiredSearchTrailsWithException(): void
    {
        // Mock search trail mapper to throw exception
        $this->searchTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willThrowException(new \Exception('Database error'));

        $result = $this->searchTrailService->clearExpiredSearchTrails();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('deleted', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertEquals(0, $result['deleted']);
    }

    /**
     * Test constructor with custom retention days
     */
    public function testConstructorWithCustomRetentionDays(): void
    {
        $retentionDays = 30;

        $service = new SearchTrailService(
            $this->searchTrailMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $retentionDays
        );

        $this->assertInstanceOf(SearchTrailService::class, $service);
    }

    /**
     * Test constructor with custom self-clearing setting
     */
    public function testConstructorWithCustomSelfClearing(): void
    {
        $retentionDays = 30;
        $selfClearing = true;

        $service = new SearchTrailService(
            $this->searchTrailMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $retentionDays,
            $selfClearing
        );

        $this->assertInstanceOf(SearchTrailService::class, $service);
    }

    /**
     * Test constructor with all custom parameters
     */
    public function testConstructorWithAllCustomParameters(): void
    {
        $retentionDays = 60;
        $selfClearing = false;

        $service = new SearchTrailService(
            $this->searchTrailMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $retentionDays,
            $selfClearing
        );

        $this->assertInstanceOf(SearchTrailService::class, $service);
    }
}
