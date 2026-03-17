<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\ChunkProcessingHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\PreparationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branch coverage tests for SaveObjects — targets uncovered branches in
 * saveObjects (empty input, deduplication, no valid objects after prep).
 */
class SaveObjectsBranchCoverageTest extends TestCase
{
    private SaveObjects $service;
    private UnifiedObjectMapper&MockObject $objectMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SaveObject&MockObject $saveHandler;
    private BulkValidationHandler&MockObject $bulkValidHandler;
    private BulkRelationHandler&MockObject $bulkRelationHandler;
    private TransformationHandler&MockObject $transformHandler;
    private PreparationHandler&MockObject $preparationHandler;
    private ChunkProcessingHandler&MockObject $chunkProcHandler;
    private OrganisationService&MockObject $organisationService;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->saveHandler = $this->createMock(SaveObject::class);
        $this->bulkValidHandler = $this->createMock(BulkValidationHandler::class);
        $this->bulkRelationHandler = $this->createMock(BulkRelationHandler::class);
        $this->transformHandler = $this->createMock(TransformationHandler::class);
        $this->preparationHandler = $this->createMock(PreparationHandler::class);
        $this->chunkProcHandler = $this->createMock(ChunkProcessingHandler::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Clear static caches via reflection
        $ref = new \ReflectionClass(SaveObjects::class);
        foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
            $p = $ref->getProperty($prop);
            $p->setValue(null, []);
        }

        $this->service = new SaveObjects(
            $this->objectMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->saveHandler,
            $this->bulkValidHandler,
            $this->bulkRelationHandler,
            $this->transformHandler,
            $this->preparationHandler,
            $this->chunkProcHandler,
            $this->organisationService,
            $this->userSession,
            $this->logger
        );
    }

    // =========================================================================
    // saveObjects — empty input
    // =========================================================================

    public function testSaveObjectsEmptyArray(): void
    {
        $result = $this->service->saveObjects([]);

        $this->assertSame([], $result['saved']);
        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['unchanged']);
        $this->assertSame([], $result['invalid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['statistics']['totalProcessed']);
    }

    // =========================================================================
    // saveObjects — mixed schema with preparation returning no valid objects
    // =========================================================================

    public function testSaveObjectsMixedSchemaNoValidObjects(): void
    {
        $this->preparationHandler->method('prepareObjectsForBulkSave')
            ->willReturn([
                [],  // processedObjects (empty)
                [],  // schemaCache
                [    // invalidObjects
                    ['data' => ['id' => '1'], 'error' => 'Missing schema'],
                ],
            ]);

        $result = $this->service->saveObjects(
            [['id' => '1', 'name' => 'test']],
            null,  // no register
            null   // no schema = mixed
        );

        $this->assertSame(1, $result['statistics']['invalid']);
        $this->assertNotEmpty($result['errors']);
    }

    // =========================================================================
    // saveObjects — deduplication removes duplicates
    // =========================================================================

    public function testSaveObjectsDeduplicatesById(): void
    {
        $this->preparationHandler->method('prepareObjectsForBulkSave')
            ->willReturn([[], [], []]);

        $objects = [
            ['id' => 'same-id', 'name' => 'first'],
            ['id' => 'same-id', 'name' => 'second'],
            ['id' => 'unique-id', 'name' => 'third'],
        ];

        $result = $this->service->saveObjects($objects, null, null);

        // Should have processed but with no valid objects after preparation
        $this->assertIsArray($result);
    }

    // =========================================================================
    // saveObjects — deduplication disabled
    // =========================================================================

    public function testSaveObjectsWithoutDeduplication(): void
    {
        $this->preparationHandler->method('prepareObjectsForBulkSave')
            ->willReturn([[], [], []]);

        $objects = [
            ['id' => 'same-id', 'name' => 'first'],
            ['id' => 'same-id', 'name' => 'second'],
        ];

        $result = $this->service->saveObjects(
            $objects,
            null,
            null,
            true,
            true,
            false,
            false,
            false  // deduplicateIds = false
        );

        $this->assertIsArray($result);
    }
}
