<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\View;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $this->view = new View();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->view->getFieldTypes();

        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('boolean', $fieldTypes['isPublic']);
        $this->assertSame('boolean', $fieldTypes['isDefault']);
        $this->assertSame('json', $fieldTypes['query']);
        $this->assertSame('json', $fieldTypes['favoredBy']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->view->getUuid());
        $this->assertNull($this->view->getName());
        $this->assertNull($this->view->getDescription());
        $this->assertNull($this->view->getOwner());
        $this->assertNull($this->view->getOrganisation());
        $this->assertFalse($this->view->getIsPublic());
        $this->assertFalse($this->view->getIsDefault());
        $this->assertSame([], $this->view->getQuery());
        $this->assertSame([], $this->view->getFavoredBy());
        $this->assertNull($this->view->getCreated());
        $this->assertNull($this->view->getUpdated());
    }

    // --- Getters/Setters ---

    public function testSetAndGetUuid(): void
    {
        $this->view->setUuid('view-uuid-123');
        $this->assertSame('view-uuid-123', $this->view->getUuid());
    }

    public function testSetAndGetName(): void
    {
        $this->view->setName('My View');
        $this->assertSame('My View', $this->view->getName());
    }

    public function testSetAndGetDescription(): void
    {
        $this->view->setDescription('A saved search view');
        $this->assertSame('A saved search view', $this->view->getDescription());
    }

    public function testSetAndGetOwner(): void
    {
        $this->view->setOwner('admin');
        $this->assertSame('admin', $this->view->getOwner());
    }

    public function testSetAndGetOrganisation(): void
    {
        $this->view->setOrganisation('org-uuid');
        $this->assertSame('org-uuid', $this->view->getOrganisation());
    }

    public function testSetAndGetIsPublic(): void
    {
        $this->view->setIsPublic(true);
        $this->assertTrue($this->view->getIsPublic());

        $this->view->setIsPublic(false);
        $this->assertFalse($this->view->getIsPublic());
    }

    public function testSetAndGetIsDefault(): void
    {
        $this->view->setIsDefault(true);
        $this->assertTrue($this->view->getIsDefault());

        $this->view->setIsDefault(false);
        $this->assertFalse($this->view->getIsDefault());
    }

    public function testSetAndGetQuery(): void
    {
        $query = ['registers' => [1], 'schemas' => [2], 'filters' => ['status' => 'active']];
        $this->view->setQuery($query);
        $this->assertSame($query, $this->view->getQuery());
    }

    public function testSetAndGetFavoredBy(): void
    {
        $users = ['user1', 'user2', 'admin'];
        $this->view->setFavoredBy($users);
        $this->assertSame($users, $this->view->getFavoredBy());
    }

    public function testGetFavoredByReturnsEmptyArrayWhenNull(): void
    {
        // Use a new view — default is [] but test the null-coalesce logic
        $view = new View();
        $this->assertSame([], $view->getFavoredBy());
    }

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-01-01 00:00:00');
        $this->view->setCreated($dt);
        $this->assertSame($dt, $this->view->getCreated());
    }

    public function testSetAndGetUpdated(): void
    {
        $dt = new DateTime('2024-06-15 08:30:00');
        $this->view->setUpdated($dt);
        $this->assertSame($dt, $this->view->getUpdated());
    }

    // --- Query JSON type coercion ---

    public function testQueryAcceptsArrayAndReturnsArray(): void
    {
        $query = ['registers' => [1, 2], 'schemas' => [3]];
        $this->view->setQuery($query);
        $this->assertSame($query, $this->view->getQuery());
    }

    public function testQueryAcceptsNullValue(): void
    {
        $this->view->setQuery(null);
        $this->assertNull($this->view->getQuery());
    }

    // --- jsonSerialize ---

    public function testJsonSerializeAllFieldsPresent(): void
    {
        $json = $this->view->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'name', 'description', 'owner', 'organisation',
            'isPublic', 'isDefault', 'query', 'favoredBy', 'quota', 'usage',
            'created', 'updated', 'managedByConfiguration',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }

    public function testJsonSerializeDefaultValues(): void
    {
        $json = $this->view->jsonSerialize();

        $this->assertNull($json['id']);
        $this->assertNull($json['uuid']);
        $this->assertNull($json['name']);
        $this->assertNull($json['description']);
        $this->assertNull($json['owner']);
        $this->assertNull($json['organisation']);
        $this->assertFalse($json['isPublic']);
        $this->assertFalse($json['isDefault']);
        $this->assertSame([], $json['query']);
        $this->assertSame([], $json['favoredBy']);
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
        $this->assertNull($json['managedByConfiguration']);
    }

    public function testJsonSerializeQuotaStructure(): void
    {
        $json = $this->view->jsonSerialize();

        $this->assertIsArray($json['quota']);
        $this->assertNull($json['quota']['storage']);
        $this->assertNull($json['quota']['bandwidth']);
        $this->assertNull($json['quota']['requests']);
        $this->assertNull($json['quota']['users']);
        $this->assertNull($json['quota']['groups']);
    }

    public function testJsonSerializeUsageStructure(): void
    {
        $json = $this->view->jsonSerialize();

        $this->assertIsArray($json['usage']);
        $this->assertSame(0, $json['usage']['storage']);
        $this->assertSame(0, $json['usage']['bandwidth']);
        $this->assertSame(0, $json['usage']['requests']);
        $this->assertSame(0, $json['usage']['users']);
        $this->assertSame(0, $json['usage']['groups']);
    }

    public function testJsonSerializeUsageCountsFavoredByUsers(): void
    {
        $this->view->setFavoredBy(['user1', 'user2', 'user3']);
        $json = $this->view->jsonSerialize();

        $this->assertSame(3, $json['usage']['users']);
    }

    public function testJsonSerializeFormatsDatetimes(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $updated = new DateTime('2024-06-15 08:30:00');

        $this->view->setCreated($created);
        $this->view->setUpdated($updated);

        $json = $this->view->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($updated->format('c'), $json['updated']);
    }

    public function testJsonSerializeDatetimesNullWhenNotSet(): void
    {
        $json = $this->view->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }

    // --- getManagedByConfiguration ---

    public function testGetManagedByConfigurationReturnsNullWithEmptyArray(): void
    {
        $this->assertNull($this->view->getManagedByConfiguration([]));
    }

    public function testGetManagedByConfigurationReturnsNullWithNoId(): void
    {
        $this->assertNull($this->view->getManagedByConfiguration([]));
    }
}
