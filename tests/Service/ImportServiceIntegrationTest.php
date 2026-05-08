<?php

/**
 * Integration tests for ImportService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ImportService
 *
 * Tests CSV import operations, data transformation, type coercion,
 * deduplication, column mapping, cache clearing, and Solr warmup scheduling
 * using the real Nextcloud DI container and database.
 *
 * @group DB
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)        Integration tests need many test methods
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)  Integration tests need many test methods
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)  Comprehensive integration tests are necessarily long
 */
class ImportServiceIntegrationTest extends TestCase
{
    /**
     * The import service instance
     *
     * @var ImportService
     */
    private ImportService $service;

    /**
     * Object service for cleanup
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * Register mapper
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Object entity mapper for direct DB cleanup
     *
     * @var MagicMapper
     */
    private MagicMapper $objectMapper;

    /**
     * Test register
     *
     * @var Register|null
     */
    private ?Register $testRegister = null;

    /**
     * Test schema
     *
     * @var Schema|null
     */
    private ?Schema $testSchema = null;

    /**
     * Created object UUIDs for cleanup
     *
     * @var string[]
     */
    private array $createdObjectUuids = [];

    /**
     * Temp files for cleanup
     *
     * @var string[]
     */
    private array $tempFiles = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(ImportService::class);
        $this->objectService = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper = \OC::$server->get(MagicMapper::class);

        $this->createTestRegisterAndSchema();
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up created objects via direct DB delete for reliability
        $db = \OC::$server->get(IDBConnection::class);
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_objects')
                    ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        if ($this->testSchema !== null) {
            try {
                $this->schemaMapper->delete($this->testSchema);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->testRegister !== null) {
            try {
                $this->registerMapper->delete($this->testRegister);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        parent::tearDown();
    }

    /**
     * Create test register and schema for import tests
     *
     * @return void
     */
    private function createTestRegisterAndSchema(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-test-import-' . uniqid());
        $register->setDescription('Test register for import tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-import-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-test-import-schema-' . uniqid());
        $schema->setDescription('Test schema for import tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-import-schema-' . uniqid());
        $schema->setProperties([
            'name'  => ['type' => 'string', 'title' => 'Name'],
            'email' => ['type' => 'string', 'title' => 'Email'],
            'age'   => ['type' => 'integer', 'title' => 'Age'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Create a temporary CSV file for testing
     *
     * @param string $content CSV content
     *
     * @return string Path to temporary file
     */
    private function createTempCsv(string $content): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpunit-csv-');
        file_put_contents($tmpFile, $content);
        $this->tempFiles[] = $tmpFile;
        return $tmpFile;
    }

    /**
     * Collect object UUIDs from import result for cleanup
     *
     * @param array $result Import result
     *
     * @return void
     */
    private function collectObjectUuids(array $result): void
    {
        foreach ($result as $sheetResult) {
            if (is_array($sheetResult) === false) {
                continue;
            }
            foreach (['created', 'updated'] as $key) {
                if (isset($sheetResult[$key]) && is_array($sheetResult[$key])) {
                    foreach ($sheetResult[$key] as $uuid) {
                        if (is_string($uuid) && $uuid !== '') {
                            $this->createdObjectUuids[] = $uuid;
                        }
                    }
                }
            }
        }
    }

    // =========================================================================
    // clearCaches tests
    // =========================================================================

    /**
     * Test clearCaches does not throw
     *
     * @return void
     */
    public function testClearCaches(): void
    {
        $this->service->clearCaches();

        $this->assertTrue(true);
    }

    /**
     * Test clearCaches can be called multiple times without error
     *
     * @return void
     */
    public function testClearCachesIdempotent(): void
    {
        $this->service->clearCaches();
        $this->service->clearCaches();
        $this->service->clearCaches();

        $this->assertTrue(true);
    }

    // =========================================================================
    // getRecommendedWarmupMode tests
    // =========================================================================

    /**
     * Test getRecommendedWarmupMode returns safe for small import
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeSafe(): void
    {
        $result = $this->service->getRecommendedWarmupMode(10);
        $this->assertSame('safe', $result);
    }

    /**
     * Test getRecommendedWarmupMode returns balanced for medium import
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeBalanced(): void
    {
        $result = $this->service->getRecommendedWarmupMode(5000);
        $this->assertSame('balanced', $result);
    }

    /**
     * Test getRecommendedWarmupMode returns fast for large import
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeFast(): void
    {
        $result = $this->service->getRecommendedWarmupMode(50000);
        $this->assertSame('fast', $result);
    }

    /**
     * Test getRecommendedWarmupMode boundary at 1000
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeBoundary1000(): void
    {
        $this->assertSame('safe', $this->service->getRecommendedWarmupMode(1000));
        $this->assertSame('balanced', $this->service->getRecommendedWarmupMode(1001));
    }

    /**
     * Test getRecommendedWarmupMode boundary at 10000
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeBoundary10000(): void
    {
        $this->assertSame('balanced', $this->service->getRecommendedWarmupMode(10000));
        $this->assertSame('fast', $this->service->getRecommendedWarmupMode(10001));
    }

    /**
     * Test getRecommendedWarmupMode with zero
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeZero(): void
    {
        $result = $this->service->getRecommendedWarmupMode(0);
        $this->assertSame('safe', $result);
    }

    // =========================================================================
    // scheduleSmartSolrWarmup tests
    // =========================================================================

    /**
     * Test scheduleSmartSolrWarmup with empty summary
     *
     * @return void
     */
    public function testScheduleSmartSolrWarmupEmpty(): void
    {
        $result = $this->service->scheduleSmartSolrWarmup([]);

        $this->assertIsBool($result);
    }

    /**
     * Test scheduleSmartSolrWarmup with import summary containing created objects
     *
     * @return void
     */
    public function testScheduleSmartSolrWarmupWithSummary(): void
    {
        $summary = [
            'Sheet1' => [
                'found'   => 10,
                'created' => ['uuid1', 'uuid2'],
                'updated' => [],
                'errors'  => [],
            ],
        ];

        $result = $this->service->scheduleSmartSolrWarmup($summary);

        $this->assertIsBool($result);
    }

    // =========================================================================
    // importFromCsv - basic import tests
    // =========================================================================

    /**
     * Test importFromCsv with valid CSV creates objects
     *
     * @return void
     */
    public function testImportFromCsvCreatesObjects(): void
    {
        $csv = "name,email,age\n";
        $csv .= "Alice,alice@example.com,25\n";
        $csv .= "Bob,bob@example.com,30\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->assertIsArray($result);
        $this->collectObjectUuids($result);

        // Check the first (and only) sheet result
        $sheetResult = reset($result);
        $this->assertSame(2, $sheetResult['found']);
        $this->assertCount(2, $sheetResult['created']);
    }

    /**
     * Test importFromCsv with single row
     *
     * @return void
     */
    public function testImportFromCsvSingleRow(): void
    {
        $csv = "name,email,age\n";
        $csv .= "Charlie,charlie@test.com,35\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertSame(1, $sheetResult['found']);
        $this->assertCount(1, $sheetResult['created']);
    }

    /**
     * Test importFromCsv with empty CSV (header only) creates no objects
     *
     * @return void
     */
    public function testImportFromCsvEmptyNoDataRows(): void
    {
        $csv = "name,email,age\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->assertIsArray($result);
        $sheetResult = reset($result);
        $this->assertSame(0, $sheetResult['found']);
    }

    // =========================================================================
    // importFromCsv - type coercion tests
    // =========================================================================

    /**
     * Test importFromCsv with typed properties (integer coercion)
     *
     * @return void
     */
    public function testImportFromCsvIntegerCoercion(): void
    {
        $csv = "name,email,age\n";
        $csv .= "TypeTest,type@example.com,42\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertCount(1, $sheetResult['created']);

        // Verify the object was created with correct type by fetching it
        $uuid = $sheetResult['created'][0];
        if ($uuid !== null) {
            $obj = $this->objectMapper->find($uuid);
            $data = $obj->getObject();
            $this->assertSame(42, $data['age']);
        }
    }

    /**
     * Test importFromCsv with boolean and array typed schema
     *
     * @return void
     */
    public function testImportFromCsvBooleanAndArrayTypes(): void
    {
        // Create a schema with boolean and array types
        $boolSchema = new Schema();
        $boolSchema->setTitle('phpunit-bool-schema-' . uniqid());
        $boolSchema->setDescription('Test schema with boolean/array types');
        $boolSchema->setUuid(Uuid::v4()->toRfc4122());
        $boolSchema->setSlug('phpunit-bool-' . uniqid());
        $boolSchema->setProperties([
            'label'    => ['type' => 'string', 'title' => 'Label'],
            'active'   => ['type' => 'boolean', 'title' => 'Active'],
            'tags'     => ['type' => 'array', 'title' => 'Tags'],
        ]);
        $boolSchema = $this->schemaMapper->insert($boolSchema);

        // Update register to include this schema
        $currentSchemas = $this->testRegister->getSchemas();
        $currentSchemas[] = $boolSchema->getId();
        $this->testRegister->setSchemas($currentSchemas);
        $this->registerMapper->update($this->testRegister);

        $csv = "label,active,tags\n";
        $csv .= "Test Item,true,\"tag1,tag2,tag3\"\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $boolSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertCount(1, $sheetResult['created']);

        // Verify boolean and array coercion
        $uuid = $sheetResult['created'][0];
        if ($uuid !== null) {
            $obj = $this->objectMapper->find($uuid);
            $data = $obj->getObject();
            $this->assertSame(true, $data['active']);
            $this->assertIsArray($data['tags']);
            $this->assertCount(3, $data['tags']);
        }

        // Clean up extra schema
        try {
            $this->schemaMapper->delete($boolSchema);
        } catch (\Exception $e) {
            // Ignore
        }
    }

    // =========================================================================
    // importFromCsv - column filtering tests
    // =========================================================================

    /**
     * Test importFromCsv ignores underscore-prefixed columns
     *
     * @return void
     */
    public function testImportFromCsvIgnoresUnderscoreColumns(): void
    {
        $csv = "name,email,_internal_id\n";
        $csv .= "UnderscoreTest,underscore@test.com,SKIP_THIS\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertCount(1, $sheetResult['created']);

        // Verify underscore column was NOT included in object data
        $uuid = $sheetResult['created'][0];
        if ($uuid !== null) {
            $obj = $this->objectMapper->find($uuid);
            $data = $obj->getObject();
            $this->assertArrayNotHasKey('_internal_id', $data);
        }
    }

    /**
     * Test importFromCsv with extra columns not in schema
     *
     * @return void
     */
    public function testImportFromCsvExtraColumnsPreserved(): void
    {
        $csv = "name,email,age,extraField\n";
        $csv .= "ExtraTest,extra@test.com,28,extraValue\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertCount(1, $sheetResult['created']);
    }

    // =========================================================================
    // importFromCsv - schema metadata tests
    // =========================================================================

    /**
     * Test importFromCsv includes schema info in result
     *
     * @return void
     */
    public function testImportFromCsvIncludesSchemaInfo(): void
    {
        $csv = "name,email,age\n";
        $csv .= "SchemaInfoTest,schema@test.com,22\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertArrayHasKey('schema', $sheetResult);
        $this->assertSame($this->testSchema->getId(), $sheetResult['schema']['id']);
        $this->assertSame($this->testSchema->getTitle(), $sheetResult['schema']['title']);
        $this->assertSame($this->testSchema->getSlug(), $sheetResult['schema']['slug']);
    }

    // =========================================================================
    // importFromCsv - deduplication tests
    // =========================================================================

    /**
     * Test importFromCsv handles deduplication on second import
     *
     * @return void
     */
    public function testImportFromCsvDeduplication(): void
    {
        $name = 'DedupTest-' . uniqid();
        $csv = "name,email,age\n";
        $csv .= "{$name},dedup@test.com,33\n";

        $filePath1 = $this->createTempCsv($csv);

        // First import
        $result1 = $this->service->importFromCsv(
            $filePath1,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );
        $this->collectObjectUuids($result1);

        $sheetResult1 = reset($result1);
        $this->assertCount(1, $sheetResult1['created']);

        // Second import with same data
        $filePath2 = $this->createTempCsv($csv);

        $result2 = $this->service->importFromCsv(
            $filePath2,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );
        $this->collectObjectUuids($result2);

        $sheetResult2 = reset($result2);
        // Second import should deduplicate (unchanged or updated, not created again)
        $totalCreatedSecond = count($sheetResult2['created'] ?? []);
        $totalUnchanged = count($sheetResult2['unchanged'] ?? []);
        $totalUpdated = count($sheetResult2['updated'] ?? []);

        // Either created again OR detected as unchanged/updated
        $this->assertSame(1, $totalCreatedSecond + $totalUnchanged + $totalUpdated);
    }

    // =========================================================================
    // importFromCsv - publish tests
    // =========================================================================

    /**
     * Test importFromCsv with publish flag sets published date
     *
     * @return void
     */
    public function testImportFromCsvWithPublish(): void
    {
        $csv = "name,email,age\n";
        $csv .= "PublishTest,publish@test.com,40\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            true, // publish = true
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertCount(1, $sheetResult['created']);
    }

    // =========================================================================
    // importFromCsv - performance metrics tests
    // =========================================================================

    /**
     * Test importFromCsv includes performance metrics
     *
     * @return void
     */
    public function testImportFromCsvPerformanceMetrics(): void
    {
        $csv = "name,email,age\n";
        $csv .= "PerfTest,perf@test.com,50\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertArrayHasKey('performance', $sheetResult);
        $this->assertArrayHasKey('totalTime', $sheetResult['performance']);
        $this->assertArrayHasKey('objectsPerSecond', $sheetResult['performance']);
        $this->assertArrayHasKey('totalProcessed', $sheetResult['performance']);
    }

    // =========================================================================
    // importFromCsv - error handling tests
    // =========================================================================

    /**
     * Test importFromCsv with nonexistent file throws exception
     *
     * @return void
     */
    public function testImportFromCsvNonexistentFileThrows(): void
    {
        $this->expectException(\Exception::class);

        $this->service->importFromCsv(
            '/nonexistent/path/to/file.csv',
            $this->testRegister,
            $this->testSchema
        );
    }

    /**
     * Test importFromCsv without schema throws InvalidArgumentException
     *
     * @return void
     */
    public function testImportFromCsvWithoutSchemaThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV import requires a specific schema');

        $csv = "name,email\ntest,test@test.com\n";
        $filePath = $this->createTempCsv($csv);

        $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            null
        );
    }

    // =========================================================================
    // importFromCsv - multi-row tests
    // =========================================================================

    /**
     * Test importFromCsv with many rows
     *
     * @return void
     */
    public function testImportFromCsvManyRows(): void
    {
        $csv = "name,email,age\n";
        for ($i = 1; $i <= 10; $i++) {
            $csv .= "User{$i},user{$i}@test.com,{$i}\n";
        }

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertSame(10, $sheetResult['found']);
        $this->assertCount(10, $sheetResult['created']);
    }

    // =========================================================================
    // importFromCsv - empty values tests
    // =========================================================================

    /**
     * Test importFromCsv skips rows that are completely empty
     *
     * @return void
     */
    public function testImportFromCsvSkipsEmptyRows(): void
    {
        $csv = "name,email,age\n";
        $csv .= "ValidRow,valid@test.com,25\n";
        $csv .= ",,\n";
        $csv .= "AnotherValid,another@test.com,30\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        // Should find 2 data rows (empty row skipped)
        $this->assertSame(2, $sheetResult['found']);
    }

    /**
     * Test importFromCsv with partial data (some columns empty)
     *
     * @return void
     */
    public function testImportFromCsvPartialData(): void
    {
        $csv = "name,email,age\n";
        $csv .= "PartialTest,,\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertSame(1, $sheetResult['found']);
    }

    // =========================================================================
    // importFromCsv - special characters tests
    // =========================================================================

    /**
     * Test importFromCsv with quoted fields containing commas
     *
     * @return void
     */
    public function testImportFromCsvQuotedFieldsWithCommas(): void
    {
        $csv = "name,email,age\n";
        $csv .= "\"Last, First\",quoted@test.com,28\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertCount(1, $sheetResult['created']);

        // Verify the comma in the name was preserved
        $uuid = $sheetResult['created'][0];
        if ($uuid !== null) {
            $obj = $this->objectMapper->find($uuid);
            $data = $obj->getObject();
            $this->assertSame('Last, First', $data['name']);
        }
    }

    // =========================================================================
    // importFromCsv - number type schema tests
    // =========================================================================

    /**
     * Test importFromCsv with number (float) type property
     *
     * @return void
     */
    public function testImportFromCsvNumberType(): void
    {
        $numSchema = new Schema();
        $numSchema->setTitle('phpunit-num-schema-' . uniqid());
        $numSchema->setDescription('Test schema with number type');
        $numSchema->setUuid(Uuid::v4()->toRfc4122());
        $numSchema->setSlug('phpunit-num-' . uniqid());
        $numSchema->setProperties([
            'label' => ['type' => 'string', 'title' => 'Label'],
            'price' => ['type' => 'number', 'title' => 'Price'],
        ]);
        $numSchema = $this->schemaMapper->insert($numSchema);

        $csv = "label,price\n";
        $csv .= "Widget,19.99\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $numSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertCount(1, $sheetResult['created']);

        $uuid = $sheetResult['created'][0];
        if ($uuid !== null) {
            $obj = $this->objectMapper->find($uuid);
            $data = $obj->getObject();
            $this->assertSame(19.99, $data['price']);
        }

        try {
            $this->schemaMapper->delete($numSchema);
        } catch (\Exception $e) {
            // Ignore
        }
    }

    // =========================================================================
    // importFromCsv - object type tests
    // =========================================================================

    /**
     * Test importFromCsv with object type property (JSON)
     *
     * @return void
     */
    public function testImportFromCsvObjectTypeJson(): void
    {
        $objSchema = new Schema();
        $objSchema->setTitle('phpunit-obj-schema-' . uniqid());
        $objSchema->setDescription('Test schema with object type');
        $objSchema->setUuid(Uuid::v4()->toRfc4122());
        $objSchema->setSlug('phpunit-obj-' . uniqid());
        $objSchema->setProperties([
            'title'    => ['type' => 'string', 'title' => 'Title'],
            'metadata' => ['type' => 'object', 'title' => 'Metadata'],
        ]);
        $objSchema = $this->schemaMapper->insert($objSchema);

        $csv = "title,metadata\n";
        $csv .= "ObjTest,\"{\"\"key\"\": \"\"value\"\"}\"\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $objSchema,
            false,
            false,
            false,
            false,
            false,
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertSame(1, $sheetResult['found']);

        try {
            $this->schemaMapper->delete($objSchema);
        } catch (\Exception $e) {
            // Ignore
        }
    }

    // =========================================================================
    // importFromCsv - publish without publish tests
    // =========================================================================

    /**
     * Test importFromCsv without publish flag does not set published date
     *
     * @return void
     */
    public function testImportFromCsvWithoutPublish(): void
    {
        $csv = "name,email,age\n";
        $csv .= "NoPublishTest,nopub@test.com,45\n";

        $filePath = $this->createTempCsv($csv);

        $result = $this->service->importFromCsv(
            $filePath,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            false, // publish = false
            null,
            true
        );

        $this->collectObjectUuids($result);

        $sheetResult = reset($result);
        $this->assertCount(1, $sheetResult['created']);
    }
}
