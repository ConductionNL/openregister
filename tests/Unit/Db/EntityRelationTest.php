<?php

/**
 * Unit tests for the EntityRelation entity (round-trip getters/setters).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests\Unit\Db
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\EntityRelation;
use PHPUnit\Framework\TestCase;

class EntityRelationTest extends TestCase
{
    private EntityRelation $relation;

    protected function setUp(): void
    {
        $this->relation = new EntityRelation();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->relation->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['entityId']);
        $this->assertSame('integer', $fieldTypes['chunkId']);
        $this->assertSame('string', $fieldTypes['role']);
        $this->assertSame('integer', $fieldTypes['fileId']);
        $this->assertSame('integer', $fieldTypes['objectId']);
        $this->assertSame('integer', $fieldTypes['emailId']);
        $this->assertSame('integer', $fieldTypes['positionStart']);
        $this->assertSame('integer', $fieldTypes['positionEnd']);
        $this->assertSame('float', $fieldTypes['confidence']);
        $this->assertSame('string', $fieldTypes['detectionMethod']);
        $this->assertSame('string', $fieldTypes['context']);
        $this->assertSame('boolean', $fieldTypes['anonymized']);
        $this->assertSame('string', $fieldTypes['anonymizedValue']);
        $this->assertSame('json', $fieldTypes['bases']);
        $this->assertSame('boolean', $fieldTypes['skipAnonymization']);
        $this->assertSame('datetime', $fieldTypes['createdAt']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->relation->getEntityId());
        $this->assertNull($this->relation->getChunkId());
        $this->assertNull($this->relation->getRole());
        $this->assertNull($this->relation->getFileId());
        $this->assertNull($this->relation->getObjectId());
        $this->assertNull($this->relation->getEmailId());
        $this->assertSame(0, $this->relation->getPositionStart());
        $this->assertSame(0, $this->relation->getPositionEnd());
        $this->assertSame(0.0, $this->relation->getConfidence());
        $this->assertNull($this->relation->getDetectionMethod());
        $this->assertNull($this->relation->getContext());
        $this->assertFalse($this->relation->getAnonymized());
        $this->assertNull($this->relation->getAnonymizedValue());
        $this->assertNull($this->relation->getBases());
        $this->assertFalse($this->relation->getSkipAnonymization());
        $this->assertNull($this->relation->getCreatedAt());
    }

    public function testSetAndGetBasesRoundTrip(): void
    {
        $this->relation->setBases(['uuid-a', 'uuid-b']);
        $this->assertSame(['uuid-a', 'uuid-b'], $this->relation->getBases());
    }

    public function testBasesEmptyArrayDistinctFromNull(): void
    {
        $this->relation->setBases([]);
        $bases = $this->relation->getBases();
        $this->assertNotNull($bases);
        $this->assertSame([], $bases);
    }

    public function testSetAndGetSkipAnonymization(): void
    {
        $this->relation->setSkipAnonymization(true);
        $this->assertTrue($this->relation->getSkipAnonymization());

        $this->relation->setSkipAnonymization(false);
        $this->assertFalse($this->relation->getSkipAnonymization());
    }

    public function testSetAndGetIntegerFields(): void
    {
        $this->relation->setEntityId(1);
        $this->relation->setChunkId(2);
        $this->relation->setFileId(3);
        $this->relation->setObjectId(4);
        $this->relation->setEmailId(5);
        $this->relation->setPositionStart(100);
        $this->relation->setPositionEnd(200);

        $this->assertSame(1, $this->relation->getEntityId());
        $this->assertSame(2, $this->relation->getChunkId());
        $this->assertSame(3, $this->relation->getFileId());
        $this->assertSame(4, $this->relation->getObjectId());
        $this->assertSame(5, $this->relation->getEmailId());
        $this->assertSame(100, $this->relation->getPositionStart());
        $this->assertSame(200, $this->relation->getPositionEnd());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->relation->setRole('subject');
        $this->relation->setDetectionMethod('nlp');
        $this->relation->setContext('found in paragraph 3');
        $this->relation->setAnonymizedValue('***');

        $this->assertSame('subject', $this->relation->getRole());
        $this->assertSame('nlp', $this->relation->getDetectionMethod());
        $this->assertSame('found in paragraph 3', $this->relation->getContext());
        $this->assertSame('***', $this->relation->getAnonymizedValue());
    }

    public function testSetAndGetConfidence(): void
    {
        $this->relation->setConfidence(0.95);
        $this->assertSame(0.95, $this->relation->getConfidence());
    }

    public function testSetAndGetAnonymized(): void
    {
        $this->relation->setAnonymized(true);
        $this->assertTrue($this->relation->getAnonymized());
    }

    public function testSetAndGetCreatedAt(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $this->relation->setCreatedAt($created);
        $this->assertSame($created, $this->relation->getCreatedAt());
    }

    public function testJsonSerialize(): void
    {
        $this->relation->setEntityId(10);
        $this->relation->setChunkId(20);
        $this->relation->setRole('object');
        $this->relation->setConfidence(0.85);
        $this->relation->setAnonymized(false);

        $json = $this->relation->jsonSerialize();

        $expectedKeys = [
            'id', 'entityId', 'chunkId', 'role', 'fileId', 'objectId',
            'emailId', 'positionStart', 'positionEnd', 'confidence',
            'detectionMethod', 'context', 'anonymized', 'anonymizedValue',
            'bases', 'skipAnonymization', 'createdAt',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame(10, $json['entityId']);
        $this->assertSame(20, $json['chunkId']);
        $this->assertSame('object', $json['role']);
        $this->assertSame(0.85, $json['confidence']);
        $this->assertFalse($json['anonymized']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-03-15T10:00:00+00:00');
        $this->relation->setCreatedAt($created);

        $json = $this->relation->jsonSerialize();

        $this->assertSame($created->format(DateTime::ATOM), $json['createdAt']);
    }

    public function testJsonSerializeNullCreatedAt(): void
    {
        $json = $this->relation->jsonSerialize();
        $this->assertNull($json['createdAt']);
    }
}
