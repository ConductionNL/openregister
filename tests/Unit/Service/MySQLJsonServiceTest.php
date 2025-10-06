<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\MySQLJsonService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Test class for MySQLJsonService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class MySQLJsonServiceTest extends TestCase
{
    private MySQLJsonService $mysqlJsonService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create MySQLJsonService instance (no constructor dependencies)
        $this->mysqlJsonService = new MySQLJsonService();
    }

    /**
     * Test orderJson method with empty order array
     */
    public function testOrderJsonWithEmptyOrderArray(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = [];

        $result = $this->mysqlJsonService->orderJson($builder, $order);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test orderJson method with single order field
     */
    public function testOrderJsonWithSingleOrderField(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = ['name' => 'ASC'];

        $result = $this->mysqlJsonService->orderJson($builder, $order);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test orderJson method with multiple order fields
     */
    public function testOrderJsonWithMultipleOrderFields(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = [
            'name' => 'ASC',
            'created' => 'DESC',
            'type' => 'ASC'
        ];

        $result = $this->mysqlJsonService->orderJson($builder, $order);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test orderJson method with different sort directions
     */
    public function testOrderJsonWithDifferentSortDirections(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = [
            'name' => 'asc',
            'created' => 'desc',
            'type' => 'ASC',
            'status' => 'DESC'
        ];

        $result = $this->mysqlJsonService->orderJson($builder, $order);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test orderInRoot method with empty order array
     */
    public function testOrderInRootWithEmptyOrderArray(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = [];

        $result = $this->mysqlJsonService->orderInRoot($builder, $order);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test orderInRoot method with single order field
     */
    public function testOrderInRootWithSingleOrderField(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = ['name' => 'ASC'];

        $result = $this->mysqlJsonService->orderInRoot($builder, $order);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test orderInRoot method with multiple order fields
     */
    public function testOrderInRootWithMultipleOrderFields(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = [
            'name' => 'ASC',
            'created' => 'DESC',
            'type' => 'ASC'
        ];

        $result = $this->mysqlJsonService->orderInRoot($builder, $order);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test searchJson method with null search term
     */
    public function testSearchJsonWithNullSearchTerm(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $search = null;

        $result = $this->mysqlJsonService->searchJson($builder, $search);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test searchJson method with empty search term
     */
    public function testSearchJsonWithEmptySearchTerm(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $search = '';

        $result = $this->mysqlJsonService->searchJson($builder, $search);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test searchJson method with valid search term
     */
    public function testSearchJsonWithValidSearchTerm(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $search = 'test search term';

        $result = $this->mysqlJsonService->searchJson($builder, $search);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test searchJson method with special characters
     */
    public function testSearchJsonWithSpecialCharacters(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $search = 'test@example.com & special chars!';

        $result = $this->mysqlJsonService->searchJson($builder, $search);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test searchJson method with numeric search term
     */
    public function testSearchJsonWithNumericSearchTerm(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $search = '12345';

        $result = $this->mysqlJsonService->searchJson($builder, $search);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test searchJson method with long search term
     */
    public function testSearchJsonWithLongSearchTerm(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $search = str_repeat('a', 1000);

        $result = $this->mysqlJsonService->searchJson($builder, $search);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test that MySQLJsonService implements IDatabaseJsonService interface
     */
    public function testImplementsIDatabaseJsonServiceInterface(): void
    {
        $this->assertInstanceOf(\OCA\OpenRegister\Service\IDatabaseJsonService::class, $this->mysqlJsonService);
    }

    /**
     * Test orderJson method with complex field names
     */
    public function testOrderJsonWithComplexFieldNames(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = [
            'user.name' => 'ASC',
            'user.profile.email' => 'DESC',
            'metadata.created_at' => 'ASC'
        ];

        $result = $this->mysqlJsonService->orderJson($builder, $order);

        $this->assertEquals($builder, $result);
    }

    /**
     * Test orderInRoot method with complex field names
     */
    public function testOrderInRootWithComplexFieldNames(): void
    {
        $builder = $this->createMock(IQueryBuilder::class);
        $order = [
            'user.name' => 'ASC',
            'user.profile.email' => 'DESC',
            'metadata.created_at' => 'ASC'
        ];

        $result = $this->mysqlJsonService->orderInRoot($builder, $order);

        $this->assertEquals($builder, $result);
    }
}
