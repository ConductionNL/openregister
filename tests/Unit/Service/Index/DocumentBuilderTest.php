<?php

declare(strict_types=1);

/**
 * DocumentBuilder Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Index
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Index;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Index\DocumentBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DocumentBuilder
 *
 * Tests pure-logic methods for Solr document creation, field mapping, and value conversion.
 */
class DocumentBuilderTest extends TestCase
{
    /** @var DocumentBuilder */
    private DocumentBuilder $documentBuilder;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->documentBuilder = new DocumentBuilder($this->logger);
    }

    // =========================================================================
    // flattenRelationsForSolr
    // =========================================================================

    public function testFlattenRelationsForSolrEmptyInput(): void
    {
        $this->assertSame([], $this->documentBuilder->flattenRelationsForSolr([]));
        $this->assertSame([], $this->documentBuilder->flattenRelationsForSolr(null));
        $this->assertSame([], $this->documentBuilder->flattenRelationsForSolr(''));
    }

    public function testFlattenRelationsForSolrStringValues(): void
    {
        $relations = [
            'modules.0' => 'uuid-1',
            'modules.1' => 'uuid-2',
            'other.0'   => 'uuid-3',
        ];

        $result = $this->documentBuilder->flattenRelationsForSolr($relations);

        $this->assertSame(['uuid-1', 'uuid-2', 'uuid-3'], $result);
    }

    public function testFlattenRelationsForSolrNumericValues(): void
    {
        $relations = ['field.0' => 42, 'field.1' => 3.14];

        $result = $this->documentBuilder->flattenRelationsForSolr($relations);

        $this->assertSame(['42', '3.14'], $result);
    }

    public function testFlattenRelationsForSolrSkipsArrayValues(): void
    {
        $relations = [
            'good.0' => 'value',
            'bad.0'  => ['nested' => 'array'],
            'good.1' => 'value2',
        ];

        $result = $this->documentBuilder->flattenRelationsForSolr($relations);

        $this->assertSame(['value', 'value2'], $result);
    }

    public function testFlattenRelationsForSolrSingleStringValue(): void
    {
        $result = $this->documentBuilder->flattenRelationsForSolr('single-value');

        $this->assertSame(['single-value'], $result);
    }

    public function testFlattenRelationsForSolrSingleNumericValue(): void
    {
        $result = $this->documentBuilder->flattenRelationsForSolr(99);

        $this->assertSame(['99'], $result);
    }

    // =========================================================================
    // flattenFilesForSolr
    // =========================================================================

    public function testFlattenFilesForSolrEmptyInput(): void
    {
        $this->assertSame([], $this->documentBuilder->flattenFilesForSolr([]));
        $this->assertSame([], $this->documentBuilder->flattenFilesForSolr(null));
        $this->assertSame([], $this->documentBuilder->flattenFilesForSolr(''));
    }

    public function testFlattenFilesForSolrStringArray(): void
    {
        $files = ['file1.pdf', 'file2.doc'];

        $result = $this->documentBuilder->flattenFilesForSolr($files);

        $this->assertSame(['file1.pdf', 'file2.doc'], $result);
    }

    public function testFlattenFilesForSolrObjectsWithId(): void
    {
        $files = [
            ['id' => 123, 'name' => 'file1.pdf'],
            ['uuid' => 'abc-def', 'name' => 'file2.doc'],
        ];

        $result = $this->documentBuilder->flattenFilesForSolr($files);

        $this->assertSame(['123', 'abc-def'], $result);
    }

    public function testFlattenFilesForSolrSingleString(): void
    {
        $result = $this->documentBuilder->flattenFilesForSolr('single-file');

        $this->assertSame(['single-file'], $result);
    }

    // =========================================================================
    // extractIdFromObject
    // =========================================================================

    public function testExtractIdFromObjectWithId(): void
    {
        $this->assertSame('123', $this->documentBuilder->extractIdFromObject(['id' => '123']));
    }

    public function testExtractIdFromObjectWithUuid(): void
    {
        $this->assertSame('abc-def', $this->documentBuilder->extractIdFromObject(['uuid' => 'abc-def']));
    }

    public function testExtractIdFromObjectWithIdentifier(): void
    {
        $this->assertSame('ident', $this->documentBuilder->extractIdFromObject(['identifier' => 'ident']));
    }

    public function testExtractIdFromObjectReturnsNullWhenNoMatch(): void
    {
        $this->assertNull($this->documentBuilder->extractIdFromObject(['name' => 'test']));
    }

    public function testExtractIdFromObjectPrefersIdOverUuid(): void
    {
        $result = $this->documentBuilder->extractIdFromObject(['id' => 'the-id', 'uuid' => 'the-uuid']);

        $this->assertSame('the-id', $result);
    }

    // =========================================================================
    // extractArraysFromRelations
    // =========================================================================

    public function testExtractArraysFromRelations(): void
    {
        $relations = [
            'standaarden.0' => 'val-a',
            'standaarden.2' => 'val-c',
            'standaarden.1' => 'val-b',
            'categories.0'  => 'cat-1',
        ];

        $result = $this->documentBuilder->extractArraysFromRelations($relations);

        $this->assertSame(['val-a', 'val-b', 'val-c'], $result['standaarden']);
        $this->assertSame(['cat-1'], $result['categories']);
    }

    public function testExtractArraysFromRelationsSkipsNonDotNotation(): void
    {
        $relations = [
            'simple'        => 'value',
            'dotted.0'      => 'val-0',
            'nested.name'   => 'non-numeric-index',
        ];

        $result = $this->documentBuilder->extractArraysFromRelations($relations);

        // 'simple' has no dot, should not appear.
        $this->assertArrayNotHasKey('simple', $result);
        $this->assertSame(['val-0'], $result['dotted']);
        // 'nested' has non-numeric index - key exists but empty after re-index.
        $this->assertArrayHasKey('nested', $result);
    }

    // =========================================================================
    // extractIndexableArrayValues
    // =========================================================================

    public function testExtractIndexableArrayValuesStrings(): void
    {
        $result = $this->documentBuilder->extractIndexableArrayValues(['a', 'b', 'c'], 'field');

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testExtractIndexableArrayValuesObjectsWithId(): void
    {
        $result = $this->documentBuilder->extractIndexableArrayValues(
            [['id' => 'obj-1'], ['uuid' => 'obj-2']],
            'field'
        );

        $this->assertSame(['obj-1', 'obj-2'], $result);
    }

    public function testExtractIndexableArrayValuesScalars(): void
    {
        $result = $this->documentBuilder->extractIndexableArrayValues([42, true, 3.14], 'field');

        $this->assertSame(['42', '1', '3.14'], $result);
    }

    public function testExtractIndexableArrayValuesSkipsNull(): void
    {
        $result = $this->documentBuilder->extractIndexableArrayValues([null, 'valid', null], 'field');

        $this->assertSame(['valid'], $result);
    }

    // =========================================================================
    // mapFieldToSolrType
    // =========================================================================

    public function testMapFieldToSolrTypeReservedFields(): void
    {
        $this->assertNull($this->documentBuilder->mapFieldToSolrType('id', 'string', 'val'));
        $this->assertNull($this->documentBuilder->mapFieldToSolrType('tenant_id', 'string', 'val'));
        $this->assertNull($this->documentBuilder->mapFieldToSolrType('_version_', 'string', 'val'));
    }

    public function testMapFieldToSolrTypeSelfPrefixSkipped(): void
    {
        $this->assertNull($this->documentBuilder->mapFieldToSolrType('self_register', 'string', 'val'));
    }

    public function testMapFieldToSolrTypeNormalField(): void
    {
        $this->assertSame('title', $this->documentBuilder->mapFieldToSolrType('title', 'string', 'val'));
        $this->assertSame('count', $this->documentBuilder->mapFieldToSolrType('count', 'integer', 5));
    }

    // =========================================================================
    // convertValueForSolr
    // =========================================================================

    public function testConvertValueForSolrNull(): void
    {
        $this->assertNull($this->documentBuilder->convertValueForSolr(null, 'string'));
    }

    public function testConvertValueForSolrInteger(): void
    {
        $this->assertSame(42, $this->documentBuilder->convertValueForSolr('42', 'integer'));
        $this->assertSame(42, $this->documentBuilder->convertValueForSolr('42', 'int'));
        $this->assertNull($this->documentBuilder->convertValueForSolr('not-a-number', 'integer'));
    }

    public function testConvertValueForSolrFloat(): void
    {
        $this->assertSame(3.14, $this->documentBuilder->convertValueForSolr('3.14', 'float'));
        $this->assertSame(2.0, $this->documentBuilder->convertValueForSolr('2', 'double'));
        $this->assertNull($this->documentBuilder->convertValueForSolr('abc', 'number'));
    }

    public function testConvertValueForSolrBoolean(): void
    {
        $this->assertTrue($this->documentBuilder->convertValueForSolr(1, 'boolean'));
        $this->assertFalse($this->documentBuilder->convertValueForSolr(0, 'bool'));
        $this->assertTrue($this->documentBuilder->convertValueForSolr('yes', 'boolean'));
    }

    public function testConvertValueForSolrDate(): void
    {
        $dt = new \DateTime('2024-01-15 10:30:00');
        $result = $this->documentBuilder->convertValueForSolr($dt, 'date');
        $this->assertSame('2024-01-15T10:30:00Z', $result);

        // String date.
        $result = $this->documentBuilder->convertValueForSolr('2024-01-15 10:30:00', 'datetime');
        $this->assertSame('2024-01-15T10:30:00Z', $result);
    }

    public function testConvertValueForSolrArray(): void
    {
        $this->assertSame(['a', 'b'], $this->documentBuilder->convertValueForSolr(['a', 'b'], 'array'));
        $this->assertSame(['single'], $this->documentBuilder->convertValueForSolr('single', 'array'));
    }

    public function testConvertValueForSolrDefault(): void
    {
        $this->assertSame('42', $this->documentBuilder->convertValueForSolr(42, 'unknown'));
    }

    // =========================================================================
    // truncateFieldValue
    // =========================================================================

    public function testTruncateFieldValueShortString(): void
    {
        $result = $this->documentBuilder->truncateFieldValue('short string', 'field');
        $this->assertSame('short string', $result);
    }

    public function testTruncateFieldValueNonString(): void
    {
        $this->assertSame(42, $this->documentBuilder->truncateFieldValue(42, 'field'));
        $this->assertSame(['a'], $this->documentBuilder->truncateFieldValue(['a'], 'field'));
    }

    public function testTruncateFieldValueLongString(): void
    {
        $longString = str_repeat('x', 40000);
        $result = $this->documentBuilder->truncateFieldValue($longString, 'field');

        $this->assertStringEndsWith('...[TRUNCATED]', $result);
        $this->assertLessThan(40000, strlen($result));
    }

    // =========================================================================
    // shouldTruncateField
    // =========================================================================

    public function testShouldTruncateFieldFileType(): void
    {
        $this->assertTrue($this->documentBuilder->shouldTruncateField('myFile', ['type' => 'file']));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('myFile', ['format' => 'binary']));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('myFile', ['format' => 'base64']));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('myFile', ['format' => 'data-url']));
    }

    public function testShouldTruncateFieldLargeContentFields(): void
    {
        $this->assertTrue($this->documentBuilder->shouldTruncateField('logo', []));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('image', []));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('content', []));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('DESCRIPTION', []));
    }

    public function testShouldTruncateFieldBase64InName(): void
    {
        $this->assertTrue($this->documentBuilder->shouldTruncateField('imageBase64Data', []));
    }

    public function testShouldNotTruncateRegularField(): void
    {
        $this->assertFalse($this->documentBuilder->shouldTruncateField('title', []));
        $this->assertFalse($this->documentBuilder->shouldTruncateField('name', ['type' => 'string']));
    }

    // =========================================================================
    // validateFieldForSolr
    // =========================================================================

    public function testValidateFieldForSolrNoFieldTypes(): void
    {
        $this->assertTrue($this->documentBuilder->validateFieldForSolr('field', 'value', []));
    }

    public function testValidateFieldForSolrUnknownField(): void
    {
        $this->assertTrue(
            $this->documentBuilder->validateFieldForSolr('new_field', 'value', ['existing' => 'string'])
        );
    }

    public function testValidateFieldForSolrCompatibleType(): void
    {
        $this->assertTrue(
            $this->documentBuilder->validateFieldForSolr('count', 42, ['count' => 'pint'])
        );
    }

    public function testValidateFieldForSolrIncompatibleType(): void
    {
        $this->assertFalse(
            $this->documentBuilder->validateFieldForSolr('count', 'not-a-number', ['count' => 'pint'])
        );
    }

    // =========================================================================
    // isValueCompatibleWithSolrType
    // =========================================================================

    public function testIsValueCompatibleWithSolrTypeNull(): void
    {
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType(null, 'pint'));
    }

    public function testIsValueCompatibleWithSolrTypeNumeric(): void
    {
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType(42, 'pint'));
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType('3.14', 'pfloat'));
        $this->assertFalse($this->documentBuilder->isValueCompatibleWithSolrType('abc', 'plong'));
    }

    public function testIsValueCompatibleWithSolrTypeString(): void
    {
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType('anything', 'string'));
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType(42, 'text_general'));
    }

    public function testIsValueCompatibleWithSolrTypeBoolean(): void
    {
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType(true, 'boolean'));
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType('true', 'boolean'));
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType('0', 'boolean'));
    }

    public function testIsValueCompatibleWithSolrTypeArray(): void
    {
        // Empty array is always compatible.
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType([], 'pint'));
        // Array of compatible values.
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType([1, 2, 3], 'pint'));
        // Array with incompatible value.
        $this->assertFalse($this->documentBuilder->isValueCompatibleWithSolrType([1, 'abc'], 'pint'));
    }

    // =========================================================================
    // resolveRegisterToId / resolveSchemaToId
    // =========================================================================

    public function testResolveRegisterToIdEmpty(): void
    {
        $this->assertSame(0, $this->documentBuilder->resolveRegisterToId(''));
        $this->assertSame(0, $this->documentBuilder->resolveRegisterToId(null));
    }

    public function testResolveRegisterToIdNumeric(): void
    {
        $this->assertSame(42, $this->documentBuilder->resolveRegisterToId(42));
        $this->assertSame(42, $this->documentBuilder->resolveRegisterToId('42'));
    }

    public function testResolveSchemaToIdEmpty(): void
    {
        $this->assertSame(0, $this->documentBuilder->resolveSchemaToId(''));
        $this->assertSame(0, $this->documentBuilder->resolveSchemaToId(null));
    }

    public function testResolveSchemaToIdNumeric(): void
    {
        $this->assertSame(7, $this->documentBuilder->resolveSchemaToId(7));
        $this->assertSame(7, $this->documentBuilder->resolveSchemaToId('7'));
    }

    public function testResolveRegisterToIdWithEntity(): void
    {
        $register = new \OCA\OpenRegister\Db\Register();
        $reflection = new \ReflectionClass($register);
        $prop = $reflection->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, 99);

        $this->assertSame(99, $this->documentBuilder->resolveRegisterToId('slug', $register));
    }

    public function testResolveSchemaToIdWithEntity(): void
    {
        $schema = new \OCA\OpenRegister\Db\Schema();
        $reflection = new \ReflectionClass($schema);
        $prop = $reflection->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, 55);

        $this->assertSame(55, $this->documentBuilder->resolveSchemaToId('slug', $schema));
    }

    // =========================================================================
    // createDocument
    // =========================================================================

    public function testCreateDocument(): void
    {
        $object = new ObjectEntity();
        $reflection = new \ReflectionClass($object);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($object, 1);

        $object->setUuid('test-uuid-123');
        $object->setSchema('5');
        $object->setRegister('3');
        $object->setObject(['title' => 'Test', 'count' => 42]);

        $doc = $this->documentBuilder->createDocument($object);

        $this->assertSame('test-uuid-123', $doc['id']);
        $this->assertSame(1, $doc['object_id']);
        $this->assertSame('test-uuid-123', $doc['uuid']);
        $this->assertSame('Test', $doc['title']);
        $this->assertSame('42', $doc['count']);
        $this->assertArrayHasKey('_text', $doc);
    }

    public function testCreateDocumentSkipsNullValues(): void
    {
        $object = new ObjectEntity();
        $reflection = new \ReflectionClass($object);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($object, 2);

        $object->setUuid('uuid-2');
        $object->setSchema('1');
        $object->setRegister('1');
        $object->setObject(['title' => 'Test', 'empty' => null]);

        $doc = $this->documentBuilder->createDocument($object);

        $this->assertArrayNotHasKey('empty', $doc);
        $this->assertArrayHasKey('title', $doc);
    }

    // =========================================================================
    // Additional coverage: convertValueForSolr edge cases
    // =========================================================================

    public function testConvertValueForSolrDateNonParseable(): void
    {
        // An unparseable date string should be returned as-is.
        $result = $this->documentBuilder->convertValueForSolr('not-a-date', 'datetime');
        $this->assertSame('not-a-date', $result);
    }

    public function testConvertValueForSolrDateNonString(): void
    {
        // Non-string, non-DateTime for date type — returned as-is.
        $result = $this->documentBuilder->convertValueForSolr(12345, 'date');
        $this->assertSame(12345, $result);
    }

    public function testConvertValueForSolrArrayWrapsScalar(): void
    {
        // Non-array value for 'array' type should be wrapped.
        $result = $this->documentBuilder->convertValueForSolr('item', 'array');
        $this->assertSame(['item'], $result);
    }

    public function testConvertValueForSolrIntegerNonNumericReturnsNull(): void
    {
        $this->assertNull($this->documentBuilder->convertValueForSolr('abc', 'int'));
    }

    public function testConvertValueForSolrFloatNonNumericReturnsNull(): void
    {
        $this->assertNull($this->documentBuilder->convertValueForSolr('xyz', 'float'));
    }

    // =========================================================================
    // isValueCompatibleWithSolrType — pdate
    // =========================================================================

    public function testIsValueCompatibleWithSolrTypePdate(): void
    {
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType('2024-01-01', 'pdate'));
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType(new \DateTime(), 'pdates'));
        // Integer is not compatible with date.
        $this->assertFalse($this->documentBuilder->isValueCompatibleWithSolrType(12345, 'pdate'));
    }

    public function testIsValueCompatibleWithSolrTypeUnknownAllowsAll(): void
    {
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType('anything', 'custom_type'));
        $this->assertTrue($this->documentBuilder->isValueCompatibleWithSolrType(99, 'my_type'));
    }

    // =========================================================================
    // resolveRegisterToId — non-resolvable slug returns 0
    // =========================================================================

    public function testResolveRegisterToIdNonResolvableReturnsZero(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $registerMapper = $this->createMock(\OCA\OpenRegister\Db\RegisterMapper::class);
        $registerMapper->method('find')->willThrowException(new \Exception('not found'));

        $builder = new DocumentBuilder($logger, $schemaMapper, $registerMapper);

        $result = $builder->resolveRegisterToId('unknown-slug');
        $this->assertSame(0, $result);
    }

    public function testResolveSchemaToIdNonResolvableReturnsZero(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('find')->willThrowException(new \Exception('not found'));

        $builder = new DocumentBuilder($logger, $schemaMapper, null);

        $result = $builder->resolveSchemaToId('unknown-slug');
        $this->assertSame(0, $result);
    }

    public function testResolveRegisterToIdNoMapperReturnsZero(): void
    {
        $builder = new DocumentBuilder($this->logger, null, null);
        $result = $builder->resolveRegisterToId('some-slug');
        $this->assertSame(0, $result);
    }

    public function testResolveSchemaToIdNoMapperReturnsZero(): void
    {
        $builder = new DocumentBuilder($this->logger, null, null);
        $result = $builder->resolveSchemaToId('some-slug');
        $this->assertSame(0, $result);
    }

    // =========================================================================
    // flattenFilesForSolr — array without id or uuid
    // =========================================================================

    public function testFlattenFilesForSolrSkipsArraysWithoutIdOrUuid(): void
    {
        $files = [
            ['name' => 'file.pdf'],   // No id or uuid — skipped.
            'string-file',
        ];

        $result = $this->documentBuilder->flattenFilesForSolr($files);
        $this->assertSame(['string-file'], $result);
    }

    public function testFlattenFilesForSolrNonStringNonArrayReturnsEmpty(): void
    {
        // An integer is not empty but also not a string.
        $result = $this->documentBuilder->flattenFilesForSolr(0);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // extractArraysFromRelations — re-indexing
    // =========================================================================

    public function testExtractArraysFromRelationsReindexesSequentially(): void
    {
        $relations = [
            'items.5' => 'val-e',
            'items.2' => 'val-b',
            'items.0' => 'val-a',
        ];

        $result = $this->documentBuilder->extractArraysFromRelations($relations);

        // After ksort + array_values the result should be ['val-a', 'val-b', 'val-e'].
        $this->assertSame(['val-a', 'val-b', 'val-e'], $result['items']);
    }

    // =========================================================================
    // shouldTruncateField — image/document formats
    // =========================================================================

    public function testShouldTruncateFieldImageAndDocumentFormats(): void
    {
        $this->assertTrue($this->documentBuilder->shouldTruncateField('f', ['format' => 'image']));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('f', ['format' => 'document']));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('thumbnail', []));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('body', []));
        $this->assertTrue($this->documentBuilder->shouldTruncateField('icon', []));
    }

    // =========================================================================
    // createDocument with created/updated dates
    // =========================================================================

    public function testCreateDocumentIncludesCreatedAndUpdatedDates(): void
    {
        $object = new ObjectEntity();
        $reflection = new \ReflectionClass($object);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($object, 3);

        $object->setUuid('uuid-3');
        $object->setSchema('1');
        $object->setRegister('1');
        $object->setObject([]);
        $object->setCreated(new \DateTime('2024-01-01 00:00:00'));
        $object->setUpdated(new \DateTime('2024-06-01 00:00:00'));

        $doc = $this->documentBuilder->createDocument($object);

        $this->assertSame('2024-01-01T00:00:00Z', $doc['created']);
        $this->assertSame('2024-06-01T00:00:00Z', $doc['updated']);
    }
}
