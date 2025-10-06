<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Source;
use DateTime;
use PHPUnit\Framework\TestCase;

class SourceTest extends TestCase
{
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new Source();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(Source::class, $this->source);
        $this->assertNull($this->source->getUuid());
        $this->assertNull($this->source->getTitle());
        $this->assertNull($this->source->getVersion());
        $this->assertNull($this->source->getDescription());
        $this->assertNull($this->source->getDatabaseUrl());
        $this->assertNull($this->source->getType());
        $this->assertNull($this->source->getCreated());
        $this->assertNull($this->source->getUpdated());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->source->setUuid($uuid);
        $this->assertEquals($uuid, $this->source->getUuid());
    }

    public function testTitle(): void
    {
        $title = 'Test Source';
        $this->source->setTitle($title);
        $this->assertEquals($title, $this->source->getTitle());
    }

    public function testVersion(): void
    {
        $version = '1.0.0';
        $this->source->setVersion($version);
        $this->assertEquals($version, $this->source->getVersion());
    }

    public function testDescription(): void
    {
        $description = 'Test Description';
        $this->source->setDescription($description);
        $this->assertEquals($description, $this->source->getDescription());
    }

    public function testDatabaseUrl(): void
    {
        $databaseUrl = 'mysql://localhost:3306/database';
        $this->source->setDatabaseUrl($databaseUrl);
        $this->assertEquals($databaseUrl, $this->source->getDatabaseUrl());
    }

    public function testType(): void
    {
        $type = 'mysql';
        $this->source->setType($type);
        $this->assertEquals($type, $this->source->getType());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->source->setCreated($created);
        $this->assertEquals($created, $this->source->getCreated());
    }

    public function testUpdated(): void
    {
        $updated = new DateTime('2024-01-02 00:00:00');
        $this->source->setUpdated($updated);
        $this->assertEquals($updated, $this->source->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->source->setUuid('test-uuid');
        $this->source->setTitle('Test Source');
        $this->source->setVersion('1.0.0');
        $this->source->setDescription('Test Description');
        $this->source->setDatabaseUrl('mysql://localhost:3306/database');
        
        $json = $this->source->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('test-uuid', $json['uuid']);
        $this->assertEquals('Test Source', $json['title']);
        $this->assertEquals('1.0.0', $json['version']);
        $this->assertEquals('Test Description', $json['description']);
        $this->assertEquals('mysql://localhost:3306/database', $json['databaseUrl']);
    }
}
