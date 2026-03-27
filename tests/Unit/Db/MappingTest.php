<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Mapping;
use PHPUnit\Framework\TestCase;

class MappingTest extends TestCase
{
    private Mapping $mapping;

    protected function setUp(): void
    {
        $this->mapping = new Mapping();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->mapping->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['reference']);
        $this->assertSame('string', $fieldTypes['version']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('json', $fieldTypes['mapping']);
        $this->assertSame('json', $fieldTypes['unset']);
        $this->assertSame('json', $fieldTypes['cast']);
        $this->assertSame('boolean', $fieldTypes['passThrough']);
        $this->assertSame('json', $fieldTypes['configurations']);
        $this->assertSame('string', $fieldTypes['slug']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->mapping->getUuid());
        $this->assertNull($this->mapping->getReference());
        $this->assertSame('0.0.0', $this->mapping->getVersion());
        $this->assertNull($this->mapping->getName());
        $this->assertNull($this->mapping->getDescription());
        $this->assertSame([], $this->mapping->getMapping());
        $this->assertSame([], $this->mapping->getUnset());
        $this->assertSame([], $this->mapping->getCast());
        $this->assertNull($this->mapping->getPassThrough());
        $this->assertSame([], $this->mapping->getConfigurations());
        $this->assertNull($this->mapping->getOrganisation());
        $this->assertNull($this->mapping->getCreated());
        $this->assertNull($this->mapping->getUpdated());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->mapping->setUuid('mapping-uuid');
        $this->mapping->setReference('ref-001');
        $this->mapping->setVersion('2.1.0');
        $this->mapping->setName('Test Mapping');
        $this->mapping->setDescription('A test mapping');
        $this->mapping->setSlug('test-mapping');
        $this->mapping->setOrganisation('org-uuid');

        $this->assertSame('mapping-uuid', $this->mapping->getUuid());
        $this->assertSame('ref-001', $this->mapping->getReference());
        $this->assertSame('2.1.0', $this->mapping->getVersion());
        $this->assertSame('Test Mapping', $this->mapping->getName());
        $this->assertSame('A test mapping', $this->mapping->getDescription());
        $this->assertSame('test-mapping', $this->mapping->getSlug());
        $this->assertSame('org-uuid', $this->mapping->getOrganisation());
    }

    public function testSetAndGetJsonFields(): void
    {
        $mappingConfig = ['target.name' => '{{ source.title }}'];
        $unsetConfig = ['obsolete_field'];
        $castConfig = ['age' => 'integer', 'active' => 'boolean'];
        $configurations = [1, 2, 3];

        $this->mapping->setMapping($mappingConfig);
        $this->mapping->setUnset($unsetConfig);
        $this->mapping->setCast($castConfig);
        $this->mapping->setConfigurations($configurations);

        $this->assertSame($mappingConfig, $this->mapping->getMapping());
        $this->assertSame($unsetConfig, $this->mapping->getUnset());
        $this->assertSame($castConfig, $this->mapping->getCast());
        $this->assertSame($configurations, $this->mapping->getConfigurations());
    }

    public function testGetMappingReturnsEmptyArrayWhenNull(): void
    {
        $this->mapping->setMapping(null);
        $this->assertSame([], $this->mapping->getMapping());
    }

    public function testGetUnsetReturnsEmptyArrayWhenNull(): void
    {
        $this->mapping->setUnset(null);
        $this->assertSame([], $this->mapping->getUnset());
    }

    public function testGetCastReturnsEmptyArrayWhenNull(): void
    {
        $this->mapping->setCast(null);
        $this->assertSame([], $this->mapping->getCast());
    }

    public function testGetConfigurationsReturnsEmptyArrayWhenNull(): void
    {
        $this->mapping->setConfigurations(null);
        $this->assertSame([], $this->mapping->getConfigurations());
    }

    public function testSetAndGetPassThrough(): void
    {
        $this->mapping->setPassThrough(true);
        $this->assertTrue($this->mapping->getPassThrough());

        $this->mapping->setPassThrough(false);
        $this->assertFalse($this->mapping->getPassThrough());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');

        $this->mapping->setCreated($created);
        $this->mapping->setUpdated($updated);

        $this->assertSame($created, $this->mapping->getCreated());
        $this->assertSame($updated, $this->mapping->getUpdated());
    }

    public function testGetSlugGeneratedFromName(): void
    {
        $this->mapping->setName('My Test Mapping');
        $this->assertSame('my-test-mapping', $this->mapping->getSlug());
    }

    public function testGetSlugReturnsSetSlug(): void
    {
        $this->mapping->setSlug('custom-slug');
        $this->mapping->setName('Different Name');
        $this->assertSame('custom-slug', $this->mapping->getSlug());
    }

    public function testGetSlugFallbackWhenEmpty(): void
    {
        // With no name and no slug, should generate a fallback
        $slug = $this->mapping->getSlug();
        $this->assertNotEmpty($slug);
        $this->assertStringStartsWith('mapping-', $slug);
    }

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->mapping->getJsonFields();
        $this->assertContains('mapping', $jsonFields);
        $this->assertContains('unset', $jsonFields);
        $this->assertContains('cast', $jsonFields);
        $this->assertContains('configurations', $jsonFields);
    }

    public function testHydrate(): void
    {
        $data = [
            'uuid'        => 'hydrate-uuid',
            'name'        => 'Hydrated Mapping',
            'mapping'     => ['a' => 'b'],
            'passThrough' => true,
        ];

        $result = $this->mapping->hydrate($data);

        $this->assertSame($this->mapping, $result);
        $this->assertSame('hydrate-uuid', $this->mapping->getUuid());
        $this->assertSame('Hydrated Mapping', $this->mapping->getName());
        $this->assertSame(['a' => 'b'], $this->mapping->getMapping());
        $this->assertTrue($this->mapping->getPassThrough());
    }

    public function testJsonSerialize(): void
    {
        $this->mapping->setUuid('json-uuid');
        $this->mapping->setName('JSON Mapping');
        $this->mapping->setSlug('json-mapping');
        $this->mapping->setVersion('1.0.0');
        $mappingConfig = ['target' => '{{ source }}'];
        $this->mapping->setMapping($mappingConfig);

        $json = $this->mapping->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'name', 'description', 'version', 'reference',
            'mapping', 'unset', 'cast', 'passThrough', 'configurations',
            'slug', 'organisation', 'created', 'updated',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame('JSON Mapping', $json['name']);
        $this->assertSame('json-mapping', $json['slug']);
        $this->assertSame($mappingConfig, $json['mapping']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $this->mapping->setCreated($created);

        $json = $this->mapping->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $this->mapping->setName('test');
        $json = $this->mapping->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }
}
