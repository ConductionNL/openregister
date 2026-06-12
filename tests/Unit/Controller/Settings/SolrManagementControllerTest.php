<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\SolrManagementController;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stub for IndexService methods used by SolrManagementController.
 */
class SolrManagementIndexServiceStub
{
    public function isAvailable(bool $forceRefresh = false): bool { return false; }
    public function getObjectCollectionFieldStatus(): array { return []; }
    public function getFileCollectionFieldStatus(): array { return []; }
    public function listCollections(): array { return []; }
    public function listConfigSets(): array { return []; }
    public function createConfigSet(string $name, string $baseConfigSet = '_default'): array { return []; }
    public function deleteConfigSet(string $name): array { return []; }
    public function createCollection(string $collectionName, string $configName, int $numShards = 1, int $replicationFactor = 1, int $maxShardsPerNode = 1): array { return []; }
    public function copyCollection(string $sourceCollection, string $targetCollection, bool $copyData = false): array { return []; }
    public function createMissingFields(string $collectionType, array $missingFields, bool $dryRun = false): array { return []; }
    public function getFieldsConfiguration(): array { return []; }
    public function fixMismatchedFields(array $fields, bool $dryRun = false): array { return []; }
    public function deleteField(string $fieldName): array { return []; }
    public function deleteCollection(?string $collectionName = null): array { return []; }
}

class SolrManagementControllerTest extends TestCase
{
    private SolrManagementController $controller;
    private IRequest&MockObject $request;
    private IDBConnection&MockObject $db;
    private ContainerInterface&MockObject $container;
    private SettingsService&MockObject $settingsService;
    private IndexService&MockObject $indexService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new SolrManagementController(
            'openregister',
            $this->request,
            $this->db,
            $this->container,
            $this->settingsService,
            $this->indexService,
            $this->logger
        );
    }

    /**
     * Create a mock index service stub and configure container to return it.
     */
    private function mockIndexService(): MockObject
    {
        $mockService = $this->createMock(SolrManagementIndexServiceStub::class);
        $this->container->method('get')
            ->willReturn($mockService);
        return $mockService;
    }

    /**
     * Create mock index service and configure container to return different mocks
     * for different class names (needed for fixMismatchedSolrFields which also gets SchemaMapper).
     *
     * @param MockObject $indexMock The index service mock
     * @param mixed      $extras   Map of class name => mock to return
     */
    private function mockContainerWithMultiple(MockObject $indexMock, array $extras = []): void
    {
        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($indexMock, $extras) {
                if (isset($extras[$class])) {
                    return $extras[$class];
                }
                return $indexMock;
            });
    }

    /**
     * Create a mock IndexService (real class) and SchemaMapper for fixMismatched tests,
     * configuring the container to return the right mock for each class.
     *
     * @return MockObject&IndexService The IndexService mock
     */
    private function mockFixMismatchedDeps(): MockObject
    {
        $indexMock = $this->createMock(IndexService::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);

        $this->mockContainerWithMultiple($indexMock, [
            \OCA\OpenRegister\Db\SchemaMapper::class => $schemaMapper,
        ]);

        return $indexMock;
    }

    // ========================================================================
    // getSolrFields tests
    // ========================================================================

    public function testGetSolrFieldsSolrUnavailable(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->getSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertEquals('SOLR is not available or not configured', $result->getData()['message']);
    }

    public function testGetSolrFieldsSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [],
            'extra' => [],
        ]);
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [],
            'extra' => [],
        ]);

        $result = $this->controller->getSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $data = $result->getData();
        $this->assertArrayHasKey('comparison', $data);
        $this->assertEquals(0, $data['comparison']['total_differences']);
        $this->assertEquals(0, $data['comparison']['missing_count']);
        $this->assertEquals(0, $data['comparison']['extra_count']);
    }

    public function testGetSolrFieldsWithMissingAndExtraFields(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [
                'title' => ['type' => 'text_general', 'stored' => true],
                'status' => ['type' => 'string', 'stored' => true],
            ],
            'extra' => ['old_field_1', 'deprecated_field'],
        ]);
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [
                'file_name' => ['type' => 'string', 'stored' => true],
            ],
            'extra' => ['legacy_file_field'],
        ]);

        $result = $this->controller->getSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);

        $comparison = $data['comparison'];
        $this->assertEquals(3, $comparison['missing_count']);
        $this->assertEquals(3, $comparison['extra_count']);
        $this->assertEquals(6, $comparison['total_differences']);

        // Verify missing fields contain collection info.
        $missing = $comparison['missing'];
        $this->assertCount(3, $missing);
        $this->assertEquals('title', $missing[0]['name']);
        $this->assertEquals('objects', $missing[0]['collection']);
        $this->assertEquals('Object Collection', $missing[0]['collectionLabel']);
        $this->assertEquals('file_name', $missing[2]['name']);
        $this->assertEquals('files', $missing[2]['collection']);
        $this->assertEquals('File Collection', $missing[2]['collectionLabel']);

        // Verify extra fields contain collection info.
        $extra = $comparison['extra'];
        $this->assertCount(3, $extra);
        $this->assertEquals('old_field_1', $extra[0]['name']);
        $this->assertEquals('objects', $extra[0]['collection']);
        $this->assertEquals('legacy_file_field', $extra[2]['name']);
        $this->assertEquals('files', $extra[2]['collection']);

        // Verify per-collection stats.
        $this->assertEquals(2, $comparison['object_collection']['missing']);
        $this->assertEquals(2, $comparison['object_collection']['extra']);
        $this->assertEquals(1, $comparison['file_collection']['missing']);
        $this->assertEquals(1, $comparison['file_collection']['extra']);
    }

    public function testGetSolrFieldsOnlyObjectMissing(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [
                'description' => ['type' => 'text_general', 'stored' => true],
            ],
            'extra' => [],
        ]);
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [],
            'extra' => [],
        ]);

        $result = $this->controller->getSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(1, $data['comparison']['missing_count']);
        $this->assertEquals(0, $data['comparison']['extra_count']);
    }

    public function testGetSolrFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Connection refused'));

        $result = $this->controller->getSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertStringContainsString('Connection refused', $result->getData()['message']);
    }

    // ========================================================================
    // createMissingSolrFields tests
    // ========================================================================

    public function testCreateMissingSolrFieldsSolrUnavailable(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testCreateMissingSolrFieldsSuccessNoMissing(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [],
            'extra' => [],
        ]);
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [],
            'extra' => [],
        ]);

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(0, $data['total_created']);
        $this->assertEquals(0, $data['total_errors']);
        $this->assertNull($data['results']['objects']);
        $this->assertNull($data['results']['files']);
    }

    public function testCreateMissingSolrFieldsSuccessWithMissingFields(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [
                'title' => ['type' => 'text_general'],
                'status' => ['type' => 'string'],
            ],
            'extra' => [],
        ]);
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [
                'file_name' => ['type' => 'string'],
            ],
            'extra' => [],
        ]);
        $mockService->method('createMissingFields')
            ->willReturnCallback(function (string $collectionType) {
                if ($collectionType === 'objects') {
                    return [
                        'created_count' => 2,
                        'error_count' => 0,
                        'success' => true,
                    ];
                }
                return [
                    'created_count' => 1,
                    'error_count' => 0,
                    'success' => true,
                ];
            });

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(3, $data['total_created']);
        $this->assertEquals(0, $data['total_errors']);
        $this->assertFalse($data['dry_run']);
    }

    public function testCreateMissingSolrFieldsDryRun(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [
                'title' => ['type' => 'text_general'],
            ],
            'extra' => [],
        ]);
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [],
            'extra' => [],
        ]);
        $mockService->method('createMissingFields')
            ->willReturn([
                'created_count' => 0,
                'error_count' => 0,
                'success' => true,
                'dry_run' => true,
            ]);

        $this->request->method('getParam')
            ->willReturn('true');

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['dry_run']);
    }

    public function testCreateMissingSolrFieldsWithErrors(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [
                'title' => ['type' => 'text_general'],
            ],
            'extra' => [],
        ]);
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [
                'file_name' => ['type' => 'string'],
            ],
            'extra' => [],
        ]);
        $mockService->method('createMissingFields')
            ->willReturnCallback(function (string $collectionType) {
                if ($collectionType === 'objects') {
                    return [
                        'created_count' => 1,
                        'error_count' => 0,
                    ];
                }
                return [
                    'created_count' => 0,
                    'error_count' => 1,
                ];
            });

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']); // errors > 0
        $this->assertEquals(1, $data['total_created']);
        $this->assertEquals(1, $data['total_errors']);
    }

    public function testCreateMissingSolrFieldsObjectExceptionFileSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);

        // Object field status throws exception.
        $mockService->method('getObjectCollectionFieldStatus')
            ->willThrowException(new \Exception('Object collection error'));
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [
                'file_name' => ['type' => 'string'],
            ],
            'extra' => [],
        ]);
        $mockService->method('createMissingFields')
            ->willReturn([
                'created_count' => 1,
                'error_count' => 0,
            ]);

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        // Object error increments totalErrors.
        $this->assertFalse($data['success']);
        $this->assertEquals(1, $data['total_errors']);
        $this->assertFalse($data['results']['objects']['success']);
    }

    public function testCreateMissingSolrFieldsFileException(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [],
            'extra' => [],
        ]);
        $mockService->method('getFileCollectionFieldStatus')
            ->willThrowException(new \Exception('File collection error'));

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals(1, $data['total_errors']);
        $this->assertFalse($data['results']['files']['success']);
    }

    public function testCreateMissingSolrFieldsBothExceptions(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')
            ->willThrowException(new \Exception('Object error'));
        $mockService->method('getFileCollectionFieldStatus')
            ->willThrowException(new \Exception('File error'));

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals(2, $data['total_errors']);
    }

    public function testCreateMissingSolrFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testCreateMissingSolrFieldsWithNullCounts(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getObjectCollectionFieldStatus')->willReturn([
            'missing' => [
                'title' => ['type' => 'text_general'],
            ],
            'extra' => [],
        ]);
        $mockService->method('getFileCollectionFieldStatus')->willReturn([
            'missing' => [],
            'extra' => [],
        ]);
        // Return result without created_count or error_count keys.
        $mockService->method('createMissingFields')
            ->willReturn([
                'success' => true,
            ]);

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        // With null counts, totals should remain 0.
        $this->assertEquals(0, $data['total_created']);
        $this->assertEquals(0, $data['total_errors']);
    }

    // ========================================================================
    // fixMismatchedSolrFields tests
    // ========================================================================

    public function testFixMismatchedSolrFieldsSolrUnavailable(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testFixMismatchedSolrFieldsFieldsConfigFailed(): void
    {
        $mockService = $this->mockFixMismatchedDeps();

        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getFieldsConfiguration')
            ->willReturn([
                'success' => false,
                'message' => 'Cannot connect to SOLR',
            ]);

        $this->settingsService->method('getExpectedSchemaFields')
            ->willReturn([]);

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Failed to get SOLR field configuration', $data['message']);
        $this->assertEquals('Cannot connect to SOLR', $data['details']['error']);
    }

    public function testFixMismatchedSolrFieldsFieldsConfigFailedNoMessage(): void
    {
        $mockService = $this->mockFixMismatchedDeps();

        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getFieldsConfiguration')
            ->willReturn([
                'success' => false,
            ]);

        $this->settingsService->method('getExpectedSchemaFields')
            ->willReturn([]);

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Unknown error', $data['details']['error']);
    }

    public function testFixMismatchedSolrFieldsNoMismatches(): void
    {
        $mockService = $this->mockFixMismatchedDeps();

        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getFieldsConfiguration')
            ->willReturn([
                'success' => true,
                'fields' => [
                    ['name' => 'title', 'type' => 'text_general'],
                ],
            ]);

        $this->settingsService->method('getExpectedSchemaFields')
            ->willReturn([
                'title' => ['type' => 'text_general'],
            ]);

        $this->settingsService->method('compareFields')
            ->willReturn([
                'mismatched' => [],
                'matching' => ['title'],
            ]);

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('No mismatched fields found - SOLR schema is properly configured', $data['message']);
        $this->assertEmpty($data['fixed']);
        $this->assertEmpty($data['errors']);
    }

    public function testFixMismatchedSolrFieldsWithMismatches(): void
    {
        $mockService = $this->mockFixMismatchedDeps();

        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getFieldsConfiguration')
            ->willReturn([
                'success' => true,
                'fields' => [
                    ['name' => 'title', 'type' => 'string'],
                ],
            ]);

        $this->settingsService->method('getExpectedSchemaFields')
            ->willReturn([
                'title' => ['type' => 'text_general', 'stored' => true],
            ]);

        $this->settingsService->method('compareFields')
            ->willReturn([
                'mismatched' => [
                    [
                        'field' => 'title',
                        'expected_config' => ['type' => 'text_general', 'stored' => true],
                        'actual_config' => ['type' => 'string'],
                    ],
                ],
            ]);

        $mockService->method('fixMismatchedFields')
            ->willReturn([
                'success' => true,
                'fixed' => ['title'],
                'errors' => [],
            ]);

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['title'], $data['fixed']);
    }

    public function testFixMismatchedSolrFieldsWithFieldsConfigNoFieldsKey(): void
    {
        $mockService = $this->mockFixMismatchedDeps();

        $mockService->method('isAvailable')->willReturn(true);
        $mockService->method('getFieldsConfiguration')
            ->willReturn([
                'success' => true,
                // No 'fields' key.
            ]);

        $this->settingsService->method('getExpectedSchemaFields')
            ->willReturn([]);

        $this->settingsService->method('compareFields')
            ->willReturn([
                'mismatched' => [],
            ]);

        $this->request->method('getParam')
            ->willReturn(false);

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testFixMismatchedSolrFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service error'));

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertStringContainsString('Service error', $result->getData()['message']);
    }

    // ========================================================================
    // deleteSolrField tests
    // ========================================================================

    public function testDeleteSolrFieldProtectedField(): void
    {
        $protectedFields = ['id', '_version_', '_root_', '_text_'];
        foreach ($protectedFields as $field) {
            $result = $this->controller->deleteSolrField($field);
            $this->assertEquals(403, $result->getStatus());
            $data = $result->getData();
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('protected system field', $data['message']);
        }
    }

    public function testDeleteSolrFieldSuccess(): void
    {
        $mockService = $this->createMock(SolrManagementIndexServiceStub::class);
        $this->container->method('get')->willReturn($mockService);
        $mockService->method('deleteField')
            ->willReturn([
                'success' => true,
                'message' => 'Field deleted successfully',
            ]);

        $result = $this->controller->deleteSolrField('custom_field');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('custom_field', $data['field_name']);
    }

    public function testDeleteSolrFieldFailure(): void
    {
        $mockService = $this->createMock(SolrManagementIndexServiceStub::class);
        $this->container->method('get')->willReturn($mockService);
        $mockService->method('deleteField')
            ->willReturn([
                'success' => false,
                'message' => 'Field not found',
                'error' => 'No such field: custom_field',
            ]);

        $result = $this->controller->deleteSolrField('custom_field');

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Field not found', $data['message']);
        $this->assertEquals('No such field: custom_field', $data['error']);
    }

    public function testDeleteSolrFieldFailureNoError(): void
    {
        $mockService = $this->createMock(SolrManagementIndexServiceStub::class);
        $this->container->method('get')->willReturn($mockService);
        $mockService->method('deleteField')
            ->willReturn([
                'success' => false,
                'message' => 'Field not found',
            ]);

        $result = $this->controller->deleteSolrField('custom_field');

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertNull($data['error']);
    }

    public function testDeleteSolrFieldException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Connection failed'));

        $result = $this->controller->deleteSolrField('custom_field');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Connection failed', $data['message']);
        $this->assertEquals('Connection failed', $data['error']);
    }

    // ========================================================================
    // deleteSpecificSolrCollection tests
    // ========================================================================

    public function testDeleteSpecificSolrCollectionSuccess(): void
    {
        $mockService = $this->createMock(SolrManagementIndexServiceStub::class);
        $this->container->method('get')->willReturn($mockService);
        $mockService->method('deleteCollection')
            ->willReturn([
                'success' => true,
                'message' => 'Collection deleted',
            ]);

        $result = $this->controller->deleteSpecificSolrCollection('test_collection');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Collection deleted successfully', $data['message']);
        $this->assertEquals('test_collection', $data['collection']);
    }

    public function testDeleteSpecificSolrCollectionFailure(): void
    {
        $mockService = $this->createMock(SolrManagementIndexServiceStub::class);
        $this->container->method('get')->willReturn($mockService);
        $mockService->method('deleteCollection')
            ->willReturn([
                'success' => false,
                'message' => 'Collection not found',
                'error_code' => 'NOT_FOUND',
                'solr_error' => 'No such collection',
            ]);

        $result = $this->controller->deleteSpecificSolrCollection('nonexistent');

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Collection not found', $data['message']);
        $this->assertEquals('NOT_FOUND', $data['error_code']);
        $this->assertEquals('nonexistent', $data['collection']);
        $this->assertEquals('No such collection', $data['solr_error']);
    }

    public function testDeleteSpecificSolrCollectionFailureNoErrorCode(): void
    {
        $mockService = $this->createMock(SolrManagementIndexServiceStub::class);
        $this->container->method('get')->willReturn($mockService);
        $mockService->method('deleteCollection')
            ->willReturn([
                'success' => false,
                'message' => 'Unknown failure',
            ]);

        $result = $this->controller->deleteSpecificSolrCollection('test_col');

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('unknown', $data['error_code']);
        $this->assertNull($data['solr_error']);
    }

    public function testDeleteSpecificSolrCollectionException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->deleteSpecificSolrCollection('test_col');

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Service unavailable', $data['message']);
        $this->assertEquals('EXCEPTION', $data['error_code']);
        $this->assertEquals('test_col', $data['collection']);
    }

    // ========================================================================
    // updateSolrCollectionAssignments tests
    // ========================================================================

    public function testUpdateSolrCollectionAssignmentsSuccess(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['objectCollection' => 'old_obj', 'fileCollection' => 'old_file']);

        $result = $this->controller->updateSolrCollectionAssignments('new_obj', 'new_file');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Collection assignments updated successfully', $data['message']);
        $this->assertEquals('new_obj', $data['objectCollection']);
        $this->assertEquals('new_file', $data['fileCollection']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testUpdateSolrCollectionAssignmentsOnlyObject(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['objectCollection' => 'old_obj', 'fileCollection' => 'old_file']);

        $result = $this->controller->updateSolrCollectionAssignments('new_obj', null);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('new_obj', $data['objectCollection']);
        $this->assertEquals('old_file', $data['fileCollection']);
    }

    public function testUpdateSolrCollectionAssignmentsOnlyFile(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['objectCollection' => 'old_obj', 'fileCollection' => 'old_file']);

        $result = $this->controller->updateSolrCollectionAssignments(null, 'new_file');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('old_obj', $data['objectCollection']);
        $this->assertEquals('new_file', $data['fileCollection']);
    }

    public function testUpdateSolrCollectionAssignmentsBothNull(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['objectCollection' => 'existing_obj', 'fileCollection' => 'existing_file']);

        $result = $this->controller->updateSolrCollectionAssignments(null, null);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('existing_obj', $data['objectCollection']);
        $this->assertEquals('existing_file', $data['fileCollection']);
    }

    public function testUpdateSolrCollectionAssignmentsException(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willThrowException(new \Exception('Settings error'));

        $result = $this->controller->updateSolrCollectionAssignments('obj', 'file');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Settings error', $data['error']);
        $this->assertArrayHasKey('trace', $data);
    }

    public function testUpdateSolrCollectionAssignmentsSaveException(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['objectCollection' => 'old', 'fileCollection' => 'old']);
        $this->settingsService->method('updateSolrSettingsOnly')
            ->willThrowException(new \Exception('Save failed'));

        $result = $this->controller->updateSolrCollectionAssignments('new_obj', 'new_file');

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }
}
