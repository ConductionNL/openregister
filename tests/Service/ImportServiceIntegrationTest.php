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
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ImportService
 *
 * Tests import operations including CSV parsing, cache clearing,
 * and Solr warmup scheduling utilities.
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

        $this->createTestRegisterAndSchema();
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
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
     * Create test register and schema
     *
     * @return void
     */
    private function createTestRegisterAndSchema(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-test-import-' . uniqid());
        $register->setDescription('Test register for import tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-test-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-test-import-schema-' . uniqid());
        $schema->setDescription('Test schema for import tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-test-' . uniqid());
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
     * Test clearCaches does not throw
     *
     * @return void
     */
    public function testClearCaches(): void
    {
        $this->service->clearCaches();

        // Should not throw
        $this->assertTrue(true);
    }

    /**
     * Test getRecommendedWarmupMode with small import
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeSmall(): void
    {
        $result = $this->service->getRecommendedWarmupMode(10);

        $this->assertIsString($result);
    }

    /**
     * Test getRecommendedWarmupMode with large import
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeLarge(): void
    {
        $result = $this->service->getRecommendedWarmupMode(10000);

        $this->assertIsString($result);
    }

    /**
     * Test getRecommendedWarmupMode with zero imports
     *
     * @return void
     */
    public function testGetRecommendedWarmupModeZero(): void
    {
        $result = $this->service->getRecommendedWarmupMode(0);

        $this->assertIsString($result);
    }

    /**
     * Test importFromCsv with valid CSV
     *
     * @return void
     */
    public function testImportFromCsv(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $csv = "name,email,age\n";
        $csv .= "phpunit-test-1-" . uniqid() . ",test1@example.com,25\n";
        $csv .= "phpunit-test-2-" . uniqid() . ",test2@example.com,30\n";

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

        // Track created objects for cleanup
        foreach ($result as $sheetResult) {
            if (isset($sheetResult['created']) && is_array($sheetResult['created'])) {
                foreach ($sheetResult['created'] as $obj) {
                    if (isset($obj['uuid'])) {
                        $this->createdObjectUuids[] = $obj['uuid'];
                    }
                }
            }
        }
    }

    /**
     * Test importFromCsv with empty CSV (header only)
     *
     * @return void
     */
    public function testImportFromCsvEmpty(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

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
    }

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
     * Test scheduleSmartSolrWarmup with import summary
     *
     * @return void
     */
    public function testScheduleSmartSolrWarmupWithSummary(): void
    {
        $summary = [
            'Sheet1' => [
                'found'   => 10,
                'created' => [['uuid' => 'test1'], ['uuid' => 'test2']],
                'updated' => [],
                'errors'  => [],
            ],
        ];

        $result = $this->service->scheduleSmartSolrWarmup($summary);

        $this->assertIsBool($result);
    }

    /**
     * Test importFromCsv with nonexistent file throws
     *
     * @return void
     */
    public function testImportFromCsvNonexistentFile(): void
    {
        $this->expectException(\Exception::class);

        $this->service->importFromCsv(
            '/nonexistent/path/to/file.csv',
            $this->testRegister,
            $this->testSchema
        );
    }
}
