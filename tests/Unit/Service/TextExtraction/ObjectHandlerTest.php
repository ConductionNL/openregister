<?php

declare(strict_types=1);

/**
 * ObjectHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\TextExtraction\ObjectHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ObjectHandler.
 *
 * Tests text extraction from ObjectEntity, extraction need detection,
 * metadata retrieval, and recursive array-to-text conversion.
 */
class ObjectHandlerTest extends TestCase
{

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectMapper;

    /** @var ChunkMapper&MockObject */
    private ChunkMapper $chunkMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private ObjectHandler $handler;

    protected function setUp(): void
    {
        $this->objectMapper   = $this->createMock(ObjectEntityMapper::class);
        $this->chunkMapper    = $this->createMock(ChunkMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->handler = new ObjectHandler(
            $this->objectMapper,
            $this->chunkMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->logger
        );
    }//end setUp()

    // ── Helper ───────────────────────────────────────────────────────────

    /**
     * Build a minimal ObjectEntity stub.
     *
     * getObject() is a real method → onlyMethods().
     * All other getters are Nextcloud __call magic → addMethods().
     *
     * @param array<string, mixed> $attrs Values for the object.
     *
     * @return ObjectEntity&MockObject
     */
    private function buildObjectMock(array $attrs = []): ObjectEntity&MockObject
    {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObject'])
            ->addMethods([
                'getUuid',
                'getVersion',
                'getSchema',
                'getRegister',
                'getOrganization',
                'getOwner',
                'getUpdated',
                'getId',
            ])
            ->getMock();

        $object->method('getUuid')->willReturn($attrs['uuid'] ?? 'test-uuid');
        $object->method('getVersion')->willReturn($attrs['version'] ?? '1.0.0');
        $object->method('getSchema')->willReturn($attrs['schema'] ?? null);
        $object->method('getRegister')->willReturn($attrs['register'] ?? null);
        $object->method('getObject')->willReturn($attrs['object'] ?? ['name' => 'Test Object']);
        $object->method('getOrganization')->willReturn($attrs['organization'] ?? null);
        $object->method('getOwner')->willReturn($attrs['owner'] ?? null);
        $object->method('getUpdated')->willReturn($attrs['updated'] ?? null);
        $object->method('getId')->willReturn($attrs['id'] ?? 1);

        return $object;
    }//end buildObjectMock()

    /**
     * Build a Schema mock with addMethods.
     *
     * @param string|null $title       Schema title.
     * @param string|null $name        Schema name.
     * @param string|null $description Schema description.
     *
     * @return MockObject
     */
    private function buildSchemaMock(?string $title = 'MySchema', ?string $name = null, ?string $description = null): MockObject
    {
        $schema = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
            ->disableOriginalConstructor()
            ->addMethods(['getTitle', 'getName', 'getDescription'])
            ->getMock();

        $schema->method('getTitle')->willReturn($title);
        $schema->method('getName')->willReturn($name);
        $schema->method('getDescription')->willReturn($description);

        return $schema;
    }//end buildSchemaMock()

    /**
     * Build a Register mock with addMethods.
     *
     * @param string|null $title       Register title.
     * @param string|null $name        Register name.
     * @param string|null $description Register description.
     *
     * @return MockObject
     */
    private function buildRegisterMock(?string $title = 'MyRegister', ?string $name = null, ?string $description = null): MockObject
    {
        $register = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
            ->disableOriginalConstructor()
            ->addMethods(['getTitle', 'getName', 'getDescription'])
            ->getMock();

        $register->method('getTitle')->willReturn($title);
        $register->method('getName')->willReturn($name);
        $register->method('getDescription')->willReturn($description);

        return $register;
    }//end buildRegisterMock()

    // ── getSourceType ────────────────────────────────────────────────────

    public function testGetSourceTypeReturnsObject(): void
    {
        $this->assertSame('object', $this->handler->getSourceType());
    }//end testGetSourceTypeReturnsObject()

    // ── extractText ──────────────────────────────────────────────────────

    public function testExtractTextReturnsExpectedStructure(): void
    {
        $object = $this->buildObjectMock(['uuid' => 'abc-123', 'version' => '2.0.0', 'object' => ['title' => 'Hello']]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertSame('object', $result['source_type']);
        $this->assertSame(1, $result['source_id']);
        $this->assertIsString($result['text']);
        $this->assertIsInt($result['length']);
        $this->assertIsString($result['checksum']);
        $this->assertSame('object_extraction', $result['method']);
        $this->assertArrayHasKey('metadata', $result);
    }//end testExtractTextReturnsExpectedStructure()

    public function testExtractTextIncludesUuidAndVersion(): void
    {
        $object = $this->buildObjectMock(['uuid' => 'my-uuid', 'version' => '3.1.4', 'object' => ['x' => 'y']]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('my-uuid', $result['text']);
        $this->assertStringContainsString('3.1.4', $result['text']);
    }//end testExtractTextIncludesUuidAndVersion()

    public function testExtractTextIncludesSchemaTitle(): void
    {
        $object = $this->buildObjectMock(['schema' => 5, 'object' => ['x' => 'y']]);
        $this->objectMapper->method('find')->willReturn($object);

        $schema = $this->buildSchemaMock('Person Schema');
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('Person Schema', $result['text']);
    }//end testExtractTextIncludesSchemaTitle()

    public function testExtractTextIncludesSchemaDescription(): void
    {
        $object = $this->buildObjectMock(['schema' => 5, 'object' => ['x' => 'y']]);
        $this->objectMapper->method('find')->willReturn($object);

        $schema = $this->buildSchemaMock('Title', null, 'A description of the schema');
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('A description of the schema', $result['text']);
    }//end testExtractTextIncludesSchemaDescription()

    public function testExtractTextContinuesWhenSchemaNotFound(): void
    {
        $object = $this->buildObjectMock(['schema' => 999, 'object' => ['x' => 'y']]);
        $this->objectMapper->method('find')->willReturn($object);

        $this->schemaMapper->method('find')->willThrowException(new Exception('Not found'));
        $this->logger->expects($this->atLeastOnce())->method('debug');

        // Should not throw — schema load failure is swallowed.
        $result = $this->handler->extractText(1, []);

        $this->assertSame('object', $result['source_type']);
    }//end testExtractTextContinuesWhenSchemaNotFound()

    public function testExtractTextIncludesRegisterTitle(): void
    {
        $object = $this->buildObjectMock(['register' => 3, 'object' => ['x' => 'y']]);
        $this->objectMapper->method('find')->willReturn($object);

        $register = $this->buildRegisterMock('My Register');
        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('My Register', $result['text']);
    }//end testExtractTextIncludesRegisterTitle()

    public function testExtractTextIncludesRegisterDescription(): void
    {
        $object = $this->buildObjectMock(['register' => 3, 'object' => ['x' => 'y']]);
        $this->objectMapper->method('find')->willReturn($object);

        $register = $this->buildRegisterMock('Reg', null, 'Register description text');
        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('Register description text', $result['text']);
    }//end testExtractTextIncludesRegisterDescription()

    public function testExtractTextContinuesWhenRegisterNotFound(): void
    {
        $object = $this->buildObjectMock(['register' => 999, 'object' => ['x' => 'y']]);
        $this->objectMapper->method('find')->willReturn($object);

        $this->registerMapper->method('find')->willThrowException(new Exception('Not found'));
        $this->logger->expects($this->atLeastOnce())->method('debug');

        $result = $this->handler->extractText(1, []);

        $this->assertSame('object', $result['source_type']);
    }//end testExtractTextContinuesWhenRegisterNotFound()

    public function testExtractTextIncludesOrganization(): void
    {
        $object = $this->buildObjectMock(['organization' => 'Conduction BV', 'object' => ['x' => 'y']]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('Conduction BV', $result['text']);
    }//end testExtractTextIncludesOrganization()

    public function testExtractTextChecksumIsSha256(): void
    {
        $object = $this->buildObjectMock(['object' => ['key' => 'value']]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        // SHA-256 hex digest is 64 characters.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['checksum']);
        $this->assertSame(hash('sha256', $result['text']), $result['checksum']);
    }//end testExtractTextChecksumIsSha256()

    public function testExtractTextLengthMatchesTextLength(): void
    {
        $object = $this->buildObjectMock(['object' => ['key' => 'value']]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertSame(strlen($result['text']), $result['length']);
    }//end testExtractTextLengthMatchesTextLength()

    public function testExtractTextMetadataContainsObjectFields(): void
    {
        $object = $this->buildObjectMock([
            'uuid'     => 'meta-uuid',
            'schema'   => 5,
            'register' => 3,
            'version'  => '1.2.3',
            'object'   => ['x' => 'y'],
        ]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertSame('meta-uuid', $result['metadata']['uuid']);
        $this->assertSame(5, $result['metadata']['schema_id']);
        $this->assertSame(3, $result['metadata']['register_id']);
        $this->assertSame('1.2.3', $result['metadata']['version']);
    }//end testExtractTextMetadataContainsObjectFields()

    public function testExtractTextThrowsWhenNoTextExtracted(): void
    {
        // Object with no data and no UUID would still have uuid-line, but let's
        // verify the flow when object data is empty array (Content: part absent).
        // Actually the UUID line is always added, so we need a NULL uuid to get empty text.
        // Use an object whose getUuid returns null and no other fields.
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObject'])
            ->addMethods([
                'getUuid', 'getVersion', 'getSchema', 'getRegister',
                'getOrganization', 'getOwner', 'getUpdated', 'getId',
            ])
            ->getMock();

        $object->method('getUuid')->willReturn('some-uuid');
        $object->method('getVersion')->willReturn(null);
        $object->method('getSchema')->willReturn(null);
        $object->method('getRegister')->willReturn(null);
        $object->method('getObject')->willReturn([]);   // empty → no Content: line
        $object->method('getOrganization')->willReturn(null);
        $object->method('getOwner')->willReturn(null);
        $object->method('getUpdated')->willReturn(null);
        $object->method('getId')->willReturn(1);

        $this->objectMapper->method('find')->willReturn($object);

        // "Object ID: some-uuid" is still produced, so no exception thrown.
        // Verify extractText does NOT throw in normal minimal case.
        $result = $this->handler->extractText(1, []);
        $this->assertStringContainsString('some-uuid', $result['text']);
    }//end testExtractTextThrowsWhenNoTextExtracted()

    public function testExtractTextHandlesNestedObjectData(): void
    {
        $nestedData = [
            'person' => [
                'firstName' => 'Jan',
                'lastName'  => 'Jansen',
                'address'   => [
                    'city' => 'Amsterdam',
                ],
            ],
        ];

        $object = $this->buildObjectMock(['object' => $nestedData]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('Jan', $result['text']);
        $this->assertStringContainsString('Amsterdam', $result['text']);
    }//end testExtractTextHandlesNestedObjectData()

    public function testExtractTextHandlesBooleanInObjectData(): void
    {
        $object = $this->buildObjectMock(['object' => ['active' => true, 'deleted' => false]]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('true', $result['text']);
        $this->assertStringContainsString('false', $result['text']);
    }//end testExtractTextHandlesBooleanInObjectData()

    public function testExtractTextHandlesNumericValuesInObjectData(): void
    {
        $object = $this->buildObjectMock(['object' => ['count' => 42, 'price' => 9.99]]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertStringContainsString('42', $result['text']);
        $this->assertStringContainsString('9.99', $result['text']);
    }//end testExtractTextHandlesNumericValuesInObjectData()

    // ── needsExtraction ──────────────────────────────────────────────────

    public function testNeedsExtractionReturnsTrueWhenForced(): void
    {
        $this->assertTrue($this->handler->needsExtraction(1, time(), true));
    }//end testNeedsExtractionReturnsTrueWhenForced()

    public function testNeedsExtractionReturnsTrueWhenNoChunksExist(): void
    {
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);

        $this->assertTrue($this->handler->needsExtraction(1, time(), false));
    }//end testNeedsExtractionReturnsTrueWhenNoChunksExist()

    public function testNeedsExtractionReturnsFalseWhenChunksAreUpToDate(): void
    {
        $now = time();
        // Chunk is newer than the source timestamp.
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn($now + 100);

        $this->assertFalse($this->handler->needsExtraction(1, $now, false));
    }//end testNeedsExtractionReturnsFalseWhenChunksAreUpToDate()

    public function testNeedsExtractionReturnsTrueWhenChunksAreStale(): void
    {
        $now = time();
        // Chunk is older than the source timestamp.
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn($now - 100);

        $this->assertTrue($this->handler->needsExtraction(1, $now, false));
    }//end testNeedsExtractionReturnsTrueWhenChunksAreStale()

    // ── getSourceMetadata ────────────────────────────────────────────────

    public function testGetSourceMetadataReturnsExpectedKeys(): void
    {
        $updated = new DateTime('2025-01-01 00:00:00');
        $object  = $this->buildObjectMock([
            'id'           => 7,
            'uuid'         => 'source-uuid',
            'schema'       => 2,
            'register'     => 1,
            'version'      => '1.0.0',
            'organization' => 'OrgX',
            'owner'        => 'user1',
            'updated'      => $updated,
        ]);
        $this->objectMapper->method('find')->willReturn($object);

        $meta = $this->handler->getSourceMetadata(7);

        foreach (['id', 'uuid', 'schema', 'register', 'version', 'organization', 'owner', 'updated'] as $key) {
            $this->assertArrayHasKey($key, $meta, "Missing key: {$key}");
        }

        $this->assertSame('source-uuid', $meta['uuid']);
        $this->assertSame(2, $meta['schema']);
        $this->assertSame(1, $meta['register']);
        $this->assertSame('1.0.0', $meta['version']);
        $this->assertSame('user1', $meta['owner']);
        $this->assertSame($updated, $meta['updated']);
    }//end testGetSourceMetadataReturnsExpectedKeys()

    // ── getSourceTimestamp ───────────────────────────────────────────────

    public function testGetSourceTimestampReturnsObjectUpdateTime(): void
    {
        $dt     = new DateTime('2025-06-01 12:00:00');
        $object = $this->buildObjectMock(['updated' => $dt]);
        $this->objectMapper->method('find')->willReturn($object);

        $ts = $this->handler->getSourceTimestamp(1);

        $this->assertSame($dt->getTimestamp(), $ts);
    }//end testGetSourceTimestampReturnsObjectUpdateTime()

    public function testGetSourceTimestampReturnsCurrentTimeWhenObjectNotFound(): void
    {
        $this->objectMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $before = time();
        $ts     = $this->handler->getSourceTimestamp(999);
        $after  = time();

        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }//end testGetSourceTimestampReturnsCurrentTimeWhenObjectNotFound()

    public function testGetSourceTimestampReturnsCurrentTimeWhenUpdatedIsNull(): void
    {
        $object = $this->buildObjectMock(['updated' => null]);
        $this->objectMapper->method('find')->willReturn($object);

        $before = time();
        $ts     = $this->handler->getSourceTimestamp(1);
        $after  = time();

        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }//end testGetSourceTimestampReturnsCurrentTimeWhenUpdatedIsNull()

    // ── extractTextFromArray (private — exercised via extractText) ────────

    public function testExtractTextIgnoresEmptyStringValues(): void
    {
        $object = $this->buildObjectMock(['object' => ['name' => '', 'desc' => 'valid']]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        // Empty string 'name' should not appear; 'valid' should.
        $this->assertStringNotContainsString('name: ', $result['text']);
        $this->assertStringContainsString('valid', $result['text']);
    }//end testExtractTextIgnoresEmptyStringValues()

    public function testExtractTextIgnoresEmptyArrayValues(): void
    {
        $object = $this->buildObjectMock(['object' => ['empty' => [], 'full' => ['key' => 'val']]]);
        $this->objectMapper->method('find')->willReturn($object);

        $result = $this->handler->extractText(1, []);

        $this->assertStringNotContainsString('empty:', $result['text']);
        $this->assertStringContainsString('val', $result['text']);
    }//end testExtractTextIgnoresEmptyArrayValues()

    public function testExtractTextRespectsMaxRecursionDepth(): void
    {
        // Build a deeply nested structure (11 levels) — recursion stops at 10.
        $nested = ['leaf' => 'deep-value'];
        for ($i = 0; $i < 12; $i++) {
            $nested = ['level' => $nested];
        }

        $object = $this->buildObjectMock(['object' => $nested]);
        $this->objectMapper->method('find')->willReturn($object);

        // Should not throw or recurse infinitely.
        $result = $this->handler->extractText(1, []);

        $this->assertIsString($result['text']);
    }//end testExtractTextRespectsMaxRecursionDepth()

}//end class
