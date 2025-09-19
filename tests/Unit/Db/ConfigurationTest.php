<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Configuration;
use DateTime;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    private Configuration $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = new Configuration();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(Configuration::class, $this->configuration);
        $this->assertNull($this->configuration->getTitle());
        $this->assertNull($this->configuration->getDescription());
        $this->assertNull($this->configuration->getType());
        $this->assertNull($this->configuration->getApp());
        $this->assertNull($this->configuration->getVersion());
        $this->assertIsArray($this->configuration->getRegisters());
        $this->assertIsArray($this->configuration->getSchemas());
        $this->assertIsArray($this->configuration->getObjects());
        $this->assertNull($this->configuration->getCreated());
        $this->assertNull($this->configuration->getUpdated());
    }

    public function testTitle(): void
    {
        $title = 'Test Configuration';
        $this->configuration->setTitle($title);
        $this->assertEquals($title, $this->configuration->getTitle());
    }

    public function testDescription(): void
    {
        $description = 'Test Description';
        $this->configuration->setDescription($description);
        $this->assertEquals($description, $this->configuration->getDescription());
    }

    public function testType(): void
    {
        $type = 'string';
        $this->configuration->setType($type);
        $this->assertEquals($type, $this->configuration->getType());
    }

    public function testApp(): void
    {
        $app = 'openregister';
        $this->configuration->setApp($app);
        $this->assertEquals($app, $this->configuration->getApp());
    }

    public function testVersion(): void
    {
        $version = '1.0.0';
        $this->configuration->setVersion($version);
        $this->assertEquals($version, $this->configuration->getVersion());
    }

    public function testRegisters(): void
    {
        $registers = ['register1', 'register2'];
        $this->configuration->setRegisters($registers);
        $this->assertEquals($registers, $this->configuration->getRegisters());
    }

    public function testSchemas(): void
    {
        $schemas = ['schema1', 'schema2'];
        $this->configuration->setSchemas($schemas);
        $this->assertEquals($schemas, $this->configuration->getSchemas());
    }

    public function testObjects(): void
    {
        $objects = ['object1', 'object2'];
        $this->configuration->setObjects($objects);
        $this->assertEquals($objects, $this->configuration->getObjects());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->configuration->setCreated($created);
        $this->assertEquals($created, $this->configuration->getCreated());
    }

    public function testUpdated(): void
    {
        $updated = new DateTime('2024-01-02 00:00:00');
        $this->configuration->setUpdated($updated);
        $this->assertEquals($updated, $this->configuration->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->configuration->setTitle('Test Config');
        $this->configuration->setDescription('Test Description');
        $this->configuration->setType('string');
        $this->configuration->setApp('openregister');
        
        $json = $this->configuration->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('Test Config', $json['title']);
        $this->assertEquals('Test Description', $json['description']);
        $this->assertEquals('string', $json['type']);
        $this->assertEquals('openregister', $json['app']);
    }
}
