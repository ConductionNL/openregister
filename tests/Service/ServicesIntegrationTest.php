<?php

/**
 * Integration tests for multiple OpenRegister services to increase PCOV coverage.
 *
 * Tests: SchemaService, SearchTrailService, OrganisationService, DashboardService,
 * EndpointService, MappingService, ExportService, AuthenticationService,
 * AuthorizationService, McpToolsService, RegisterService, SchemaCacheHandler,
 * FacetCacheHandler, PropertyValidatorHandler.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @group DB
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use DateTime;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\AuthenticationService;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\DashboardService;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for services to increase PCOV coverage
 *
 * @group DB
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ServicesIntegrationTest extends TestCase
{
    private IDBConnection $db;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private ObjectEntityMapper $objectEntityMapper;
    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;

    /**
     * UUIDs of created objects for cleanup
     *
     * @var string[]
     */
    private array $createdObjectUuids = [];

    /**
     * IDs of created registers for cleanup
     *
     * @var int[]
     */
    private array $createdRegisterIds = [];

    /**
     * IDs of created schemas for cleanup
     *
     * @var int[]
     */
    private array $createdSchemaIds = [];

    /**
     * IDs of created mappings for cleanup
     *
     * @var int[]
     */
    private array $createdMappingIds = [];

    /**
     * IDs of created search trails for cleanup
     *
     * @var int[]
     */
    private array $createdSearchTrailIds = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \OC::$server->get(IDBConnection::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
        $this->objectEntityMapper = \OC::$server->get(ObjectEntityMapper::class);

        $this->createTestRegisterAndSchema();
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up objects.
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_objects')
                    ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        // Clean up search trails.
        foreach ($this->createdSearchTrailIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_search_trails')
                    ->where($qb->expr()->eq(
                        'id',
                        $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        // Clean up mappings.
        foreach ($this->createdMappingIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_mappings')
                    ->where($qb->expr()->eq(
                        'id',
                        $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        // Clean up schemas.
        foreach ($this->createdSchemaIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq(
                        'id',
                        $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        // Clean up audit trails for our test register.
        if ($this->testRegister !== null) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_audit_trails')
                    ->where($qb->expr()->eq(
                        'register',
                        $qb->createNamedParameter(
                            $this->testRegister->getId(),
                            \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT
                        )
                    ))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        // Clean up registers.
        foreach ($this->createdRegisterIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq(
                        'id',
                        $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        // Clean up schema cache entries for test schemas.
        foreach ($this->createdSchemaIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_schema_cache')
                    ->where($qb->expr()->eq(
                        'schema_id',
                        $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore.
            }

            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_schema_facet_cache')
                    ->where($qb->expr()->eq(
                        'schema_id',
                        $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        parent::tearDown();
    }

    /**
     * Create test register and schema fixtures
     *
     * @return void
     */
    private function createTestRegisterAndSchema(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-svc-test-' . uniqid());
        $register->setDescription('Test register for services integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-svc-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);
        $this->createdRegisterIds[] = $this->testRegister->getId();

        $schema = new Schema();
        $schema->setTitle('phpunit-svc-schema-' . uniqid());
        $schema->setDescription('Test schema for services integration tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-svc-schema-' . uniqid());
        $schema->setProperties([
            'name' => ['type' => 'string', 'title' => 'Name'],
            'description' => ['type' => 'string', 'title' => 'Description'],
            'count' => ['type' => 'integer', 'title' => 'Count'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);
        $this->createdSchemaIds[] = $this->testSchema->getId();

        // Link schema to register.
        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Create a test object in the database
     *
     * @param array $data Object data
     *
     * @return ObjectEntity
     */
    private function createTestObject(array $data = []): ObjectEntity
    {
        $uuid = Uuid::v4()->toRfc4122();
        $objectData = array_merge([
            'name' => 'Test Object ' . uniqid(),
            'description' => 'A test object',
            'count' => 42,
        ], $data);

        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setRegister($this->testRegister->getId());
        $object->setSchema($this->testSchema->getId());
        $object->setObject($objectData);
        $object->setOwner('admin');
        $object->setOrganisation('default');

        $inserted = $this->objectEntityMapper->insert($object);
        $this->createdObjectUuids[] = $uuid;
        return $inserted;
    }

    // -------------------------------------------------------------------------
    // SchemaService tests
    // -------------------------------------------------------------------------

    /**
     * Test SchemaService::exploreSchemaProperties with real data
     *
     * @return void
     */
    public function testSchemaServiceExplorePropertiesEmpty(): void
    {
        $service = \OC::$server->get(SchemaService::class);
        $result = $service->exploreSchemaProperties($this->testSchema->getId());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('schema_id', $result);
        $this->assertArrayHasKey('schema_title', $result);
        $this->assertArrayHasKey('total_objects', $result);
        $this->assertEquals($this->testSchema->getId(), $result['schema_id']);
    }

    /**
     * Test SchemaService::exploreSchemaProperties with objects
     *
     * @return void
     */
    public function testSchemaServiceExplorePropertiesWithObjects(): void
    {
        $this->createTestObject(['name' => 'Test A', 'extra_field' => 'value']);
        $this->createTestObject(['name' => 'Test B', 'another_field' => 123]);

        $service = \OC::$server->get(SchemaService::class);
        $result = $service->exploreSchemaProperties($this->testSchema->getId());

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(2, $result['total_objects']);
        $this->assertArrayHasKey('discovered_properties', $result);
    }

    // -------------------------------------------------------------------------
    // SearchTrailService tests
    // -------------------------------------------------------------------------

    /**
     * Test SearchTrailService::createSearchTrail
     *
     * @return void
     */
    public function testSearchTrailServiceCreateTrail(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: [
                'register' => $this->testRegister->getId(),
                'schema' => $this->testSchema->getId(),
                '_search' => 'test query',
            ],
            resultCount: 5,
            totalResults: 25,
            responseTime: 42.5,
            executionType: 'sync'
        );

        $this->createdSearchTrailIds[] = $trail->getId();
        $this->assertNotNull($trail->getId());
        $this->assertEquals(5, $trail->getResultCount());
        $this->assertEquals(25, $trail->getTotalResults());
    }

    /**
     * Test SearchTrailService::getSearchTrails (paginated)
     *
     * @return void
     */
    public function testSearchTrailServiceGetTrails(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        // Create a trail first.
        $trail = $service->createSearchTrail(
            query: ['register' => $this->testRegister->getId(), 'schema' => $this->testSchema->getId()],
            resultCount: 3,
            totalResults: 10
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        $result = $service->getSearchTrails(['limit' => 5, 'page' => 1]);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
    }

    /**
     * Test SearchTrailService::getSearchTrail by ID
     *
     * @return void
     */
    public function testSearchTrailServiceGetTrailById(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: ['register' => $this->testRegister->getId(), 'schema' => $this->testSchema->getId()],
            resultCount: 1,
            totalResults: 1
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        $found = $service->getSearchTrail($trail->getId());
        $this->assertEquals($trail->getId(), $found->getId());
    }

    /**
     * Test SearchTrailService::getSearchStatistics
     *
     * @return void
     */
    public function testSearchTrailServiceStatistics(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: ['register' => $this->testRegister->getId(), 'schema' => $this->testSchema->getId()],
            resultCount: 5,
            totalResults: 10
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        $stats = $service->getSearchStatistics();

        $this->assertArrayHasKey('total_searches', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        $this->assertArrayHasKey('unique_search_terms', $stats);
        $this->assertArrayHasKey('period', $stats);
    }

    /**
     * Test SearchTrailService::getSearchStatistics with date range
     *
     * @return void
     */
    public function testSearchTrailServiceStatisticsWithDateRange(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: ['register' => $this->testRegister->getId(), 'schema' => $this->testSchema->getId()],
            resultCount: 5,
            totalResults: 10
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        $from = new DateTime('-7 days');
        $to = new DateTime('now');
        $stats = $service->getSearchStatistics($from, $to);

        $this->assertArrayHasKey('total_searches', $stats);
        $this->assertArrayHasKey('daily_averages', $stats);
        $this->assertArrayHasKey('period', $stats);
        $this->assertNotNull($stats['period']['days']);
    }

    /**
     * Test SearchTrailService::getPopularSearchTerms
     *
     * @return void
     */
    public function testSearchTrailServicePopularTerms(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: ['_search' => 'popular term', 'register' => $this->testRegister->getId()],
            resultCount: 10,
            totalResults: 50
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        $result = $service->getPopularSearchTerms(5);

        $this->assertArrayHasKey('terms', $result);
        $this->assertArrayHasKey('total_unique_terms', $result);
        $this->assertArrayHasKey('total_searches', $result);
        $this->assertArrayHasKey('period', $result);
    }

    /**
     * Test SearchTrailService::getSearchActivity
     *
     * @return void
     */
    public function testSearchTrailServiceActivity(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: ['register' => $this->testRegister->getId()],
            resultCount: 5,
            totalResults: 10
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        $result = $service->getSearchActivity('day');

        $this->assertArrayHasKey('activity', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertArrayHasKey('interval', $result);
        $this->assertEquals('day', $result['interval']);
    }

    /**
     * Test SearchTrailService::getRegisterSchemaStatistics
     *
     * @return void
     */
    public function testSearchTrailServiceRegisterSchemaStats(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: ['register' => $this->testRegister->getId(), 'schema' => $this->testSchema->getId()],
            resultCount: 3,
            totalResults: 7
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        $result = $service->getRegisterSchemaStatistics();

        $this->assertArrayHasKey('statistics', $result);
        $this->assertArrayHasKey('total_combinations', $result);
        $this->assertArrayHasKey('total_searches', $result);
    }

    /**
     * Test SearchTrailService::getUserAgentStatistics
     *
     * @return void
     */
    public function testSearchTrailServiceUserAgentStats(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: ['register' => $this->testRegister->getId()],
            resultCount: 5,
            totalResults: 10
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        $result = $service->getUserAgentStatistics(5);

        $this->assertArrayHasKey('user_agents', $result);
        $this->assertArrayHasKey('browser_distribution', $result);
        $this->assertArrayHasKey('total_user_agents', $result);
    }

    /**
     * Test SearchTrailService::cleanupSearchTrails
     *
     * @return void
     */
    public function testSearchTrailServiceCleanup(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $result = $service->cleanupSearchTrails();

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('deleted', $result);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test SearchTrailService::clearExpiredSearchTrails
     *
     * @return void
     */
    public function testSearchTrailServiceClearExpired(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $result = $service->clearExpiredSearchTrails();

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test SearchTrailService config processing with various params
     *
     * @return void
     */
    public function testSearchTrailServiceConfigProcessing(): void
    {
        $service = \OC::$server->get(SearchTrailService::class);

        $trail = $service->createSearchTrail(
            query: ['register' => $this->testRegister->getId()],
            resultCount: 1,
            totalResults: 1
        );
        $this->createdSearchTrailIds[] = $trail->getId();

        // Test with _limit, _offset, _page, _search, sort, order, from, to.
        $result = $service->getSearchTrails([
            '_limit' => 10,
            '_page' => 1,
            '_search' => 'test',
            'sort' => 'created',
            'order' => 'ASC',
            'from' => (new DateTime('-30 days'))->format('Y-m-d'),
            'to' => (new DateTime())->format('Y-m-d'),
        ]);

        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(10, $result['limit']);

        // Test with offset instead of page.
        $result2 = $service->getSearchTrails([
            'offset' => 0,
            'limit' => 5,
        ]);

        $this->assertArrayHasKey('results', $result2);
        $this->assertEquals(5, $result2['limit']);
    }

    // -------------------------------------------------------------------------
    // DashboardService tests
    // -------------------------------------------------------------------------

    /**
     * Test DashboardService::getRegistersWithSchemas
     *
     * @return void
     */
    public function testDashboardServiceGetRegistersWithSchemas(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getRegistersWithSchemas();

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(2, count($result));
        // At minimum 'totals' and 'orphaned'.
        $this->assertEquals('totals', $result[0]['id']);
        $this->assertEquals('System Totals', $result[0]['title']);
        $this->assertEquals('orphaned', $result[count($result) - 1]['id']);
    }

    /**
     * Test DashboardService::getRegistersWithSchemas with filter
     *
     * @return void
     */
    public function testDashboardServiceGetRegistersWithSchemasFiltered(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getRegistersWithSchemas(
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(2, count($result));
    }

    /**
     * Test DashboardService::recalculateSizes
     *
     * @return void
     */
    public function testDashboardServiceRecalculateSizes(): void
    {
        $this->createTestObject();
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->recalculateSizes(
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertGreaterThanOrEqual(1, $result['processed']);
    }

    /**
     * Test DashboardService::recalculateLogSizes
     *
     * @return void
     */
    public function testDashboardServiceRecalculateLogSizes(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->recalculateLogSizes(
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('failed', $result);
    }

    /**
     * Test DashboardService::recalculateAllSizes
     *
     * @return void
     */
    public function testDashboardServiceRecalculateAllSizes(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->recalculateAllSizes(
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('logs', $result);
        $this->assertArrayHasKey('total', $result);
    }

    /**
     * Test DashboardService::calculate with register only
     *
     * @return void
     */
    public function testDashboardServiceCalculate(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->calculate($this->testRegister->getId());

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('scope', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertNotNull($result['scope']['register']);
        $this->assertNull($result['scope']['schema']);
    }

    /**
     * Test DashboardService::recalculateSizes with no filter
     *
     * @return void
     */
    public function testDashboardServiceRecalculateSizesNoFilter(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        // Use register filter to avoid scanning all objects in the system.
        $result = $service->recalculateSizes($this->testRegister->getId());

        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('failed', $result);
    }

    /**
     * Test DashboardService::getAuditTrailActionChartData
     *
     * @return void
     */
    public function testDashboardServiceAuditTrailChartData(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getAuditTrailActionChartData(
            new DateTime('-7 days'),
            new DateTime('now'),
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('series', $result);
    }

    /**
     * Test DashboardService::getObjectsByRegisterChartData
     *
     * @return void
     */
    public function testDashboardServiceObjectsByRegisterChart(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getObjectsByRegisterChartData();

        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('series', $result);
    }

    /**
     * Test DashboardService::getObjectsBySchemaChartData
     *
     * @return void
     */
    public function testDashboardServiceObjectsBySchemaChart(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getObjectsBySchemaChartData();

        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('series', $result);
    }

    /**
     * Test DashboardService::getObjectsBySizeChartData
     *
     * @return void
     */
    public function testDashboardServiceObjectsBySizeChart(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getObjectsBySizeChartData();

        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('series', $result);
    }

    /**
     * Test DashboardService::getAuditTrailStatistics
     *
     * @return void
     */
    public function testDashboardServiceAuditTrailStatistics(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getAuditTrailStatistics(
            $this->testRegister->getId(),
            $this->testSchema->getId(),
            24
        );

        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('creates', $result);
        $this->assertArrayHasKey('updates', $result);
        $this->assertArrayHasKey('deletes', $result);
        $this->assertArrayHasKey('reads', $result);
    }

    /**
     * Test DashboardService::getAuditTrailActionDistribution
     *
     * @return void
     */
    public function testDashboardServiceAuditTrailActionDistribution(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getAuditTrailActionDistribution(
            $this->testRegister->getId(),
            $this->testSchema->getId(),
            24
        );

        $this->assertArrayHasKey('actions', $result);
    }

    /**
     * Test DashboardService::getMostActiveObjects
     *
     * @return void
     */
    public function testDashboardServiceMostActiveObjects(): void
    {
        $service = \OC::$server->get(DashboardService::class);

        $result = $service->getMostActiveObjects(
            $this->testRegister->getId(),
            $this->testSchema->getId(),
            5,
            24
        );

        $this->assertArrayHasKey('objects', $result);
    }

    // -------------------------------------------------------------------------
    // MappingService tests
    // -------------------------------------------------------------------------

    /**
     * Test MappingService::executeMapping simple mapping
     *
     * @return void
     */
    public function testMappingServiceSimpleMapping(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('test-mapping');
        $mapping->setMapping([
            'output_name' => 'input_name',
            'output_desc' => 'input_description',
        ]);
        $mapping->setUnset([]);
        $mapping->setCast([]);
        $mapping->setPassThrough(false);

        $input = [
            'input_name' => 'Test Name',
            'input_description' => 'Test Description',
        ];

        $result = $service->executeMapping($mapping, $input);

        $this->assertEquals('Test Name', $result['output_name']);
        $this->assertEquals('Test Description', $result['output_desc']);
    }

    /**
     * Test MappingService::executeMapping with passthrough
     *
     * @return void
     */
    public function testMappingServicePassThrough(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('passthrough-mapping');
        $mapping->setMapping([
            'new_field' => 'original',
        ]);
        $mapping->setUnset([]);
        $mapping->setCast([]);
        $mapping->setPassThrough(true);

        $input = [
            'original' => 'value',
            'keep_this' => 'should stay',
        ];

        $result = $service->executeMapping($mapping, $input);

        $this->assertEquals('value', $result['new_field']);
        $this->assertEquals('should stay', $result['keep_this']);
    }

    /**
     * Test MappingService::executeMapping with list mode
     *
     * @return void
     */
    public function testMappingServiceListMapping(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('list-mapping');
        $mapping->setMapping([
            'mapped_name' => 'name',
        ]);
        $mapping->setUnset([]);
        $mapping->setCast([]);
        $mapping->setPassThrough(false);

        $input = [
            ['name' => 'First'],
            ['name' => 'Second'],
        ];

        $result = $service->executeMapping($mapping, $input, true);

        $this->assertCount(2, $result);
        $this->assertEquals('First', $result[0]['mapped_name']);
        $this->assertEquals('Second', $result[1]['mapped_name']);
    }

    /**
     * Test MappingService::executeMapping with cast operations
     *
     * @return void
     */
    public function testMappingServiceCasting(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('cast-mapping');
        $mapping->setMapping([
            'int_val' => 'string_num',
            'bool_val' => 'bool_str',
            'float_val' => 'float_str',
            'string_val' => 'num',
        ]);
        $mapping->setUnset([]);
        $mapping->setCast([
            'int_val' => 'integer',
            'bool_val' => 'boolean',
            'float_val' => 'float',
            'string_val' => 'string',
        ]);
        $mapping->setPassThrough(false);

        $input = [
            'string_num' => '42',
            'bool_str' => 'true',
            'float_str' => '3.14',
            'num' => 100,
        ];

        $result = $service->executeMapping($mapping, $input);

        $this->assertSame(42, $result['int_val']);
        $this->assertSame(true, $result['bool_val']);
        $this->assertSame(3.14, $result['float_val']);
        $this->assertSame('100', $result['string_val']);
    }

    /**
     * Test MappingService cast operations: url, base64, json, html
     *
     * @return void
     */
    public function testMappingServiceAdvancedCasts(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('advanced-cast');
        $mapping->setMapping([
            'url_val' => 'raw',
            'b64_val' => 'raw',
            'json_val' => 'data',
            'html_val' => 'raw',
        ]);
        $mapping->setUnset([]);
        $mapping->setCast([
            'url_val' => 'url',
            'b64_val' => 'base64',
            'json_val' => 'json',
            'html_val' => 'html',
        ]);
        $mapping->setPassThrough(false);

        $input = [
            'raw' => 'hello world',
            'data' => ['key' => 'value'],
        ];

        $result = $service->executeMapping($mapping, $input);

        $this->assertEquals(urlencode('hello world'), $result['url_val']);
        $this->assertEquals(base64_encode('hello world'), $result['b64_val']);
        $this->assertEquals('{"key":"value"}', $result['json_val']);
        $this->assertEquals(htmlentities('hello world'), $result['html_val']);
    }

    /**
     * Test MappingService cast: nullable boolean
     *
     * @return void
     */
    public function testMappingServiceNullableBoolCast(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('nullable-bool');
        $mapping->setMapping([
            'null_bool' => 'empty_val',
            'true_bool' => 'yes_val',
        ]);
        $mapping->setUnset([]);
        $mapping->setCast([
            'null_bool' => '?boolean',
            'true_bool' => '?bool',
        ]);
        $mapping->setPassThrough(false);

        $input = ['empty_val' => '', 'yes_val' => 'yes'];
        $result = $service->executeMapping($mapping, $input);

        $this->assertNull($result['null_bool']);
        $this->assertTrue($result['true_bool']);
    }

    /**
     * Test MappingService::executeMapping with unset
     *
     * @return void
     */
    public function testMappingServiceUnset(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('unset-mapping');
        $mapping->setMapping([
            'keep' => 'a',
            'remove' => 'b',
        ]);
        $mapping->setUnset(['remove']);
        $mapping->setCast([]);
        $mapping->setPassThrough(false);

        $input = ['a' => 'kept', 'b' => 'removed'];
        $result = $service->executeMapping($mapping, $input);

        $this->assertEquals('kept', $result['keep']);
        $this->assertArrayNotHasKey('remove', $result);
    }

    /**
     * Test MappingService::encodeArrayKeys
     *
     * @return void
     */
    public function testMappingServiceEncodeArrayKeys(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $input = [
            'key.with.dots' => 'value',
            'nested' => ['inner.key' => 'inner_value'],
        ];

        $result = $service->encodeArrayKeys($input, '.', '_DOT_');

        $this->assertArrayHasKey('key_DOT_with_DOT_dots', $result);
        $this->assertArrayHasKey('inner_DOT_key', $result['nested']);
    }

    /**
     * Test MappingService::coordinateStringToArray
     *
     * @return void
     */
    public function testMappingServiceCoordinateStringToArray(): void
    {
        $service = \OC::$server->get(MappingService::class);

        // Single point.
        $result = $service->coordinateStringToArray('52.37 4.89');
        $this->assertEquals(['52.37', '4.89'], $result);

        // Multiple points.
        $result = $service->coordinateStringToArray('52.37 4.89 52.38 4.90');
        $this->assertCount(2, $result);
    }

    /**
     * Test MappingService::invalidateMappingCache
     *
     * @return void
     */
    public function testMappingServiceInvalidateCache(): void
    {
        $service = \OC::$server->get(MappingService::class);

        // This should not throw.
        $service->invalidateMappingCache(99999);
        $service->invalidateMappingCache('some-uuid');
        $this->assertTrue(true);
    }

    /**
     * Test MappingService::getMappings
     *
     * @return void
     */
    public function testMappingServiceGetMappings(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $result = $service->getMappings();
        $this->assertIsArray($result);
    }

    /**
     * Test MappingService cast: moneyStringToInt and intToMoneyString
     *
     * @return void
     */
    public function testMappingServiceMoneyCasts(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('money-cast');
        $mapping->setMapping([
            'cents' => 'money_str',
            'formatted' => 'cents_val',
        ]);
        $mapping->setUnset([]);
        $mapping->setCast([
            'cents' => 'moneyStringToInt',
            'formatted' => 'intToMoneyString',
        ]);
        $mapping->setPassThrough(false);

        $input = ['money_str' => '1.234,56', 'cents_val' => '12345'];
        $result = $service->executeMapping($mapping, $input);

        $this->assertSame(123456, $result['cents']);
        $this->assertIsString($result['formatted']);
    }

    /**
     * Test MappingService cast: nullStringToNull
     *
     * @return void
     */
    public function testMappingServiceNullStringToNull(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('null-str');
        $mapping->setMapping(['val' => 'input']);
        $mapping->setUnset([]);
        $mapping->setCast(['val' => 'nullStringToNull']);
        $mapping->setPassThrough(false);

        $result = $service->executeMapping($mapping, ['input' => 'null']);
        $this->assertNull($result['val']);

        $result2 = $service->executeMapping($mapping, ['input' => 'not null']);
        $this->assertEquals('not null', $result2['val']);
    }

    /**
     * Test MappingService cast: jsonToArray
     *
     * @return void
     */
    public function testMappingServiceJsonToArrayCast(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('json-to-array');
        $mapping->setMapping(['arr' => 'json_str']);
        $mapping->setUnset([]);
        $mapping->setCast(['arr' => 'jsonToArray']);
        $mapping->setPassThrough(false);

        $result = $service->executeMapping($mapping, ['json_str' => '{"a":1}']);
        $this->assertEquals(['a' => 1], $result['arr']);
    }

    /**
     * Test MappingService list mapping with extra values
     *
     * @return void
     */
    public function testMappingServiceListWithExtraValues(): void
    {
        $service = \OC::$server->get(MappingService::class);

        $mapping = new Mapping();
        $mapping->setName('list-extra');
        $mapping->setMapping([
            'name' => 'name',
        ]);
        $mapping->setUnset([]);
        $mapping->setCast([]);
        $mapping->setPassThrough(false);

        $input = [
            'listInput' => [
                ['name' => 'Item 1'],
                ['name' => 'Item 2'],
            ],
            'extraParam' => 'shared',
        ];

        $result = $service->executeMapping($mapping, $input, true);
        $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // AuthenticationService tests
    // -------------------------------------------------------------------------

    /**
     * Test AuthenticationService::fetchJWTToken with HS256
     *
     * @return void
     */
    public function testAuthenticationServiceJwtHs256(): void
    {
        $service = \OC::$server->get(AuthenticationService::class);

        $configuration = [
            'algorithm' => 'HS256',
            'secret' => 'my-super-secret-key-for-testing-must-be-at-least-32-bytes-long!!',
            'payload' => '{"sub": "1234567890", "iat": {{ "now"|date("U") }}, "exp": {{ ("now"|date("U")) + 3600 }}}',
        ];

        $token = $service->fetchJWTToken($configuration);

        $this->assertNotEmpty($token);
        // JWT has 3 parts separated by dots.
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    /**
     * Test AuthenticationService::fetchJWTToken with HS384
     *
     * @return void
     */
    public function testAuthenticationServiceJwtHs384(): void
    {
        $service = \OC::$server->get(AuthenticationService::class);

        $configuration = [
            'algorithm' => 'HS384',
            'secret' => 'another-secret-key-for-testing-384-must-be-at-least-48-bytes-long-here!!',
            'payload' => '{"sub": "test", "iat": {{ "now"|date("U") }}}',
        ];

        $token = $service->fetchJWTToken($configuration);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    /**
     * Test AuthenticationService::fetchJWTToken with HS512
     *
     * @return void
     */
    public function testAuthenticationServiceJwtHs512(): void
    {
        $service = \OC::$server->get(AuthenticationService::class);

        $configuration = [
            'algorithm' => 'HS512',
            'secret' => 'yet-another-secret-key-for-512-testing-must-be-at-least-64-bytes-long-padding-here!!!!!!!!!!!!!!!!!',
            'payload' => '{"sub": "512test", "iat": {{ "now"|date("U") }}}',
        ];

        $token = $service->fetchJWTToken($configuration);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    /**
     * Test AuthenticationService::fetchJWTToken missing params
     *
     * @return void
     */
    public function testAuthenticationServiceJwtMissingParams(): void
    {
        $service = \OC::$server->get(AuthenticationService::class);

        $this->expectException(\Symfony\Component\HttpFoundation\Exception\BadRequestException::class);
        $service->fetchJWTToken(['algorithm' => 'HS256']);
    }

    /**
     * Test AuthenticationService::fetchOAuthTokens missing grant_type
     *
     * @return void
     */
    public function testAuthenticationServiceOAuthMissingGrantType(): void
    {
        $service = \OC::$server->get(AuthenticationService::class);

        $this->expectException(\Symfony\Component\HttpFoundation\Exception\BadRequestException::class);
        $service->fetchOAuthTokens([]);
    }

    /**
     * Test AuthenticationService::fetchOAuthTokens missing tokenUrl
     *
     * @return void
     */
    public function testAuthenticationServiceOAuthMissingTokenUrl(): void
    {
        $service = \OC::$server->get(AuthenticationService::class);

        $this->expectException(\Symfony\Component\HttpFoundation\Exception\BadRequestException::class);
        $service->fetchOAuthTokens(['grant_type' => 'client_credentials']);
    }

    /**
     * Test AuthenticationService::fetchOAuthTokens unsupported grant type
     *
     * @return void
     */
    public function testAuthenticationServiceOAuthUnsupportedGrantType(): void
    {
        $service = \OC::$server->get(AuthenticationService::class);

        $this->expectException(\Symfony\Component\HttpFoundation\Exception\BadRequestException::class);
        $service->fetchOAuthTokens([
            'grant_type' => 'implicit',
            'tokenUrl' => 'http://localhost/token',
        ]);
    }

    // -------------------------------------------------------------------------
    // AuthorizationService tests
    // -------------------------------------------------------------------------

    /**
     * Test AuthorizationService::validatePayload with valid payload
     *
     * @return void
     */
    public function testAuthorizationServiceValidatePayload(): void
    {
        $service = \OC::$server->get(AuthorizationService::class);

        $now = time();
        $payload = [
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        // Should not throw.
        $service->validatePayload($payload);
        $this->assertTrue(true);
    }

    /**
     * Test AuthorizationService::validatePayload expired token
     *
     * @return void
     */
    public function testAuthorizationServiceValidatePayloadExpired(): void
    {
        $service = \OC::$server->get(AuthorizationService::class);

        $this->expectException(AuthenticationException::class);
        $service->validatePayload([
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]);
    }

    /**
     * Test AuthorizationService::validatePayload missing iat
     *
     * @return void
     */
    public function testAuthorizationServiceValidatePayloadMissingIat(): void
    {
        $service = \OC::$server->get(AuthorizationService::class);

        $this->expectException(AuthenticationException::class);
        $service->validatePayload(['exp' => time() + 3600]);
    }

    /**
     * Test AuthorizationService::validatePayload with default exp
     *
     * @return void
     */
    public function testAuthorizationServiceValidatePayloadDefaultExp(): void
    {
        $service = \OC::$server->get(AuthorizationService::class);

        // No exp, should default to iat + 1 hour.
        $payload = ['iat' => time()];
        $service->validatePayload($payload);
        $this->assertTrue(true);
    }

    /**
     * Test AuthorizationService::authorizeJwt with no token
     *
     * @return void
     */
    public function testAuthorizationServiceJwtNoToken(): void
    {
        $service = \OC::$server->get(AuthorizationService::class);

        $this->expectException(AuthenticationException::class);
        $service->authorizeJwt('Bearer ');
    }

    /**
     * Test AuthorizationService::authorizeOAuth with non-Bearer
     *
     * @return void
     */
    public function testAuthorizationServiceOAuthNonBearer(): void
    {
        $service = \OC::$server->get(AuthorizationService::class);

        $this->expectException(AuthenticationException::class);
        $service->authorizeOAuth('Basic dGVzdDp0ZXN0');
    }

    /**
     * Test AuthorizationService::authorizeApiKey with invalid key
     *
     * @return void
     */
    public function testAuthorizationServiceApiKeyInvalid(): void
    {
        $service = \OC::$server->get(AuthorizationService::class);

        $this->expectException(AuthenticationException::class);
        $service->authorizeApiKey('invalid-key', ['valid-key' => 'admin']);
    }

    // -------------------------------------------------------------------------
    // RegisterService tests
    // -------------------------------------------------------------------------

    /**
     * Test RegisterService::findAll
     *
     * @return void
     */
    public function testRegisterServiceFindAll(): void
    {
        $service = \OC::$server->get(RegisterService::class);
        $registers = $service->findAll();

        $this->assertIsArray($registers);
        $this->assertGreaterThanOrEqual(1, count($registers));
    }

    /**
     * Test RegisterService::find
     *
     * @return void
     */
    public function testRegisterServiceFind(): void
    {
        $service = \OC::$server->get(RegisterService::class);
        $register = $service->find($this->testRegister->getId());

        $this->assertEquals($this->testRegister->getId(), $register->getId());
        $this->assertEquals($this->testRegister->getTitle(), $register->getTitle());
    }

    /**
     * Test RegisterService::createFromArray and delete
     *
     * @return void
     */
    public function testRegisterServiceCreateAndDelete(): void
    {
        $service = \OC::$server->get(RegisterService::class);

        $data = [
            'title' => 'phpunit-create-test-' . uniqid(),
            'description' => 'Created via RegisterService test',
        ];

        $register = $service->createFromArray($data);
        $this->createdRegisterIds[] = $register->getId();

        $this->assertNotNull($register->getId());
        $this->assertEquals($data['title'], $register->getTitle());
        $this->assertNotEmpty($register->getUuid());

        // Delete.
        $deleted = $service->delete($register);
        $this->assertEquals($register->getId(), $deleted->getId());
    }

    /**
     * Test RegisterService::updateFromArray
     *
     * @return void
     */
    public function testRegisterServiceUpdate(): void
    {
        $service = \OC::$server->get(RegisterService::class);

        $newTitle = 'phpunit-updated-' . uniqid();
        $updated = $service->updateFromArray(
            $this->testRegister->getId(),
            ['title' => $newTitle]
        );

        $this->assertEquals($newTitle, $updated->getTitle());
    }

    /**
     * Test RegisterService::getSchemaObjectCounts
     *
     * @return void
     */
    public function testRegisterServiceSchemaObjectCounts(): void
    {
        $this->createTestObject();

        $service = \OC::$server->get(RegisterService::class);
        $schemas = $this->registerMapper->getSchemasByRegisterId($this->testRegister->getId());

        $counts = $service->getSchemaObjectCounts($this->testRegister->getId(), $schemas);

        $this->assertIsArray($counts);
    }

    // -------------------------------------------------------------------------
    // McpToolsService tests
    // -------------------------------------------------------------------------

    /**
     * Test McpToolsService::listTools
     *
     * @return void
     */
    public function testMcpToolsServiceListTools(): void
    {
        $service = \OC::$server->get(McpToolsService::class);
        $result = $service->listTools();

        $this->assertArrayHasKey('tools', $result);
        $this->assertCount(3, $result['tools']);

        $toolNames = array_column($result['tools'], 'name');
        $this->assertContains('registers', $toolNames);
        $this->assertContains('schemas', $toolNames);
        $this->assertContains('objects', $toolNames);
    }

    /**
     * Test McpToolsService::callTool for registers list
     *
     * @return void
     */
    public function testMcpToolsServiceListRegisters(): void
    {
        $service = \OC::$server->get(McpToolsService::class);
        $result = $service->callTool('registers', ['action' => 'list', 'limit' => 5]);

        $this->assertArrayHasKey('content', $result);
        $this->assertFalse($result['isError']);
    }

    /**
     * Test McpToolsService::callTool for schemas list
     *
     * @return void
     */
    public function testMcpToolsServiceListSchemas(): void
    {
        $service = \OC::$server->get(McpToolsService::class);
        $result = $service->callTool('schemas', ['action' => 'list']);

        $this->assertFalse($result['isError']);
    }

    /**
     * Test McpToolsService::callTool for registers get
     *
     * @return void
     */
    public function testMcpToolsServiceGetRegister(): void
    {
        $service = \OC::$server->get(McpToolsService::class);
        $result = $service->callTool('registers', [
            'action' => 'get',
            'id' => $this->testRegister->getId(),
        ]);

        $this->assertFalse($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertEquals($this->testRegister->getId(), $decoded['id']);
    }

    /**
     * Test McpToolsService::callTool for schemas get
     *
     * @return void
     */
    public function testMcpToolsServiceGetSchema(): void
    {
        $service = \OC::$server->get(McpToolsService::class);
        $result = $service->callTool('schemas', [
            'action' => 'get',
            'id' => $this->testSchema->getId(),
        ]);

        $this->assertFalse($result['isError']);
    }

    /**
     * Test McpToolsService::callTool for unknown tool
     *
     * @return void
     */
    public function testMcpToolsServiceUnknownTool(): void
    {
        $service = \OC::$server->get(McpToolsService::class);
        $result = $service->callTool('nonexistent', ['action' => 'list']);

        $this->assertTrue($result['isError']);
    }

    /**
     * Test McpToolsService::callTool register CRUD
     *
     * @return void
     */
    public function testMcpToolsServiceRegisterCrud(): void
    {
        $service = \OC::$server->get(McpToolsService::class);

        // Create.
        $createResult = $service->callTool('registers', [
            'action' => 'create',
            'data' => ['title' => 'mcp-test-' . uniqid(), 'description' => 'MCP test register'],
        ]);
        $this->assertFalse($createResult['isError']);
        $created = json_decode($createResult['content'][0]['text'], true);
        $this->createdRegisterIds[] = $created['id'];

        // Update.
        $updateResult = $service->callTool('registers', [
            'action' => 'update',
            'id' => $created['id'],
            'data' => ['title' => 'mcp-updated-' . uniqid()],
        ]);
        $this->assertFalse($updateResult['isError']);

        // Delete.
        $deleteResult = $service->callTool('registers', [
            'action' => 'delete',
            'id' => $created['id'],
        ]);
        $this->assertFalse($deleteResult['isError']);
    }

    /**
     * Test McpToolsService::callTool schema CRUD
     *
     * @return void
     */
    public function testMcpToolsServiceSchemaCrud(): void
    {
        $service = \OC::$server->get(McpToolsService::class);

        $createResult = $service->callTool('schemas', [
            'action' => 'create',
            'data' => ['title' => 'mcp-schema-' . uniqid()],
        ]);
        $this->assertFalse($createResult['isError']);
        $created = json_decode($createResult['content'][0]['text'], true);
        $this->createdSchemaIds[] = $created['id'];

        $updateResult = $service->callTool('schemas', [
            'action' => 'update',
            'id' => $created['id'],
            'data' => ['title' => 'mcp-schema-updated'],
        ]);
        $this->assertFalse($updateResult['isError']);

        $deleteResult = $service->callTool('schemas', [
            'action' => 'delete',
            'id' => $created['id'],
        ]);
        $this->assertFalse($deleteResult['isError']);
    }

    /**
     * Test McpToolsService::callTool objects missing params
     *
     * @return void
     */
    public function testMcpToolsServiceObjectsMissingParams(): void
    {
        $service = \OC::$server->get(McpToolsService::class);
        $result = $service->callTool('objects', ['action' => 'list']);

        $this->assertTrue($result['isError']);
    }

    /**
     * Test McpToolsService missing required param
     *
     * @return void
     */
    public function testMcpToolsServiceMissingRequiredParam(): void
    {
        $service = \OC::$server->get(McpToolsService::class);

        // Missing 'id' for get.
        $result = $service->callTool('registers', ['action' => 'get']);
        $this->assertTrue($result['isError']);
    }

    // -------------------------------------------------------------------------
    // PropertyValidatorHandler tests
    // -------------------------------------------------------------------------

    /**
     * Test PropertyValidatorHandler::validateProperty - valid string
     *
     * @return void
     */
    public function testPropertyValidatorValidString(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty(['type' => 'string']);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler::validateProperty - string with format
     *
     * @return void
     */
    public function testPropertyValidatorStringWithFormat(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty(['type' => 'string', 'format' => 'email']);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler::validateProperty - invalid format
     *
     * @return void
     */
    public function testPropertyValidatorInvalidFormat(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty(['type' => 'string', 'format' => 'nonexistent-format']);
    }

    /**
     * Test PropertyValidatorHandler::validateProperty - invalid type
     *
     * @return void
     */
    public function testPropertyValidatorInvalidType(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty(['type' => 'invalid_type']);
    }

    /**
     * Test PropertyValidatorHandler::validateProperty - missing type
     *
     * @return void
     */
    public function testPropertyValidatorMissingType(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty(['title' => 'no type']);
    }

    /**
     * Test PropertyValidatorHandler::validateProperty - number with min/max
     *
     * @return void
     */
    public function testPropertyValidatorNumberMinMax(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty([
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 100,
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler - min greater than max
     *
     * @return void
     */
    public function testPropertyValidatorMinGreaterThanMax(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty([
            'type' => 'integer',
            'minimum' => 100,
            'maximum' => 0,
        ]);
    }

    /**
     * Test PropertyValidatorHandler - non-numeric minimum
     *
     * @return void
     */
    public function testPropertyValidatorNonNumericMinimum(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty([
            'type' => 'number',
            'minimum' => 'abc',
        ]);
    }

    /**
     * Test PropertyValidatorHandler - enum validation
     *
     * @return void
     */
    public function testPropertyValidatorEnum(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty([
            'type' => 'string',
            'enum' => ['a', 'b', 'c'],
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler - empty enum fails
     *
     * @return void
     */
    public function testPropertyValidatorEmptyEnum(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty([
            'type' => 'string',
            'enum' => [],
        ]);
    }

    /**
     * Test PropertyValidatorHandler - array with items
     *
     * @return void
     */
    public function testPropertyValidatorArray(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty([
            'type' => 'array',
            'items' => ['type' => 'string'],
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler - object with nested properties
     *
     * @return void
     */
    public function testPropertyValidatorNestedObject(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler::validateProperties
     *
     * @return void
     */
    public function testPropertyValidatorValidateProperties(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperties([
            'name' => ['type' => 'string'],
            'count' => ['type' => 'integer', 'minimum' => 0],
            'active' => ['type' => 'boolean'],
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler - invalid property in properties
     *
     * @return void
     */
    public function testPropertyValidatorInvalidPropertyInBatch(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperties([
            'name' => 'not-an-object',
        ]);
    }

    /**
     * Test PropertyValidatorHandler - visible and hideOnCollection booleans
     *
     * @return void
     */
    public function testPropertyValidatorBooleanFlags(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty([
            'type' => 'string',
            'visible' => true,
            'hideOnCollection' => false,
            'hideOnForm' => true,
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler - visible non-boolean fails
     *
     * @return void
     */
    public function testPropertyValidatorVisibleNonBoolean(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty([
            'type' => 'string',
            'visible' => 'yes',
        ]);
    }

    /**
     * Test PropertyValidatorHandler - file type
     *
     * @return void
     */
    public function testPropertyValidatorFileType(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty([
            'type' => 'file',
            'allowedTypes' => ['image/png', 'image/jpeg'],
            'maxSize' => 1048576,
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler - file invalid MIME type
     *
     * @return void
     */
    public function testPropertyValidatorFileInvalidMime(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty([
            'type' => 'file',
            'allowedTypes' => ['not a mime'],
        ]);
    }

    /**
     * Test PropertyValidatorHandler - file maxSize too large
     *
     * @return void
     */
    public function testPropertyValidatorFileMaxSizeTooLarge(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty([
            'type' => 'file',
            'maxSize' => 999999999,
        ]);
    }

    /**
     * Test PropertyValidatorHandler - oneOf support
     *
     * @return void
     */
    public function testPropertyValidatorOneOf(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty([
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler - onDelete property
     *
     * @return void
     */
    public function testPropertyValidatorOnDelete(): void
    {
        $handler = new PropertyValidatorHandler();
        $result = $handler->validateProperty([
            'type' => 'string',
            'format' => 'uuid',
            '$ref' => 'https://example.com/schema',
            'onDelete' => 'CASCADE',
        ]);
        $this->assertTrue($result);
    }

    /**
     * Test PropertyValidatorHandler - onDelete on non-relation fails
     *
     * @return void
     */
    public function testPropertyValidatorOnDeleteNonRelation(): void
    {
        $handler = new PropertyValidatorHandler();
        $this->expectException(\Exception::class);
        $handler->validateProperty([
            'type' => 'string',
            'onDelete' => 'CASCADE',
        ]);
    }

    // -------------------------------------------------------------------------
    // SchemaCacheHandler tests
    // -------------------------------------------------------------------------

    /**
     * Test SchemaCacheHandler::invalidateForSchemaChange (graceful with missing table)
     *
     * @return void
     */
    public function testSchemaCacheHandlerInvalidate(): void
    {
        $handler = \OC::$server->get(SchemaCacheHandler::class);

        // invalidateForSchemaChange gracefully handles missing cache table.
        $handler->invalidateForSchemaChange($this->testSchema->getId(), 'update');
        $handler->invalidateForSchemaChange($this->testSchema->getId(), 'delete');
        $handler->invalidateForSchemaChange($this->testSchema->getId(), 'create');

        // Should not throw.
        $this->assertTrue(true);
    }

    /**
     * Test SchemaCacheHandler::clearSchemaCache (graceful with missing table)
     *
     * @return void
     */
    public function testSchemaCacheHandlerClearSchemaCache(): void
    {
        $handler = \OC::$server->get(SchemaCacheHandler::class);

        // clearSchemaCache gracefully handles missing table.
        $handler->clearSchemaCache($this->testSchema->getId());
        $handler->clearSchemaCache(999999);

        $this->assertTrue(true);
    }

    /**
     * Test SchemaCacheHandler is properly instantiated via DI
     *
     * @return void
     */
    public function testSchemaCacheHandlerInstantiation(): void
    {
        $handler = \OC::$server->get(SchemaCacheHandler::class);
        $this->assertInstanceOf(SchemaCacheHandler::class, $handler);
    }

    // -------------------------------------------------------------------------
    // FacetCacheHandler tests
    // -------------------------------------------------------------------------

    /**
     * Test FacetCacheHandler::invalidateForSchemaChange (graceful with missing table)
     *
     * @return void
     */
    public function testFacetCacheHandlerInvalidate(): void
    {
        $handler = \OC::$server->get(FacetCacheHandler::class);

        // invalidateForSchemaChange gracefully handles missing table.
        $handler->invalidateForSchemaChange($this->testSchema->getId(), 'update');
        $handler->invalidateForSchemaChange($this->testSchema->getId(), 'delete');
        $handler->invalidateForSchemaChange($this->testSchema->getId(), 'create');

        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // OrganisationService tests
    // -------------------------------------------------------------------------

    /**
     * Test OrganisationService::ensureDefaultOrganisation
     *
     * @return void
     */
    public function testOrganisationServiceEnsureDefault(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $org = $service->ensureDefaultOrganisation();
        $this->assertNotNull($org);
        $this->assertNotEmpty($org->getUuid());
    }

    /**
     * Test OrganisationService::getOrganisationSettingsOnly
     *
     * @return void
     */
    public function testOrganisationServiceGetSettings(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $settings = $service->getOrganisationSettingsOnly();
        $this->assertIsArray($settings);
        $this->assertArrayHasKey('organisation', $settings);
    }

    /**
     * Test OrganisationService::getDefaultOrganisationUuid
     *
     * @return void
     */
    public function testOrganisationServiceGetDefaultUuid(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $uuid = $service->getDefaultOrganisationUuid();
        // May be null or a valid UUID string.
        if ($uuid !== null) {
            $this->assertNotEmpty($uuid);
        } else {
            $this->assertNull($uuid);
        }
    }

    /**
     * Test OrganisationService::getUserOrganisations
     *
     * @return void
     */
    public function testOrganisationServiceGetUserOrganisations(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $orgs = $service->getUserOrganisations();
        $this->assertIsArray($orgs);
    }

    /**
     * Test OrganisationService::getActiveOrganisation
     *
     * @return void
     */
    public function testOrganisationServiceGetActiveOrganisation(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $org = $service->getActiveOrganisation();
        // May be null if no org is active.
        $this->assertTrue($org === null || $org instanceof \OCA\OpenRegister\Db\Organisation);
    }

    /**
     * Test OrganisationService::getUserOrganisationStats
     *
     * @return void
     */
    public function testOrganisationServiceUserStats(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $stats = $service->getUserOrganisationStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
    }

    /**
     * Test OrganisationService::getOrganisationForNewEntity
     *
     * @return void
     */
    public function testOrganisationServiceGetForNewEntity(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $result = $service->getOrganisationForNewEntity();
        // May be null or a UUID string.
        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test OrganisationService::clearCache
     *
     * @return void
     */
    public function testOrganisationServiceClearCache(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $result = $service->clearCache();
        $this->assertIsBool($result);
    }

    /**
     * Test OrganisationService::getUserActiveOrganisations
     *
     * @return void
     */
    public function testOrganisationServiceGetUserActiveOrganisations(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $orgs = $service->getUserActiveOrganisations();
        $this->assertIsArray($orgs);
    }

    /**
     * Test OrganisationService::hasAccessToOrganisation with invalid UUID
     *
     * @return void
     */
    public function testOrganisationServiceHasAccessInvalid(): void
    {
        $service = \OC::$server->get(OrganisationService::class);

        $result = $service->hasAccessToOrganisation('nonexistent-uuid');
        $this->assertIsBool($result);
    }
}
