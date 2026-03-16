<?php

declare(strict_types=1);

/**
 * ChunkProcessingHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Object\SaveObjects\ChunkProcessingHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ChunkProcessingHandler.
 *
 * Covers the processObjectsChunk() method across the following scenarios:
 * - Successful bulk upsert with database-computed classification (created/updated/unchanged)
 * - Validation failures produced by TransformationHandler
 * - Partial success: mix of valid and invalid objects in the same chunk
 * - Legacy/fallback behaviour when the bulk result is a plain UUID array
 * - Fallback when ultraFastBulkSave returns an unexpected non-array value
 * - Empty-chunk short-circuit (no DB call)
 * - All-invalid-chunk short-circuit
 * - processingTimeMs always present in statistics
 * - Register/schema resolution when IDs (int/string) are passed
 * - Correct aggregation of statistics across multiple classification outcomes
 */
class ChunkProcessingHandlerTest extends TestCase
{
    /** @var TransformationHandler&MockObject */
    private TransformationHandler $transformHandler;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var MagicMapper&MockObject */
    private MagicMapper $unifiedObjectMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private ChunkProcessingHandler $handler;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal Schema entity with a given id (set via reflection).
     */
    private function buildSchema(int $id): Schema
    {
        $schema = new Schema();
        $ref    = new ReflectionClass($schema);
        $prop   = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        return $schema;
    }

    /**
     * Build a minimal Register entity with a given id (set via reflection).
     */
    private function buildRegister(int $id): Register
    {
        $register = new Register();
        $ref      = new ReflectionClass($register);
        $prop     = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }

    /**
     * Build a single transformed-object array as TransformationHandler would produce,
     * including an object_status field to trigger the new classification path.
     */
    private function buildCompleteObject(string $uuid, string $status): array
    {
        return [
            '_uuid'         => $uuid,
            'uuid'          => $uuid,
            'register'      => 1,
            'schema'        => 10,
            'object'        => ['title' => 'Test'],
            'object_status' => $status,
        ];
    }

    // -------------------------------------------------------------------------
    // setUp
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->transformHandler    = $this->createMock(TransformationHandler::class);
        $this->objectEntityMapper  = $this->createMock(ObjectEntityMapper::class);
        $this->unifiedObjectMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->handler = new ChunkProcessingHandler(
            $this->transformHandler,
            $this->objectEntityMapper,
            $this->unifiedObjectMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->logger
        );
    }

    // =========================================================================
    // 1. Empty-chunk short-circuit — no DB call when nothing is valid
    // =========================================================================

    public function testEmptyChunkReturnsImmediatelyWithoutCallingBulkSave(): void
    {
        $this->transformHandler->expects($this->once())
            ->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => [], 'invalid' => []]);

        $this->unifiedObjectMapper->expects($this->never())
            ->method('ultraFastBulkSave');

        $result = $this->handler->processObjectsChunk(
            objects: [],
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame([], $result['saved']);
        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['unchanged']);
        $this->assertSame([], $result['invalid']);
        $this->assertSame(0, $result['statistics']['saved']);
        $this->assertArrayHasKey('processingTimeMs', $result['statistics']);
    }

    // =========================================================================
    // 2. All objects invalid — no DB call, invalid list populated
    // =========================================================================

    public function testAllInvalidObjectsSkipsBulkSaveAndPopulatesInvalidList(): void
    {
        $invalidEntries = [
            ['object' => ['name' => 'A'], 'error' => 'Missing register', 'index' => 0, 'type' => 'MissingRegisterException'],
            ['object' => ['name' => 'B'], 'error' => 'Missing schema',   'index' => 1, 'type' => 'MissingSchemaException'],
        ];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => [], 'invalid' => $invalidEntries]);

        $this->unifiedObjectMapper->expects($this->never())
            ->method('ultraFastBulkSave');

        $result = $this->handler->processObjectsChunk(
            objects: [['name' => 'A'], ['name' => 'B']],
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame(2, $result['statistics']['invalid']);
        $this->assertCount(2, $result['invalid']);
        $this->assertSame(0, $result['statistics']['saved']);
        $this->assertArrayHasKey('processingTimeMs', $result['statistics']);
    }

    // =========================================================================
    // 3. Successful bulk save — database-computed "created" status
    // =========================================================================

    public function testBulkSaveWithCreatedStatusPopulatesSavedList(): void
    {
        $uuid1 = 'aaaaaaaa-0000-4000-a000-000000000001';
        $uuid2 = 'aaaaaaaa-0000-4000-a000-000000000002';

        $validObjects = [
            ['uuid' => $uuid1, 'register' => 1, 'schema' => 10, 'object' => []],
            ['uuid' => $uuid2, 'register' => 1, 'schema' => 10, 'object' => []],
        ];

        $bulkResult = [
            $this->buildCompleteObject($uuid1, 'created'),
            $this->buildCompleteObject($uuid2, 'created'),
        ];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->expects($this->once())
            ->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame(2, $result['statistics']['saved']);
        $this->assertSame(0, $result['statistics']['updated']);
        $this->assertSame(0, $result['statistics']['unchanged']);
        $this->assertCount(2, $result['saved']);
        $this->assertCount(0, $result['updated']);
    }

    // =========================================================================
    // 4. Database-computed "updated" status
    // =========================================================================

    public function testBulkSaveWithUpdatedStatusPopulatesUpdatedList(): void
    {
        $uuid = 'bbbbbbbb-0000-4000-b000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []]];
        $bulkResult   = [$this->buildCompleteObject($uuid, 'updated')];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame(0, $result['statistics']['saved']);
        $this->assertSame(1, $result['statistics']['updated']);
        $this->assertSame(0, $result['statistics']['unchanged']);
        $this->assertCount(1, $result['updated']);
    }

    // =========================================================================
    // 5. Database-computed "unchanged" status
    // =========================================================================

    public function testBulkSaveWithUnchangedStatusPopulatesUnchangedList(): void
    {
        $uuid = 'cccccccc-0000-4000-c000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []]];
        $bulkResult   = [$this->buildCompleteObject($uuid, 'unchanged')];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame(0, $result['statistics']['saved']);
        $this->assertSame(0, $result['statistics']['updated']);
        $this->assertSame(1, $result['statistics']['unchanged']);
        $this->assertCount(1, $result['unchanged']);
    }

    // =========================================================================
    // 6. Mixed statuses — created + updated + unchanged in one chunk
    // =========================================================================

    public function testMixedStatusesAggregateCorrectly(): void
    {
        $uuidCreated   = 'dddddddd-0000-4000-d000-000000000001';
        $uuidUpdated   = 'dddddddd-0000-4000-d000-000000000002';
        $uuidUnchanged = 'dddddddd-0000-4000-d000-000000000003';

        $validObjects = [
            ['uuid' => $uuidCreated,   'register' => 1, 'schema' => 10, 'object' => []],
            ['uuid' => $uuidUpdated,   'register' => 1, 'schema' => 10, 'object' => []],
            ['uuid' => $uuidUnchanged, 'register' => 1, 'schema' => 10, 'object' => []],
        ];

        $bulkResult = [
            $this->buildCompleteObject($uuidCreated,   'created'),
            $this->buildCompleteObject($uuidUpdated,   'updated'),
            $this->buildCompleteObject($uuidUnchanged, 'unchanged'),
        ];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame(1, $result['statistics']['saved']);
        $this->assertSame(1, $result['statistics']['updated']);
        $this->assertSame(1, $result['statistics']['unchanged']);
        $this->assertCount(1, $result['saved']);
        $this->assertCount(1, $result['updated']);
        $this->assertCount(1, $result['unchanged']);
    }

    // =========================================================================
    // 7. Partial chunk — some valid, some invalid
    // =========================================================================

    public function testPartialChunkWithInvalidAndValidObjectsMergesCorrectly(): void
    {
        $uuid         = 'eeeeeeee-0000-4000-e000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []]];
        $invalidObjs  = [['object' => ['x' => 1], 'error' => 'Missing register', 'index' => 1, 'type' => 'MissingRegisterException']];
        $bulkResult   = [$this->buildCompleteObject($uuid, 'created')];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => $invalidObjs]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: array_merge($validObjects, [['x' => 1]]),
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame(1, $result['statistics']['saved']);
        $this->assertSame(1, $result['statistics']['invalid']);
        $this->assertCount(1, $result['saved']);
        $this->assertCount(1, $result['invalid']);
    }

    // =========================================================================
    // 8. Legacy fallback — bulk result is a plain UUID array (no object_status)
    // =========================================================================

    public function testLegacyUuidArrayBulkResultCountsSaved(): void
    {
        $uuid = 'ffffffff-0000-4000-f000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []]];

        // Return a plain array of UUID strings (no 'object_status' key).
        $bulkResult = [$uuid];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        // Legacy path: count based on UUID match, no reconstruction.
        $this->assertSame(1, $result['statistics']['saved']);
        $this->assertArrayHasKey('processingTimeMs', $result['statistics']);
    }

    // =========================================================================
    // 9. Legacy fallback — UUID not in bulk result (unmatched)
    // =========================================================================

    public function testLegacyUuidArrayDoesNotCountUnmatchedObjects(): void
    {
        $uuid = '11111111-0000-4000-a000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []]];

        // Return empty bulk result — nothing was saved.
        $bulkResult = [];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame(0, $result['statistics']['saved']);
    }

    // =========================================================================
    // 10. Legacy fallback — bulk result is array of non-array scalars (no first-item key)
    //     This exercises the UUID-array counting branch for objects whose UUID
    //     is NOT present in the returned UUID list (count stays 0).
    // =========================================================================

    public function testLegacyScalarArrayWithNoMatchingUuidsCountsZeroSaved(): void
    {
        $uuid1 = '22222222-0000-4000-a000-000000000001';
        $uuid2 = '22222222-0000-4000-a000-000000000002';
        $validObjects = [
            ['uuid' => $uuid1, 'register' => 1, 'schema' => 10, 'object' => []],
            ['uuid' => $uuid2, 'register' => 1, 'schema' => 10, 'object' => []],
        ];

        // Return UUIDs that do NOT match the objects — both miss.
        $bulkResult = ['unrelated-uuid-1', 'unrelated-uuid-2'];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        // No UUID matched → saved count stays 0 in legacy path.
        $this->assertSame(0, $result['statistics']['saved']);
        $this->assertArrayHasKey('processingTimeMs', $result['statistics']);
    }

    // =========================================================================
    // 11. processingTimeMs is always a non-negative float
    // =========================================================================

    public function testProcessingTimeMsIsAlwaysPresentAndNonNegative(): void
    {
        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => [], 'invalid' => []]);

        $result = $this->handler->processObjectsChunk(
            objects: [],
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertArrayHasKey('processingTimeMs', $result['statistics']);
        $this->assertGreaterThanOrEqual(0.0, $result['statistics']['processingTimeMs']);
    }

    // =========================================================================
    // 12. Register and schema passed as object entities — no resolution needed
    // =========================================================================

    public function testRegisterAndSchemaObjectsPassedDirectlyToUltraFastBulkSave(): void
    {
        $register = $this->buildRegister(5);
        $schema   = $this->buildSchema(20);

        $uuid = '33333333-0000-4000-a000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 5, 'schema' => 20, 'object' => []]];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->expects($this->once())
            ->method('ultraFastBulkSave')
            ->with(
                $this->anything(),    // insertObjects
                $this->anything(),    // updateObjects
                $register,            // register entity passed through
                $schema               // schema entity passed through
            )
            ->willReturn([$this->buildCompleteObject($uuid, 'created')]);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false,
            register: $register,
            schema: $schema
        );

        $this->assertSame(1, $result['statistics']['saved']);
    }

    // =========================================================================
    // 13. ultraFastBulkSave receives empty updateObjects array
    // =========================================================================

    public function testUltraFastBulkSaveIsCalledWithEmptyUpdateObjectsArray(): void
    {
        $uuid = '44444444-0000-4000-a000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []]];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->expects($this->once())
            ->method('ultraFastBulkSave')
            ->with(
                $validObjects,  // insertObjects = the transformed objects
                [],             // updateObjects = always empty
                $this->anything(),
                $this->anything()
            )
            ->willReturn([$this->buildCompleteObject($uuid, 'created')]);

        $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );
    }

    // =========================================================================
    // 14. Result structure keys are always present
    // =========================================================================

    public function testResultAlwaysContainsAllRequiredKeys(): void
    {
        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => [], 'invalid' => []]);

        $result = $this->handler->processObjectsChunk(
            objects: [],
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        foreach (['saved', 'updated', 'unchanged', 'invalid', 'errors', 'statistics'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }

        foreach (['saved', 'updated', 'unchanged', 'invalid', 'errors'] as $statKey) {
            $this->assertArrayHasKey($statKey, $result['statistics'], "Missing statistics key: {$statKey}");
        }
    }

    // =========================================================================
    // 15. Multiple invalid objects all land in the invalid list
    // =========================================================================

    public function testMultipleInvalidObjectsAreAllAccumulated(): void
    {
        $invalidEntries = array_map(
            static fn(int $i) => [
                'object' => ['index' => $i],
                'error'  => "Error #{$i}",
                'index'  => $i,
                'type'   => 'MissingRegisterException',
            ],
            range(0, 4)
        );

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => [], 'invalid' => $invalidEntries]);

        $this->unifiedObjectMapper->expects($this->never())
            ->method('ultraFastBulkSave');

        $result = $this->handler->processObjectsChunk(
            objects: [],
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame(5, $result['statistics']['invalid']);
        $this->assertCount(5, $result['invalid']);
    }

    // =========================================================================
    // 16. Unexpected object_status — source bug: default case calls ->jsonSerialize()
    //     on an array, producing an Error. This test documents the existing
    //     defect so it is caught as a regression if the code is fixed.
    // =========================================================================

    public function testUnknownObjectStatusExposesSourceBugInDefaultCase(): void
    {
        $uuid         = '55555555-0000-4000-a000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []]];

        $completeObject         = $this->buildCompleteObject($uuid, 'unknown_status');
        $completeObject['uuid'] = $uuid;

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn([$completeObject]);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // The default case in the switch statement calls $completeObject->jsonSerialize()
        // but $completeObject is an array, not an object — this is a bug in the source.
        // Expect the resulting Error until the source is corrected.
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Call to a member function jsonSerialize\(\) on array/');

        $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );
    }

    // =========================================================================
    // 17. Large chunk — statistics tally correctly
    // =========================================================================

    public function testLargeChunkStatisticsAreTalliedCorrectly(): void
    {
        $created   = 50;
        $updated   = 30;
        $unchanged = 20;

        $validObjects = [];
        $bulkResult   = [];

        foreach (range(1, $created) as $i) {
            $uuid           = sprintf('created00-0000-4000-a000-%012d', $i);
            $validObjects[] = ['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []];
            $bulkResult[]   = $this->buildCompleteObject($uuid, 'created');
        }

        foreach (range(1, $updated) as $i) {
            $uuid           = sprintf('updated00-0000-4000-b000-%012d', $i);
            $validObjects[] = ['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []];
            $bulkResult[]   = $this->buildCompleteObject($uuid, 'updated');
        }

        foreach (range(1, $unchanged) as $i) {
            $uuid           = sprintf('unchngd00-0000-4000-c000-%012d', $i);
            $validObjects[] = ['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []];
            $bulkResult[]   = $this->buildCompleteObject($uuid, 'unchanged');
        }

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertSame($created,   $result['statistics']['saved']);
        $this->assertSame($updated,   $result['statistics']['updated']);
        $this->assertSame($unchanged, $result['statistics']['unchanged']);
        $this->assertCount($created,   $result['saved']);
        $this->assertCount($updated,   $result['updated']);
        $this->assertCount($unchanged, $result['unchanged']);
    }

    // =========================================================================
    // 18. Null register and null schema are forwarded to ultraFastBulkSave
    // =========================================================================

    public function testNullRegisterAndSchemaPropagateToUltraFastBulkSave(): void
    {
        $uuid = '66666666-0000-4000-a000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => []]];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->expects($this->once())
            ->method('ultraFastBulkSave')
            ->with(
                $this->anything(),
                $this->anything(),
                null,    // register
                null     // schema
            )
            ->willReturn([$this->buildCompleteObject($uuid, 'created')]);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false,
            register: null,
            schema: null
        );

        $this->assertSame(1, $result['statistics']['saved']);
    }

    // =========================================================================
    // 19. Boolean flags do not cause errors when varied
    // =========================================================================

    public function testBooleanFlagVariationsDoNotCauseErrors(): void
    {
        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => [], 'invalid' => []]);

        // Should not throw for any combination of boolean flags.
        $result = $this->handler->processObjectsChunk(
            objects: [],
            schemaCache: [],
            _rbac: true,
            _multitenancy: true,
            _validation: true,
            _events: true
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('statistics', $result);
    }

    // =========================================================================
    // 20. TransformationHandler is called exactly once per chunk
    // =========================================================================

    public function testTransformHandlerIsCalledExactlyOnce(): void
    {
        $this->transformHandler->expects($this->once())
            ->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => [], 'invalid' => []]);

        $this->handler->processObjectsChunk(
            objects: [['name' => 'A'], ['name' => 'B']],
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );
    }

    // =========================================================================
    // 21. schemaCache is forwarded to TransformationHandler
    // =========================================================================

    public function testSchemaCacheIsForwardedToTransformationHandler(): void
    {
        $schema      = $this->buildSchema(10);
        $schemaCache = [10 => $schema];

        $this->transformHandler->expects($this->once())
            ->method('transformObjectsToDatabaseFormatInPlace')
            ->with(
                $this->anything(), // objects (passed by reference, matcher still works)
                $schemaCache
            )
            ->willReturn(['valid' => [], 'invalid' => []]);

        $this->handler->processObjectsChunk(
            objects: [],
            schemaCache: $schemaCache,
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );
    }

    // =========================================================================
    // 22. saved list items are jsonSerializable arrays (have @self or similar)
    // =========================================================================

    public function testSavedItemsAreJsonSerializableArrays(): void
    {
        $uuid         = '77777777-0000-4000-a000-000000000001';
        $validObjects = [['uuid' => $uuid, 'register' => 1, 'schema' => 10, 'object' => ['foo' => 'bar']]];
        $bulkResult   = [$this->buildCompleteObject($uuid, 'created')];

        $this->transformHandler->method('transformObjectsToDatabaseFormatInPlace')
            ->willReturn(['valid' => $validObjects, 'invalid' => []]);

        $this->unifiedObjectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);

        $result = $this->handler->processObjectsChunk(
            objects: $validObjects,
            schemaCache: [],
            _rbac: false,
            _multitenancy: false,
            _validation: false,
            _events: false
        );

        $this->assertCount(1, $result['saved']);
        $savedItem = $result['saved'][0];
        // jsonSerialize() returns an array with a '@self' key.
        $this->assertIsArray($savedItem);
        $this->assertArrayHasKey('@self', $savedItem);
    }
}//end class
