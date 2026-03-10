<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ObjectEntityMapper
 *
 * Tests construction of the ObjectEntityMapper facade and its delegation
 * to handlers. Most real logic lives in the handler classes; the mapper
 * itself is a thin facade. We verify construction wiring and table name.
 *
 * The mapper delegates to:
 * - QueryBuilderHandler (getQueryBuilder, getMaxAllowedPacketSize)
 * - StatisticsHandler (getStatistics, chart data)
 * - FacetsHandler (getSimpleFacets, getFacetableFieldsFromSchemas)
 * - BulkOperationsHandler (ultraFastBulkSave, deleteObjects, etc.)
 * - QueryOptimizationHandler (hasJsonFilters, separateLargeObjects, etc.)
 */
class ObjectEntityMapperTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private IEventDispatcher&MockObject $eventDispatcher;
    private IUserSession&MockObject $userSession;
    private SchemaMapper&MockObject $schemaMapper;
    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private OrganisationMapper&MockObject $organisationMapper;
    private ObjectEntityMapper $mapper;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);

        $this->mapper = new ObjectEntityMapper(
            $this->db,
            $this->eventDispatcher,
            $this->userSession,
            $this->schemaMapper,
            $this->groupManager,
            $this->userManager,
            $this->appConfig,
            $this->logger,
            $this->organisationMapper
        );
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(ObjectEntityMapper::class, $this->mapper);
    }

    public function testGetTableNameReturnsCorrectValue(): void
    {
        $this->assertStringContainsString('openregister_objects', $this->mapper->getTableName());
    }

    // -------------------------------------------------------------------------
    // hasJsonFilters — delegates to QueryOptimizationHandler but is pure logic
    // -------------------------------------------------------------------------

    public function testHasJsonFiltersReturnsTrueForDotNotation(): void
    {
        $filters = ['object.name' => 'test', 'status' => 'active'];
        $this->assertTrue($this->mapper->hasJsonFilters($filters));
    }

    public function testHasJsonFiltersReturnsFalseForSimpleFilters(): void
    {
        $filters = ['status' => 'active', 'register' => '1'];
        $this->assertFalse($this->mapper->hasJsonFilters($filters));
    }

    public function testHasJsonFiltersReturnsFalseForEmptyArray(): void
    {
        $this->assertFalse($this->mapper->hasJsonFilters([]));
    }

    public function testHasJsonFiltersIgnoresSchemaIdDotNotation(): void
    {
        // 'schema.id' is a special case that should NOT be treated as JSON filter
        $filters = ['schema.id' => '5'];
        $this->assertFalse($this->mapper->hasJsonFilters($filters));
    }

    public function testHasJsonFiltersDetectsNestedDotNotation(): void
    {
        $filters = ['metadata.author.name' => 'John'];
        $this->assertTrue($this->mapper->hasJsonFilters($filters));
    }
}
