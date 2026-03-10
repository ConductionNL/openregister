<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\GdprEntity;
use PHPUnit\Framework\TestCase;

class GdprEntityTest extends TestCase
{
    private GdprEntity $entity;

    protected function setUp(): void
    {
        $this->entity = new GdprEntity();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->entity->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['type']);
        $this->assertSame('string', $fieldTypes['value']);
        $this->assertSame('string', $fieldTypes['category']);
        $this->assertSame('integer', $fieldTypes['belongsToEntityId']);
        $this->assertSame('json', $fieldTypes['metadata']);
        $this->assertSame('string', $fieldTypes['owner']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('datetime', $fieldTypes['detectedAt']);
        $this->assertSame('datetime', $fieldTypes['updatedAt']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->entity->getUuid());
        $this->assertNull($this->entity->getType());
        $this->assertNull($this->entity->getValue());
        $this->assertNull($this->entity->getCategory());
        $this->assertNull($this->entity->getBelongsToEntityId());
        $this->assertNull($this->entity->getMetadata());
        $this->assertNull($this->entity->getOwner());
        $this->assertNull($this->entity->getOrganisation());
        $this->assertNull($this->entity->getDetectedAt());
        $this->assertNull($this->entity->getUpdatedAt());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->entity->setUuid('gdpr-uuid');
        $this->entity->setType(GdprEntity::TYPE_EMAIL);
        $this->entity->setValue('test@example.com');
        $this->entity->setCategory(GdprEntity::CATEGORY_PII);
        $this->entity->setOwner('admin');
        $this->entity->setOrganisation('org-uuid');

        $this->assertSame('gdpr-uuid', $this->entity->getUuid());
        $this->assertSame('email', $this->entity->getType());
        $this->assertSame('test@example.com', $this->entity->getValue());
        $this->assertSame('pii', $this->entity->getCategory());
        $this->assertSame('admin', $this->entity->getOwner());
        $this->assertSame('org-uuid', $this->entity->getOrganisation());
    }

    public function testSetAndGetBelongsToEntityId(): void
    {
        $this->entity->setBelongsToEntityId(42);
        $this->assertSame(42, $this->entity->getBelongsToEntityId());
    }

    public function testSetAndGetMetadata(): void
    {
        $metadata = ['source' => 'scan', 'confidence' => 0.99];
        $this->entity->setMetadata($metadata);
        $this->assertSame($metadata, $this->entity->getMetadata());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $detected = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');

        $this->entity->setDetectedAt($detected);
        $this->entity->setUpdatedAt($updated);

        $this->assertSame($detected, $this->entity->getDetectedAt());
        $this->assertSame($updated, $this->entity->getUpdatedAt());
    }

    public function testConstants(): void
    {
        $this->assertSame('person', GdprEntity::TYPE_PERSON);
        $this->assertSame('email', GdprEntity::TYPE_EMAIL);
        $this->assertSame('phone', GdprEntity::TYPE_PHONE);
        $this->assertSame('organization', GdprEntity::TYPE_ORGANIZATION);
        $this->assertSame('pii', GdprEntity::CATEGORY_PII);
        $this->assertSame('sensitive_pii', GdprEntity::CATEGORY_SENSITIVE);
        $this->assertSame('business_data', GdprEntity::CATEGORY_BUSINESS);
    }

    public function testJsonSerialize(): void
    {
        $this->entity->setUuid('json-uuid');
        $this->entity->setType(GdprEntity::TYPE_PERSON);
        $this->entity->setValue('John Doe');
        $this->entity->setCategory(GdprEntity::CATEGORY_PII);
        $this->entity->setBelongsToEntityId(5);

        $json = $this->entity->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'type', 'value', 'category', 'belongsToEntityId',
            'metadata', 'owner', 'organisation', 'detectedAt', 'updatedAt',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame('person', $json['type']);
        $this->assertSame('John Doe', $json['value']);
        $this->assertSame('pii', $json['category']);
        $this->assertSame(5, $json['belongsToEntityId']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $detected = new DateTime('2024-03-15T10:00:00+00:00');
        $this->entity->setDetectedAt($detected);

        $json = $this->entity->jsonSerialize();

        $this->assertSame($detected->format(DateTime::ATOM), $json['detectedAt']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $json = $this->entity->jsonSerialize();
        $this->assertNull($json['detectedAt']);
        $this->assertNull($json['updatedAt']);
    }
}
