<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Chunk;
use PHPUnit\Framework\TestCase;

class ChunkTest extends TestCase
{
    private Chunk $chunk;

    protected function setUp(): void
    {
        $this->chunk = new Chunk();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->chunk->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['sourceType']);
        $this->assertSame('integer', $fieldTypes['sourceId']);
        $this->assertSame('string', $fieldTypes['textContent']);
        $this->assertSame('integer', $fieldTypes['startOffset']);
        $this->assertSame('integer', $fieldTypes['endOffset']);
        $this->assertSame('integer', $fieldTypes['chunkIndex']);
        $this->assertSame('json', $fieldTypes['positionReference']);
        $this->assertSame('string', $fieldTypes['language']);
        $this->assertSame('string', $fieldTypes['languageLevel']);
        $this->assertSame('float', $fieldTypes['languageConfidence']);
        $this->assertSame('string', $fieldTypes['detectionMethod']);
        $this->assertSame('boolean', $fieldTypes['indexed']);
        $this->assertSame('boolean', $fieldTypes['vectorized']);
        $this->assertSame('string', $fieldTypes['embeddingProvider']);
        $this->assertSame('integer', $fieldTypes['overlapSize']);
        $this->assertSame('string', $fieldTypes['owner']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('string', $fieldTypes['checksum']);
        $this->assertSame('datetime', $fieldTypes['createdAt']);
        $this->assertSame('datetime', $fieldTypes['updatedAt']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->chunk->getUuid());
        $this->assertNull($this->chunk->getSourceType());
        $this->assertNull($this->chunk->getSourceId());
        $this->assertNull($this->chunk->getTextContent());
        $this->assertSame(0, $this->chunk->getStartOffset());
        $this->assertSame(0, $this->chunk->getEndOffset());
        $this->assertSame(0, $this->chunk->getChunkIndex());
        $this->assertNull($this->chunk->getPositionReference());
        $this->assertNull($this->chunk->getLanguage());
        $this->assertNull($this->chunk->getLanguageLevel());
        $this->assertNull($this->chunk->getLanguageConfidence());
        $this->assertNull($this->chunk->getDetectionMethod());
        $this->assertFalse($this->chunk->getIndexed());
        $this->assertFalse($this->chunk->getVectorized());
        $this->assertNull($this->chunk->getEmbeddingProvider());
        $this->assertSame(0, $this->chunk->getOverlapSize());
        $this->assertNull($this->chunk->getOwner());
        $this->assertNull($this->chunk->getOrganisation());
        $this->assertNull($this->chunk->getChecksum());
        $this->assertNull($this->chunk->getCreatedAt());
        $this->assertNull($this->chunk->getUpdatedAt());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->chunk->setUuid('chunk-uuid');
        $this->chunk->setSourceType('file');
        $this->chunk->setTextContent('Hello world');
        $this->chunk->setLanguage('en');
        $this->chunk->setLanguageLevel('B2');
        $this->chunk->setDetectionMethod('nlp');
        $this->chunk->setEmbeddingProvider('openai');
        $this->chunk->setOwner('admin');
        $this->chunk->setOrganisation('org-uuid');
        $this->chunk->setChecksum('abc123');

        $this->assertSame('chunk-uuid', $this->chunk->getUuid());
        $this->assertSame('file', $this->chunk->getSourceType());
        $this->assertSame('Hello world', $this->chunk->getTextContent());
        $this->assertSame('en', $this->chunk->getLanguage());
        $this->assertSame('B2', $this->chunk->getLanguageLevel());
        $this->assertSame('nlp', $this->chunk->getDetectionMethod());
        $this->assertSame('openai', $this->chunk->getEmbeddingProvider());
        $this->assertSame('admin', $this->chunk->getOwner());
        $this->assertSame('org-uuid', $this->chunk->getOrganisation());
        $this->assertSame('abc123', $this->chunk->getChecksum());
    }

    public function testSetAndGetNumericFields(): void
    {
        $this->chunk->setSourceId(42);
        $this->chunk->setStartOffset(100);
        $this->chunk->setEndOffset(200);
        $this->chunk->setChunkIndex(5);
        $this->chunk->setLanguageConfidence(0.95);
        $this->chunk->setOverlapSize(50);

        $this->assertSame(42, $this->chunk->getSourceId());
        $this->assertSame(100, $this->chunk->getStartOffset());
        $this->assertSame(200, $this->chunk->getEndOffset());
        $this->assertSame(5, $this->chunk->getChunkIndex());
        $this->assertSame(0.95, $this->chunk->getLanguageConfidence());
        $this->assertSame(50, $this->chunk->getOverlapSize());
    }

    public function testSetAndGetBooleanFields(): void
    {
        $this->chunk->setIndexed(true);
        $this->chunk->setVectorized(true);

        $this->assertTrue($this->chunk->getIndexed());
        $this->assertTrue($this->chunk->getVectorized());
    }

    public function testSetAndGetJsonFields(): void
    {
        $posRef = ['page' => 1, 'paragraph' => 3];
        $this->chunk->setPositionReference($posRef);
        $this->assertSame($posRef, $this->chunk->getPositionReference());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');

        $this->chunk->setCreatedAt($created);
        $this->chunk->setUpdatedAt($updated);

        $this->assertSame($created, $this->chunk->getCreatedAt());
        $this->assertSame($updated, $this->chunk->getUpdatedAt());
    }

    public function testJsonSerialize(): void
    {
        $this->chunk->setUuid('serialize-uuid');
        $this->chunk->setSourceType('object');
        $this->chunk->setSourceId(10);
        $this->chunk->setChunkIndex(2);
        $this->chunk->setStartOffset(50);
        $this->chunk->setEndOffset(150);
        $this->chunk->setIndexed(true);

        $json = $this->chunk->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'sourceType', 'sourceId', 'chunkIndex',
            'startOffset', 'endOffset', 'language', 'languageLevel',
            'languageConfidence', 'indexed', 'vectorized', 'embeddingProvider',
            'overlapSize', 'owner', 'organisation', 'checksum',
            'createdAt', 'updatedAt', 'positionReference',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('serialize-uuid', $json['uuid']);
        $this->assertSame('object', $json['sourceType']);
        $this->assertSame(10, $json['sourceId']);
        $this->assertTrue($json['indexed']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-03-15T10:00:00+00:00');
        $this->chunk->setCreatedAt($created);

        $json = $this->chunk->jsonSerialize();

        $this->assertSame($created->format(DateTime::ATOM), $json['createdAt']);
    }
}
