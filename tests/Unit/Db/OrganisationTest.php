<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Organisation;
use DateTime;
use PHPUnit\Framework\TestCase;

class OrganisationTest extends TestCase
{
    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organisation = new Organisation();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(Organisation::class, $this->organisation);
        $this->assertNull($this->organisation->getUuid());
        $this->assertNull($this->organisation->getSlug());
        $this->assertNull($this->organisation->getName());
        $this->assertNull($this->organisation->getDescription());
        $this->assertIsArray($this->organisation->getUsers());
        $this->assertNull($this->organisation->getOwner());
        $this->assertNull($this->organisation->getCreated());
        $this->assertNull($this->organisation->getUpdated());
        $this->assertFalse($this->organisation->getIsDefault());
        $this->assertTrue($this->organisation->getActive());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->organisation->setUuid($uuid);
        $this->assertEquals($uuid, $this->organisation->getUuid());
    }

    public function testSlug(): void
    {
        $slug = 'test-organisation';
        $this->organisation->setSlug($slug);
        $this->assertEquals($slug, $this->organisation->getSlug());
    }

    public function testName(): void
    {
        $name = 'Test Organisation';
        $this->organisation->setName($name);
        $this->assertEquals($name, $this->organisation->getName());
    }

    public function testDescription(): void
    {
        $description = 'Test Description';
        $this->organisation->setDescription($description);
        $this->assertEquals($description, $this->organisation->getDescription());
    }

    public function testUsers(): void
    {
        $users = ['user1', 'user2'];
        $this->organisation->setUsers($users);
        $this->assertEquals($users, $this->organisation->getUsers());
    }

    public function testOwner(): void
    {
        $owner = 'owner123';
        $this->organisation->setOwner($owner);
        $this->assertEquals($owner, $this->organisation->getOwner());
    }

    public function testIsDefault(): void
    {
        $this->organisation->setIsDefault(true);
        $this->assertTrue($this->organisation->getIsDefault());
    }

    public function testActive(): void
    {
        $this->organisation->setActive(false);
        $this->assertFalse($this->organisation->getActive());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->organisation->setCreated($created);
        $this->assertEquals($created, $this->organisation->getCreated());
    }

    public function testUpdated(): void
    {
        $updated = new DateTime('2024-01-02 00:00:00');
        $this->organisation->setUpdated($updated);
        $this->assertEquals($updated, $this->organisation->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->organisation->setUuid('test-uuid');
        $this->organisation->setName('Test Organisation');
        $this->organisation->setDescription('Test Description');
        $this->organisation->setSlug('test-org');
        
        $json = $this->organisation->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('test-uuid', $json['uuid']);
        $this->assertEquals('Test Organisation', $json['name']);
        $this->assertEquals('Test Description', $json['description']);
        $this->assertEquals('test-org', $json['slug']);
    }

}
