<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Register;
use DateTime;
use PHPUnit\Framework\TestCase;

class RegisterTest extends TestCase
{
    private Register $register;

    protected function setUp(): void
    {
        parent::setUp();
        $this->register = new Register();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(Register::class, $this->register);
        $this->assertNull($this->register->getUuid());
        $this->assertNull($this->register->getSlug());
        $this->assertNull($this->register->getTitle());
        $this->assertNull($this->register->getVersion());
        $this->assertNull($this->register->getDescription());
        $this->assertIsArray($this->register->getSchemas());
        $this->assertNull($this->register->getSource());
        $this->assertNull($this->register->getOrganisation());
        $this->assertNull($this->register->getCreated());
        $this->assertNull($this->register->getUpdated());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->register->setUuid($uuid);
        $this->assertEquals($uuid, $this->register->getUuid());
    }

    public function testSlug(): void
    {
        $slug = 'test-register';
        $this->register->setSlug($slug);
        $this->assertEquals($slug, $this->register->getSlug());
    }

    public function testTitle(): void
    {
        $title = 'Test Register';
        $this->register->setTitle($title);
        $this->assertEquals($title, $this->register->getTitle());
    }

    public function testVersion(): void
    {
        $version = '1.0.0';
        $this->register->setVersion($version);
        $this->assertEquals($version, $this->register->getVersion());
    }

    public function testSchemas(): void
    {
        $schemas = ['schema1', 'schema2'];
        $this->register->setSchemas($schemas);
        $this->assertEquals($schemas, $this->register->getSchemas());
    }

    public function testSource(): void
    {
        $source = 'https://example.com/source';
        $this->register->setSource($source);
        $this->assertEquals($source, $this->register->getSource());
    }

    public function testDescription(): void
    {
        $description = 'Test Description';
        $this->register->setDescription($description);
        $this->assertEquals($description, $this->register->getDescription());
    }

    public function testOrganisation(): void
    {
        $organisation = 123;
        $this->register->setOrganisation($organisation);
        $this->assertEquals($organisation, $this->register->getOrganisation());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->register->setCreated($created);
        $this->assertEquals($created, $this->register->getCreated());
    }

    public function testUpdated(): void
    {
        $updated = new DateTime('2024-01-02 00:00:00');
        $this->register->setUpdated($updated);
        $this->assertEquals($updated, $this->register->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->register->setUuid('test-uuid');
        $this->register->setTitle('Test Register');
        $this->register->setDescription('Test Description');
        $this->register->setSlug('test-register');
        
        $json = $this->register->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('test-uuid', $json['uuid']);
        $this->assertEquals('Test Register', $json['title']);
        $this->assertEquals('Test Description', $json['description']);
        $this->assertEquals('test-register', $json['slug']);
    }
}
