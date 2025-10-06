<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use DateTime;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new Schema();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(Schema::class, $this->schema);
        $this->assertNull($this->schema->getUuid());
        $this->assertNull($this->schema->getUri());
        $this->assertNull($this->schema->getSlug());
        $this->assertNull($this->schema->getTitle());
        $this->assertNull($this->schema->getDescription());
        $this->assertNull($this->schema->getVersion());
        $this->assertIsArray($this->schema->getRequired());
        $this->assertIsArray($this->schema->getProperties());
        $this->assertFalse($this->schema->getHardValidation());
        $this->assertNull($this->schema->getOrganisation());
        $this->assertNull($this->schema->getCreated());
        $this->assertNull($this->schema->getUpdated());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->schema->setUuid($uuid);
        $this->assertEquals($uuid, $this->schema->getUuid());
    }

    public function testUri(): void
    {
        $uri = 'https://example.com/schema';
        $this->schema->setUri($uri);
        $this->assertEquals($uri, $this->schema->getUri());
    }

    public function testSlug(): void
    {
        $slug = 'test-schema';
        $this->schema->setSlug($slug);
        $this->assertEquals($slug, $this->schema->getSlug());
    }

    public function testTitle(): void
    {
        $title = 'Test Schema';
        $this->schema->setTitle($title);
        $this->assertEquals($title, $this->schema->getTitle());
    }

    public function testVersion(): void
    {
        $version = '1.0.0';
        $this->schema->setVersion($version);
        $this->assertEquals($version, $this->schema->getVersion());
    }

    public function testRequired(): void
    {
        $required = ['field1', 'field2'];
        $this->schema->setRequired($required);
        $this->assertEquals($required, $this->schema->getRequired());
    }

    public function testProperties(): void
    {
        $properties = ['field1' => 'string', 'field2' => 'integer'];
        $this->schema->setProperties($properties);
        $this->assertEquals($properties, $this->schema->getProperties());
    }

    public function testHardValidation(): void
    {
        $this->schema->setHardValidation(true);
        $this->assertTrue($this->schema->getHardValidation());
    }

    public function testDescription(): void
    {
        $description = 'Test Description';
        $this->schema->setDescription($description);
        $this->assertEquals($description, $this->schema->getDescription());
    }


    public function testOrganisation(): void
    {
        $organisation = 456;
        $this->schema->setOrganisation($organisation);
        $this->assertEquals($organisation, $this->schema->getOrganisation());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->schema->setCreated($created);
        $this->assertEquals($created, $this->schema->getCreated());
    }

    public function testUpdated(): void
    {
        $updated = new DateTime('2024-01-02 00:00:00');
        $this->schema->setUpdated($updated);
        $this->assertEquals($updated, $this->schema->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->schema->setUuid('test-uuid');
        $this->schema->setTitle('Test Schema');
        $this->schema->setDescription('Test Description');
        $this->schema->setUri('https://example.com/schema');
        
        $json = $this->schema->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('test-uuid', $json['uuid']);
        $this->assertEquals('Test Schema', $json['title']);
        $this->assertEquals('Test Description', $json['description']);
        $this->assertEquals('https://example.com/schema', $json['uri']);
    }
}
