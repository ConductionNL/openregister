<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Source;
use PHPUnit\Framework\TestCase;

class SourceTest extends TestCase
{
    private Source $source;

    protected function setUp(): void
    {
        $this->source = new Source();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->source->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['title']);
        $this->assertSame('string', $fieldTypes['version']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('string', $fieldTypes['databaseUrl']);
        $this->assertSame('string', $fieldTypes['type']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('datetime', $fieldTypes['updated']);
        $this->assertSame('datetime', $fieldTypes['created']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->source->getUuid());
        $this->assertNull($this->source->getTitle());
        $this->assertNull($this->source->getVersion());
        $this->assertNull($this->source->getDescription());
        $this->assertNull($this->source->getDatabaseUrl());
        $this->assertNull($this->source->getType());
        $this->assertNull($this->source->getOrganisation());
        $this->assertNull($this->source->getUpdated());
        $this->assertNull($this->source->getCreated());
    }

    // --- Getters/Setters ---

    public function testSetAndGetUuid(): void
    {
        $this->source->setUuid('source-uuid-123');
        $this->assertSame('source-uuid-123', $this->source->getUuid());
    }

    public function testSetAndGetTitle(): void
    {
        $this->source->setTitle('My Source');
        $this->assertSame('My Source', $this->source->getTitle());
    }

    public function testSetAndGetVersion(): void
    {
        $this->source->setVersion('1.0.0');
        $this->assertSame('1.0.0', $this->source->getVersion());
    }

    public function testSetAndGetDescription(): void
    {
        $this->source->setDescription('A test source');
        $this->assertSame('A test source', $this->source->getDescription());
    }

    public function testSetAndGetDatabaseUrl(): void
    {
        $this->source->setDatabaseUrl('mysql://localhost/testdb');
        $this->assertSame('mysql://localhost/testdb', $this->source->getDatabaseUrl());
    }

    public function testSetAndGetType(): void
    {
        $this->source->setType('api');
        $this->assertSame('api', $this->source->getType());
    }

    public function testSetAndGetOrganisation(): void
    {
        $this->source->setOrganisation('org-uuid-456');
        $this->assertSame('org-uuid-456', $this->source->getOrganisation());
    }

    public function testSetAndGetOrganisationNull(): void
    {
        $this->source->setOrganisation('org-uuid');
        $this->source->setOrganisation(null);
        $this->assertNull($this->source->getOrganisation());
    }

    public function testSetAndGetUpdated(): void
    {
        $dt = new DateTime('2024-06-15 08:30:00');
        $this->source->setUpdated($dt);
        $this->assertSame($dt, $this->source->getUpdated());
    }

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-01-01 00:00:00');
        $this->source->setCreated($dt);
        $this->assertSame($dt, $this->source->getCreated());
    }

    // --- getJsonFields ---

    public function testGetJsonFieldsReturnsEmptyForSource(): void
    {
        // Source has no JSON fields
        $this->assertSame([], $this->source->getJsonFields());
    }

    // --- hydrate ---

    public function testHydrateSetsFields(): void
    {
        $this->source->hydrate([
            'uuid'        => 'hydrated-uuid',
            'title'       => 'Hydrated Source',
            'version'     => '2.0',
            'description' => 'Hydrated desc',
            'type'        => 'database',
        ]);

        $this->assertSame('hydrated-uuid', $this->source->getUuid());
        $this->assertSame('Hydrated Source', $this->source->getTitle());
        $this->assertSame('2.0', $this->source->getVersion());
        $this->assertSame('Hydrated desc', $this->source->getDescription());
        $this->assertSame('database', $this->source->getType());
    }

    public function testHydrateReturnsThis(): void
    {
        $result = $this->source->hydrate(['uuid' => 'test']);
        $this->assertSame($this->source, $result);
    }

    public function testHydrateIgnoresUnknownFields(): void
    {
        $this->source->hydrate(['nonExistent' => 'value']);
        $this->assertNull($this->source->getUuid());
    }

    // --- ManagedByConfiguration transient property ---

    public function testManagedByConfigurationEntityDefaultsToNull(): void
    {
        $this->assertNull($this->source->getManagedByConfigurationEntity());
    }

    // --- __toString ---

    public function testToStringReturnsTitleWhenSet(): void
    {
        $this->source->setTitle('My Source');
        $this->assertSame('My Source', (string)$this->source);
    }

    public function testToStringReturnsUuidWhenNoTitle(): void
    {
        $this->source->setUuid('source-uuid');
        $this->assertSame('source-uuid', (string)$this->source);
    }

    public function testToStringFallsBackToDefault(): void
    {
        $this->assertSame('Source', (string)$this->source);
    }

    // --- jsonSerialize ---

    public function testJsonSerializeAllFieldsPresent(): void
    {
        $json = $this->source->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'title', 'version', 'description',
            'databaseUrl', 'type', 'organisation', 'updated', 'created',
            'managedByConfiguration',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }

    public function testJsonSerializeDefaultValues(): void
    {
        $json = $this->source->jsonSerialize();

        $this->assertNull($json['id']);
        $this->assertNull($json['uuid']);
        $this->assertNull($json['title']);
        $this->assertNull($json['version']);
        $this->assertNull($json['description']);
        $this->assertNull($json['databaseUrl']);
        $this->assertNull($json['type']);
        $this->assertNull($json['organisation']);
        $this->assertNull($json['updated']);
        $this->assertNull($json['created']);
        $this->assertNull($json['managedByConfiguration']);
    }

    public function testJsonSerializeFormatsDatetimes(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $updated = new DateTime('2024-06-15 08:30:00');

        $this->source->setCreated($created);
        $this->source->setUpdated($updated);

        $json = $this->source->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($updated->format('c'), $json['updated']);
    }

    public function testJsonSerializeWithValues(): void
    {
        $this->source->setUuid('src-uuid');
        $this->source->setTitle('Test Source');
        $this->source->setVersion('1.5');
        $this->source->setDescription('A description');
        $this->source->setDatabaseUrl('postgres://localhost/db');
        $this->source->setType('api');
        $this->source->setOrganisation('org-uuid');

        $json = $this->source->jsonSerialize();

        $this->assertSame('src-uuid', $json['uuid']);
        $this->assertSame('Test Source', $json['title']);
        $this->assertSame('1.5', $json['version']);
        $this->assertSame('A description', $json['description']);
        $this->assertSame('postgres://localhost/db', $json['databaseUrl']);
        $this->assertSame('api', $json['type']);
        $this->assertSame('org-uuid', $json['organisation']);
    }

    // --- isManagedByConfiguration ---

    public function testIsManagedByConfigurationReturnsFalseWithEmptyArray(): void
    {
        $this->assertFalse($this->source->isManagedByConfiguration([]));
    }

    public function testIsManagedByConfigurationReturnsFalseWithNoId(): void
    {
        // Source has no ID set (null)
        $this->assertFalse($this->source->isManagedByConfiguration([]));
    }
}
