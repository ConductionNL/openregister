<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectEntity;
use DateTime;
use PHPUnit\Framework\TestCase;

class ObjectEntityTest extends TestCase
{
    private ObjectEntity $objectEntity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectEntity = new ObjectEntity();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(ObjectEntity::class, $this->objectEntity);
        $this->assertNull($this->objectEntity->getUuid());
        $this->assertNull($this->objectEntity->getName());
        $this->assertNull($this->objectEntity->getDescription());
        $this->assertNull($this->objectEntity->getSummary());
        $this->assertNull($this->objectEntity->getImage());
        $this->assertIsArray($this->objectEntity->getObject());
        $this->assertNull($this->objectEntity->getRegister());
        $this->assertNull($this->objectEntity->getSchema());
        $this->assertNull($this->objectEntity->getOrganisation());
        $this->assertNull($this->objectEntity->getCreated());
        $this->assertNull($this->objectEntity->getUpdated());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->objectEntity->setUuid($uuid);
        $this->assertEquals($uuid, $this->objectEntity->getUuid());
    }

    public function testName(): void
    {
        $name = 'Test Object';
        $this->objectEntity->setName($name);
        $this->assertEquals($name, $this->objectEntity->getName());
    }

    public function testDescription(): void
    {
        $description = 'Test Description';
        $this->objectEntity->setDescription($description);
        $this->assertEquals($description, $this->objectEntity->getDescription());
    }

    public function testSummary(): void
    {
        $summary = 'Test Summary';
        $this->objectEntity->setSummary($summary);
        $this->assertEquals($summary, $this->objectEntity->getSummary());
    }

    public function testImage(): void
    {
        $image = 'test-image.jpg';
        $this->objectEntity->setImage($image);
        $this->assertEquals($image, $this->objectEntity->getImage());
    }

    public function testObject(): void
    {
        $object = ['field1' => 'value1', 'field2' => 'value2'];
        $this->objectEntity->setObject($object);
        $result = $this->objectEntity->getObject();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('value1', $result['field1']);
        $this->assertEquals('value2', $result['field2']);
    }

    public function testRegister(): void
    {
        $register = 123;
        $this->objectEntity->setRegister($register);
        $this->assertEquals($register, $this->objectEntity->getRegister());
    }

    public function testSchema(): void
    {
        $schema = 456;
        $this->objectEntity->setSchema($schema);
        $this->assertEquals($schema, $this->objectEntity->getSchema());
    }

    public function testOrganisation(): void
    {
        $organisation = 789;
        $this->objectEntity->setOrganisation($organisation);
        $this->assertEquals($organisation, $this->objectEntity->getOrganisation());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->objectEntity->setCreated($created);
        $this->assertEquals($created, $this->objectEntity->getCreated());
    }

    public function testUpdated(): void
    {
        $updated = new DateTime('2024-01-02 00:00:00');
        $this->objectEntity->setUpdated($updated);
        $this->assertEquals($updated, $this->objectEntity->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->objectEntity->setUuid('test-uuid');
        $this->objectEntity->setName('Test Object');
        $this->objectEntity->setDescription('Test Description');
        $this->objectEntity->setRegister(123);
        $this->objectEntity->setSchema(456);
        
        $json = $this->objectEntity->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertArrayHasKey('@self', $json);
        $this->assertEquals('Test Object', $json['@self']['name']);
        $this->assertEquals('Test Description', $json['@self']['description']);
        $this->assertEquals(123, $json['@self']['register']);
        $this->assertEquals(456, $json['@self']['schema']);
    }
}
