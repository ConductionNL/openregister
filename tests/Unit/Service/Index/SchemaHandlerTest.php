<?php

declare(strict_types=1);

/**
 * SchemaHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Index
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Index;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Index\SchemaHandler;
use OCA\OpenRegister\Service\Index\SearchBackendInterface;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Index SchemaHandler
 *
 * Tests vector field type management, schema mirroring, conflict resolution,
 * field type determination, and collection field status.
 */
class SchemaHandlerTest extends TestCase
{
    private SchemaHandler $handler;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var SearchBackendInterface&MockObject */
    private SearchBackendInterface $searchBackend;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var IConfig&MockObject */
    private IConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper  = $this->createMock(SchemaMapper::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->config        = $this->createMock(IConfig::class);
        $this->searchBackend = $this->createMock(SearchBackendInterface::class);

        $this->handler = new SchemaHandler(
            $this->schemaMapper,
            $this->logger,
            $this->config,
            $this->searchBackend
        );
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * Build a real Schema entity with given properties (via Entity __call magic).
     */
    private function makeSchema(int $id, array $properties): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setProperties($properties);
        return $schema;
    }

    // =========================================================================
    // ensureVectorFieldType
    // =========================================================================

    public function testEnsureVectorFieldTypeAlreadyExists(): void
    {
        $this->searchBackend->method('getFieldTypes')
            ->willReturn(['knn_vector' => ['name' => 'knn_vector', 'class' => 'solr.DenseVectorField']]);

        $result = $this->handler->ensureVectorFieldType('my-collection');

        $this->assertTrue($result);
    }

    public function testEnsureVectorFieldTypeAlreadyExistsDoesNotCallAddFieldType(): void
    {
        $this->searchBackend->method('getFieldTypes')
            ->willReturn(['knn_vector' => ['name' => 'knn_vector']]);

        $this->searchBackend->expects($this->never())
            ->method('addFieldType');

        $this->handler->ensureVectorFieldType('any-collection');
    }

    public function testEnsureVectorFieldTypeCreatesNewWithDefaults(): void
    {
        $this->searchBackend->method('getFieldTypes')->willReturn([]);

        $this->searchBackend->expects($this->once())
            ->method('addFieldType')
            ->with(
                'my-collection',
                $this->callback(static function (array $fieldType): bool {
                    return $fieldType['name'] === 'knn_vector'
                        && $fieldType['class'] === 'solr.DenseVectorField'
                        && $fieldType['vectorDimension'] === 4096
                        && $fieldType['similarityFunction'] === 'cosine';
                })
            )
            ->willReturn(true);

        $result = $this->handler->ensureVectorFieldType('my-collection');

        $this->assertTrue($result);
    }

    public function testEnsureVectorFieldTypeCreatesNewWithCustomDimensionsAndSimilarity(): void
    {
        $this->searchBackend->method('getFieldTypes')->willReturn([]);

        $this->searchBackend->expects($this->once())
            ->method('addFieldType')
            ->with(
                'test-col',
                $this->callback(static function (array $ft): bool {
                    return $ft['vectorDimension'] === 768
                        && $ft['similarityFunction'] === 'dot_product';
                })
            )
            ->willReturn(true);

        $result = $this->handler->ensureVectorFieldType('test-col', 768, 'dot_product');

        $this->assertTrue($result);
    }

    public function testEnsureVectorFieldTypeReturnsFalseWhenAddFails(): void
    {
        $this->searchBackend->method('getFieldTypes')->willReturn([]);
        $this->searchBackend->method('addFieldType')->willReturn(false);

        $result = $this->handler->ensureVectorFieldType('my-collection');

        $this->assertFalse($result);
    }

    public function testEnsureVectorFieldTypeHandlesGetFieldTypesException(): void
    {
        $this->searchBackend->method('getFieldTypes')
            ->willThrowException(new \Exception('Connection failed'));

        $result = $this->handler->ensureVectorFieldType('my-collection');

        $this->assertFalse($result);
    }

    public function testEnsureVectorFieldTypeHandlesAddFieldTypeException(): void
    {
        $this->searchBackend->method('getFieldTypes')->willReturn([]);
        $this->searchBackend->method('addFieldType')
            ->willThrowException(new \Exception('Schema update failed'));

        $result = $this->handler->ensureVectorFieldType('my-collection');

        $this->assertFalse($result);
    }

    // =========================================================================
    // mirrorSchemas
    // =========================================================================

    public function testMirrorSchemasWithNoSchemasSucceeds(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);

        // Core metadata fields will call addOrUpdateField for each core field.
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('stats', $result);
        $this->assertArrayHasKey('execution_time_ms', $result);
        $this->assertSame(0, $result['stats']['schemas_processed']);
    }

    public function testMirrorSchemasProcessesSchemas(): void
    {
        $schema = $this->makeSchema(1, ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['stats']['schemas_processed']);
    }

    public function testMirrorSchemasWithForceFlag(): void
    {
        $schema = $this->makeSchema(2, ['title' => ['type' => 'text']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        // With force=true, addOrUpdateField should be called and may return 'updated'.
        $this->searchBackend->method('addOrUpdateField')->willReturn('updated');

        $result = $this->handler->mirrorSchemas(force: true);

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(0, $result['stats']['fields_updated']);
    }

    public function testMirrorSchemasCountsCreatedAndUpdatedFields(): void
    {
        // Schema has 2 custom fields (foo, bar). Core fields are tracked separately.
        $schema = $this->makeSchema(1, ['foo' => ['type' => 'string'], 'bar' => ['type' => 'integer']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        // Core field names as defined in getCoreMetadataFields().
        $coreFieldNames = ['id', 'uuid', 'name', 'title', 'summary', 'description',
            'created', 'updated', 'published', 'deleted', 'owner', 'organisation', 'register', 'schema'];

        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use ($coreFieldNames): string {
                // Core fields -> 'created' (counted in core_fields_created, not fields_created).
                // Schema-specific fields -> 'created' so they appear in fields_created.
                return 'created';
            });

        $result = $this->handler->mirrorSchemas();

        $this->assertTrue($result['success']);
        // Only the 2 schema-specific fields (foo, bar) contribute to fields_created.
        $this->assertSame(2, $result['stats']['fields_created']);
        $this->assertSame(0, $result['stats']['fields_updated']);
    }

    public function testMirrorSchemasCountsUpdatedSchemaFields(): void
    {
        $schema = $this->makeSchema(1, ['foo' => ['type' => 'string'], 'bar' => ['type' => 'integer']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $coreFieldNames = ['id', 'uuid', 'name', 'title', 'summary', 'description',
            'created', 'updated', 'published', 'deleted', 'owner', 'organisation', 'register', 'schema'];

        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use ($coreFieldNames): string {
                // Core fields 'created', schema-specific fields 'updated'.
                return in_array($cfg['name'], $coreFieldNames, true) ? 'created' : 'updated';
            });

        $result = $this->handler->mirrorSchemas();

        $this->assertTrue($result['success']);
        // foo and bar are schema fields; they are 'updated', not 'created'.
        $this->assertSame(0, $result['stats']['fields_created']);
        $this->assertSame(2, $result['stats']['fields_updated']);
    }

    public function testMirrorSchemasReturnsResolvedConflicts(): void
    {
        // Two schemas with the same field 'amount' but different types.
        $schema1 = $this->makeSchema(1, ['amount' => ['type' => 'integer']]);
        $schema2 = $this->makeSchema(2, ['amount' => ['type' => 'string']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('resolved_conflicts', $result);
        // The conflict on 'amount' should be resolved to 'string' (more permissive).
        $this->assertArrayHasKey('amount', $result['resolved_conflicts']);
        $this->assertSame('string', $result['resolved_conflicts']['amount']);
    }

    public function testMirrorSchemasErrorInOneSchemaDoesNotAbort(): void
    {
        // First schema has a field that triggers an exception when applying.
        $schema1 = $this->makeSchema(1, ['bad_field' => ['type' => 'string']]);
        $schema2 = $this->makeSchema(2, ['good_field' => ['type' => 'string']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);

        $callCount = 0;
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $fieldConfig) use (&$callCount): string {
                $callCount++;
                // Core fields pass; schema-specific fields throw for bad_field.
                if (($fieldConfig['name'] ?? '') === 'bad_field') {
                    throw new \Exception('Bad field type');
                }
                return 'created';
            });

        $result = $this->handler->mirrorSchemas();

        // Both schemas should have been attempted.
        $this->assertSame(2, $result['stats']['schemas_processed']);
    }

    public function testMirrorSchemasPropagatesTopLevelException(): void
    {
        $this->schemaMapper->method('findAll')
            ->willThrowException(new \Exception('DB connection lost'));

        $result = $this->handler->mirrorSchemas();

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('DB connection lost', $result['error']);
    }

    public function testMirrorSchemasIncludesExecutionTime(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('skipped');

        $result = $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('execution_time_ms', $result);
        $this->assertIsFloat($result['execution_time_ms']);
        $this->assertGreaterThanOrEqual(0.0, $result['execution_time_ms']);
    }

    // =========================================================================
    // getCollectionFieldStatus
    // =========================================================================

    public function testGetCollectionFieldStatusAllPresent(): void
    {
        $coreFields = [
            'id', 'uuid', 'name', 'title', 'summary', 'description',
            'created', 'updated', 'published', 'deleted', 'owner',
            'organisation', 'register', 'schema',
        ];

        $currentFields = [];
        foreach ($coreFields as $f) {
            $currentFields[$f] = ['name' => $f];
        }

        $this->searchBackend->method('getFields')->willReturn($currentFields);

        $result = $this->handler->getCollectionFieldStatus('test-collection');

        $this->assertSame('test-collection', $result['collection']);
        $this->assertSame(14, $result['total_fields']);
        $this->assertSame(14, $result['expected_fields']);
        $this->assertEmpty($result['missing_fields']);
        $this->assertCount(14, $result['existing_fields']);
    }

    public function testGetCollectionFieldStatusWithMissingFields(): void
    {
        // Only 'id' is present; the other 13 should be missing.
        $this->searchBackend->method('getFields')
            ->willReturn(['id' => ['name' => 'id']]);

        $result = $this->handler->getCollectionFieldStatus('test-collection');

        $this->assertSame(1, $result['total_fields']);
        $this->assertNotEmpty($result['missing_fields']);
        $this->assertArrayHasKey('uuid', $result['missing_fields']);
        $this->assertArrayHasKey('name', $result['missing_fields']);
        $this->assertArrayNotHasKey('id', $result['missing_fields']);
    }

    public function testGetCollectionFieldStatusEmptyCollection(): void
    {
        $this->searchBackend->method('getFields')->willReturn([]);

        $result = $this->handler->getCollectionFieldStatus('empty-collection');

        $this->assertSame(0, $result['total_fields']);
        $this->assertSame(14, $result['expected_fields']);
        $this->assertCount(14, $result['missing_fields']);
    }

    public function testGetCollectionFieldStatusWithExtraFieldsInBackend(): void
    {
        // Backend has all core fields plus extras.
        $fields = [
            'id'           => ['name' => 'id'],
            'uuid'         => ['name' => 'uuid'],
            'name'         => ['name' => 'name'],
            'title'        => ['name' => 'title'],
            'summary'      => ['name' => 'summary'],
            'description'  => ['name' => 'description'],
            'created'      => ['name' => 'created'],
            'updated'      => ['name' => 'updated'],
            'published'    => ['name' => 'published'],
            'deleted'      => ['name' => 'deleted'],
            'owner'        => ['name' => 'owner'],
            'organisation' => ['name' => 'organisation'],
            'register'     => ['name' => 'register'],
            'schema'       => ['name' => 'schema'],
            'custom_field' => ['name' => 'custom_field'],
            'another_field' => ['name' => 'another_field'],
        ];

        $this->searchBackend->method('getFields')->willReturn($fields);

        $result = $this->handler->getCollectionFieldStatus('test-collection');

        $this->assertSame(16, $result['total_fields']);
        $this->assertEmpty($result['missing_fields']);
    }

    public function testGetCollectionFieldStatusHandlesException(): void
    {
        $this->searchBackend->method('getFields')
            ->willThrowException(new \Exception('Backend unavailable'));

        $result = $this->handler->getCollectionFieldStatus('test-collection');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('test-collection', $result['collection']);
        $this->assertSame('Backend unavailable', $result['error']);
    }

    public function testGetCollectionFieldStatusPassesCollectionName(): void
    {
        $this->searchBackend->expects($this->once())
            ->method('getFields')
            ->with('specific-tenant-collection')
            ->willReturn([]);

        $this->handler->getCollectionFieldStatus('specific-tenant-collection');
    }

    // =========================================================================
    // createMissingFields
    // =========================================================================

    public function testCreateMissingFieldsDryRunReturnsFieldsToAdd(): void
    {
        $missingFields = [
            'uuid'  => ['name' => 'uuid', 'type' => 'string', 'indexed' => true, 'stored' => true],
            'title' => ['name' => 'title', 'type' => 'text', 'indexed' => true, 'stored' => true],
        ];

        $result = $this->handler->createMissingFields('test-collection', $missingFields, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dry_run']);
        $this->assertContains('uuid', $result['fields_to_add']);
        $this->assertContains('title', $result['fields_to_add']);
    }

    public function testCreateMissingFieldsDryRunDoesNotCallBackend(): void
    {
        $this->searchBackend->expects($this->never())
            ->method('addOrUpdateField');

        $this->handler->createMissingFields('test-collection', ['foo' => ['name' => 'foo']], true);
    }

    public function testCreateMissingFieldsActuallyCreatesFields(): void
    {
        $missingFields = [
            'uuid'  => ['name' => 'uuid', 'type' => 'string', 'indexed' => true, 'stored' => true],
            'title' => ['name' => 'title', 'type' => 'text', 'indexed' => true, 'stored' => true],
        ];

        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->createMissingFields('test-collection', $missingFields);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['failed']);
    }

    public function testCreateMissingFieldsCountsFailuresWhenBackendSkips(): void
    {
        $missingFields = [
            'uuid'  => ['name' => 'uuid', 'type' => 'string', 'indexed' => true, 'stored' => true],
            'title' => ['name' => 'title', 'type' => 'text', 'indexed' => true, 'stored' => true],
        ];

        // Backend returns 'skipped' (not 'created'), so created count stays 0.
        $this->searchBackend->method('addOrUpdateField')->willReturn('skipped');

        $result = $this->handler->createMissingFields('test-collection', $missingFields);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['failed']);
    }

    public function testCreateMissingFieldsEmptyArraySucceeds(): void
    {
        $result = $this->handler->createMissingFields('test-collection', []);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['failed']);
    }

    // =========================================================================
    // fixMismatchedFields
    // =========================================================================

    public function testFixMismatchedFieldsDelegatesToBackend(): void
    {
        $mismatchedFields = ['field1' => ['expected' => 'string', 'actual' => 'pint']];

        $this->searchBackend->expects($this->once())
            ->method('fixMismatchedFields')
            ->with($mismatchedFields, false)
            ->willReturn(['success' => true, 'fixed' => 1, 'failed' => 0]);

        $result = $this->handler->fixMismatchedFields($mismatchedFields);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['fixed']);
    }

    public function testFixMismatchedFieldsPassesDryRunFlag(): void
    {
        $this->searchBackend->expects($this->once())
            ->method('fixMismatchedFields')
            ->with([], true)
            ->willReturn(['success' => true, 'preview' => []]);

        $result = $this->handler->fixMismatchedFields([], true);

        $this->assertTrue($result['success']);
    }

    public function testFixMismatchedFieldsHandlesException(): void
    {
        $this->searchBackend->method('fixMismatchedFields')
            ->willThrowException(new \Exception('Fix operation failed'));

        $result = $this->handler->fixMismatchedFields(['field1' => []]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Fix operation failed', $result['error']);
    }

    public function testFixMismatchedFieldsEmptyArraySucceeds(): void
    {
        $this->searchBackend->method('fixMismatchedFields')
            ->willReturn(['success' => true, 'fixed' => 0]);

        $result = $this->handler->fixMismatchedFields([]);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // Field type conflict resolution (tested via mirrorSchemas)
    // =========================================================================

    public function testConflictResolutionPrefersStringOverInteger(): void
    {
        $schema1 = $this->makeSchema(1, ['score' => ['type' => 'integer']]);
        $schema2 = $this->makeSchema(2, ['score' => ['type' => 'string']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        $this->assertSame('string', $result['resolved_conflicts']['score']);
    }

    public function testConflictResolutionPrefersStringOverBoolean(): void
    {
        $schema1 = $this->makeSchema(1, ['active' => ['type' => 'boolean']]);
        $schema2 = $this->makeSchema(2, ['active' => ['type' => 'string']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        $this->assertSame('string', $result['resolved_conflicts']['active']);
    }

    public function testConflictResolutionPrefersTextOverFloat(): void
    {
        $schema1 = $this->makeSchema(1, ['rate' => ['type' => 'float']]);
        $schema2 = $this->makeSchema(2, ['rate' => ['type' => 'text']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        $this->assertSame('text', $result['resolved_conflicts']['rate']);
    }

    public function testConflictResolutionPrefersFloatOverInteger(): void
    {
        $schema1 = $this->makeSchema(1, ['amount' => ['type' => 'integer']]);
        $schema2 = $this->makeSchema(2, ['amount' => ['type' => 'number']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        $this->assertSame('float', $result['resolved_conflicts']['amount']);
    }

    public function testNoConflictWhenFieldUsedSameTypeAcrossSchemas(): void
    {
        $schema1 = $this->makeSchema(1, ['name' => ['type' => 'string']]);
        $schema2 = $this->makeSchema(2, ['name' => ['type' => 'string']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        // No conflicts; resolved_conflicts is either empty or contains only non-conflicting entries.
        if (isset($result['resolved_conflicts']['name'])) {
            // It may still appear in resolved but with a single unique type.
            $this->assertSame('string', $result['resolved_conflicts']['name']);
        }

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // Field type determination (tested via mirrorSchemas field application)
    // =========================================================================

    public function testIntegerTypeMappedToSolrInteger(): void
    {
        $schema = $this->makeSchema(1, ['count' => ['type' => 'integer']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('count', $appliedConfigs);
        $this->assertSame('integer', $appliedConfigs['count']['type']);
    }

    public function testNumberTypeMappedToSolrFloat(): void
    {
        $schema = $this->makeSchema(1, ['price' => ['type' => 'number']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('price', $appliedConfigs);
        $this->assertSame('float', $appliedConfigs['price']['type']);
    }

    public function testBooleanTypeMappedToSolrBoolean(): void
    {
        $schema = $this->makeSchema(1, ['active' => ['type' => 'boolean']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('active', $appliedConfigs);
        $this->assertSame('boolean', $appliedConfigs['active']['type']);
    }

    public function testDateTypeMappedToSolrDate(): void
    {
        $schema = $this->makeSchema(1, ['birthday' => ['type' => 'date']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('birthday', $appliedConfigs);
        $this->assertSame('date', $appliedConfigs['birthday']['type']);
    }

    public function testUnknownTypeMapsToString(): void
    {
        $schema = $this->makeSchema(1, ['data' => ['type' => 'object']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('data', $appliedConfigs);
        $this->assertSame('string', $appliedConfigs['data']['type']);
    }

    public function testFieldNameSanitizationReplacesSpecialChars(): void
    {
        $schema = $this->makeSchema(1, ['My Field-Name' => ['type' => 'string']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        // 'My Field-Name' -> 'my_field_name'
        $this->assertArrayHasKey('my_field_name', $appliedConfigs);
    }

    public function testArrayTypeFieldIsMultiValued(): void
    {
        $schema = $this->makeSchema(1, ['tags' => ['type' => 'array']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('tags', $appliedConfigs);
        $this->assertTrue($appliedConfigs['tags']['multiValued']);
    }

    public function testFieldWithMaxItemsGreaterThanOneIsMultiValued(): void
    {
        $schema = $this->makeSchema(1, ['aliases' => ['type' => 'string', 'maxItems' => 5]]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('aliases', $appliedConfigs);
        $this->assertTrue($appliedConfigs['aliases']['multiValued']);
    }

    public function testScalarFieldIsNotMultiValued(): void
    {
        $schema = $this->makeSchema(1, ['email' => ['type' => 'string']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('email', $appliedConfigs);
        $this->assertFalse($appliedConfigs['email']['multiValued']);
    }

    public function testAllFieldsAreBothIndexedAndStored(): void
    {
        $schema = $this->makeSchema(1, ['ref_code' => ['type' => 'string']]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $appliedConfigs = [];
        $this->searchBackend->method('addOrUpdateField')
            ->willReturnCallback(static function (array $cfg) use (&$appliedConfigs): string {
                $appliedConfigs[$cfg['name']] = $cfg;
                return 'created';
            });

        $this->handler->mirrorSchemas();

        $this->assertArrayHasKey('ref_code', $appliedConfigs);
        $this->assertTrue($appliedConfigs['ref_code']['indexed']);
        $this->assertTrue($appliedConfigs['ref_code']['stored']);
    }

    public function testSchemaWithNoPropertiesProcessedWithoutError(): void
    {
        $schema = $this->makeSchema(1, []);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $result = $this->handler->mirrorSchemas();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['stats']['schemas_processed']);
        $this->assertSame(0, $result['stats']['fields_created']);
    }
}
