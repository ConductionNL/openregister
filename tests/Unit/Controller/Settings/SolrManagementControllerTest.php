<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\SolrManagementController;
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

    private function mockIndexService(): MockObject
    {
        $mockService = $this->createMock(SolrManagementIndexServiceStub::class);
        $this->container->method('get')
            ->willReturn($mockService);
        return $mockService;
    }

    public function testGetSolrFieldsSolrUnavailable(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->getSolrFields();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
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
    }

    public function testGetSolrFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getSolrFields();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testCreateMissingSolrFieldsSolrUnavailable(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testCreateMissingSolrFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->createMissingSolrFields();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testFixMismatchedSolrFieldsSolrUnavailable(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testFixMismatchedSolrFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->fixMismatchedSolrFields();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testListSolrCollectionsSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('listCollections')->willReturn([
            ['name' => 'objects'],
            ['name' => 'files'],
        ]);

        $result = $this->controller->listSolrCollections();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals(2, $result->getData()['count']);
    }

    public function testListSolrCollectionsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->listSolrCollections();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testListSolrConfigSetsSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('listConfigSets')->willReturn(['_default']);

        $result = $this->controller->listSolrConfigSets();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testListSolrConfigSetsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->listSolrConfigSets();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testCreateSolrConfigSetSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('createConfigSet')
            ->willReturn(['success' => true]);

        $result = $this->controller->createSolrConfigSet('test_config');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCreateSolrConfigSetException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->createSolrConfigSet('test_config');

        $this->assertEquals(400, $result->getStatus());
    }

    public function testDeleteSolrConfigSetSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('deleteConfigSet')
            ->willReturn(['success' => true]);

        $result = $this->controller->deleteSolrConfigSet('test_config');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDeleteSolrConfigSetException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->deleteSolrConfigSet('test_config');

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCreateSolrCollectionSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('createCollection')
            ->willReturn(['success' => true]);

        $result = $this->controller->createSolrCollection('test_col', '_default');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCreateSolrCollectionException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->createSolrCollection('test_col', '_default');

        $this->assertEquals(500, $result->getStatus());
    }

    public function testCopySolrCollectionSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('copyCollection')
            ->willReturn(['success' => true]);

        $result = $this->controller->copySolrCollection('source', 'target');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCopySolrCollectionException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->copySolrCollection('source', 'target');

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateSolrCollectionAssignmentsSuccess(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['objectCollection' => 'old_obj', 'fileCollection' => 'old_file']);

        $result = $this->controller->updateSolrCollectionAssignments('new_obj', 'new_file');

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testUpdateSolrCollectionAssignmentsException(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->updateSolrCollectionAssignments('obj', 'file');

        $this->assertEquals(500, $result->getStatus());
    }
}
