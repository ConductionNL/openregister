<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DataAccessProfile;
use DateTime;
use PHPUnit\Framework\TestCase;

class DataAccessProfileTest extends TestCase
{
    private DataAccessProfile $dataAccessProfile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataAccessProfile = new DataAccessProfile();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(DataAccessProfile::class, $this->dataAccessProfile);
        $this->assertNull($this->dataAccessProfile->getUuid());
        $this->assertNull($this->dataAccessProfile->getName());
        $this->assertNull($this->dataAccessProfile->getDescription());
        $this->assertIsArray($this->dataAccessProfile->getPermissions());
        $this->assertNull($this->dataAccessProfile->getCreated());
        $this->assertNull($this->dataAccessProfile->getUpdated());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->dataAccessProfile->setUuid($uuid);
        $this->assertEquals($uuid, $this->dataAccessProfile->getUuid());
    }

    public function testName(): void
    {
        $name = 'Test Profile';
        $this->dataAccessProfile->setName($name);
        $this->assertEquals($name, $this->dataAccessProfile->getName());
    }

    public function testDescription(): void
    {
        $description = 'Test Description';
        $this->dataAccessProfile->setDescription($description);
        $this->assertEquals($description, $this->dataAccessProfile->getDescription());
    }

    public function testPermissions(): void
    {
        $permissions = ['read', 'write', 'delete'];
        $this->dataAccessProfile->setPermissions($permissions);
        $this->assertEquals($permissions, $this->dataAccessProfile->getPermissions());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->dataAccessProfile->setCreated($created);
        $this->assertEquals($created, $this->dataAccessProfile->getCreated());
    }

    public function testUpdated(): void
    {
        $updated = new DateTime('2024-01-02 00:00:00');
        $this->dataAccessProfile->setUpdated($updated);
        $this->assertEquals($updated, $this->dataAccessProfile->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->dataAccessProfile->setUuid('test-uuid');
        $this->dataAccessProfile->setName('Test Profile');
        $this->dataAccessProfile->setDescription('Test Description');
        $this->dataAccessProfile->setPermissions(['read', 'write']);
        
        $json = $this->dataAccessProfile->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('test-uuid', $json['uuid']);
        $this->assertEquals('Test Profile', $json['name']);
        $this->assertEquals('Test Description', $json['description']);
        $this->assertEquals(['read', 'write'], $json['permissions']);
    }

}
