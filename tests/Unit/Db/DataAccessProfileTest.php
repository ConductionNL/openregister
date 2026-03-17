<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\DataAccessProfile;
use PHPUnit\Framework\TestCase;

class DataAccessProfileTest extends TestCase
{
    private DataAccessProfile $profile;

    protected function setUp(): void
    {
        $this->profile = new DataAccessProfile();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->profile->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('json', $fieldTypes['permissions']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->profile->getUuid());
        $this->assertNull($this->profile->getName());
        $this->assertNull($this->profile->getDescription());
        $this->assertSame([], $this->profile->getPermissions());
        $this->assertNull($this->profile->getCreated());
        $this->assertNull($this->profile->getUpdated());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->profile->setUuid('dap-uuid');
        $this->profile->setName('Admin Profile');
        $this->profile->setDescription('Full access profile');

        $this->assertSame('dap-uuid', $this->profile->getUuid());
        $this->assertSame('Admin Profile', $this->profile->getName());
        $this->assertSame('Full access profile', $this->profile->getDescription());
    }

    public function testSetAndGetPermissions(): void
    {
        $permissions = [
            'read'   => ['schema1', 'schema2'],
            'write'  => ['schema1'],
            'delete' => [],
        ];
        $this->profile->setPermissions($permissions);
        $this->assertSame($permissions, $this->profile->getPermissions());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');

        $this->profile->setCreated($created);
        $this->profile->setUpdated($updated);

        $this->assertSame($created, $this->profile->getCreated());
        $this->assertSame($updated, $this->profile->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->profile->setUuid('json-uuid');
        $this->profile->setName('Test Profile');
        $this->profile->setDescription('Test description');
        $permissions = ['read' => ['all']];
        $this->profile->setPermissions($permissions);

        $json = $this->profile->jsonSerialize();

        $expectedKeys = ['id', 'uuid', 'name', 'description', 'permissions', 'created', 'updated'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame('Test Profile', $json['name']);
        $this->assertSame($permissions, $json['permissions']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $updated = new DateTime('2024-06-01T12:00:00+00:00');
        $this->profile->setCreated($created);
        $this->profile->setUpdated($updated);

        $json = $this->profile->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($updated->format('c'), $json['updated']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $json = $this->profile->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }

    public function testToStringWithName(): void
    {
        $this->profile->setName('My Profile');
        $this->assertSame('My Profile', (string)$this->profile);
    }

    public function testToStringWithUuid(): void
    {
        $this->profile->setUuid('fallback-uuid');
        $this->assertSame('fallback-uuid', (string)$this->profile);
    }

    public function testToStringFallback(): void
    {
        $this->assertSame('Data Access Profile', (string)$this->profile);
    }
}
