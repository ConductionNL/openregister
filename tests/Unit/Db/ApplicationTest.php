<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        $this->application = new Application();
    }

    // --- Constructor and field type registration ---

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->application->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('string', $fieldTypes['version']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('json', $fieldTypes['configurations']);
        $this->assertSame('json', $fieldTypes['registers']);
        $this->assertSame('json', $fieldTypes['schemas']);
        $this->assertSame('string', $fieldTypes['owner']);
        $this->assertSame('boolean', $fieldTypes['active']);
        $this->assertSame('integer', $fieldTypes['storage_quota']);
        $this->assertSame('integer', $fieldTypes['bandwidth_quota']);
        $this->assertSame('integer', $fieldTypes['request_quota']);
        $this->assertSame('json', $fieldTypes['groups']);
        $this->assertSame('json', $fieldTypes['authorization']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->application->getUuid());
        $this->assertNull($this->application->getName());
        $this->assertNull($this->application->getDescription());
        $this->assertNull($this->application->getVersion());
        $this->assertNull($this->application->getOrganisation());
        $this->assertSame([], $this->application->getConfigurations());
        $this->assertSame([], $this->application->getRegisters());
        $this->assertSame([], $this->application->getSchemas());
        $this->assertNull($this->application->getOwner());
        $this->assertTrue($this->application->isActive());
        $this->assertNull($this->application->getStorageQuota());
        $this->assertNull($this->application->getBandwidthQuota());
        $this->assertNull($this->application->getRequestQuota());
        $this->assertSame([], $this->application->getGroups());
        $this->assertNull($this->application->getCreated());
        $this->assertNull($this->application->getUpdated());
    }

    // --- Getters and setters via __call magic ---

    public function testSetAndGetUuid(): void
    {
        $this->application->setUuid('550e8400-e29b-41d4-a716-446655440000');
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $this->application->getUuid());
    }

    public function testSetAndGetName(): void
    {
        $this->application->setName('Test Application');
        $this->assertSame('Test Application', $this->application->getName());
    }

    public function testSetAndGetDescription(): void
    {
        $this->application->setDescription('A test application description');
        $this->assertSame('A test application description', $this->application->getDescription());
    }

    public function testSetAndGetVersion(): void
    {
        $this->application->setVersion('1.2.3');
        $this->assertSame('1.2.3', $this->application->getVersion());
    }

    public function testSetAndGetOrganisation(): void
    {
        $this->application->setOrganisation('org-uuid-123');
        $this->assertSame('org-uuid-123', $this->application->getOrganisation());
    }

    public function testSetOrganisationNull(): void
    {
        $this->application->setOrganisation('some-org');
        $this->application->setOrganisation(null);
        $this->assertNull($this->application->getOrganisation());
    }

    public function testSetAndGetOwner(): void
    {
        $this->application->setOwner('admin');
        $this->assertSame('admin', $this->application->getOwner());
    }

    public function testSetAndGetStorageQuota(): void
    {
        $this->application->setStorageQuota(1048576);
        $this->assertSame(1048576, $this->application->getStorageQuota());
    }

    public function testSetAndGetBandwidthQuota(): void
    {
        $this->application->setBandwidthQuota(5000000);
        $this->assertSame(5000000, $this->application->getBandwidthQuota());
    }

    public function testSetAndGetRequestQuota(): void
    {
        $this->application->setRequestQuota(1000);
        $this->assertSame(1000, $this->application->getRequestQuota());
    }

    public function testSetAndGetCreated(): void
    {
        $now = new DateTime('2024-01-15 10:30:00');
        $this->application->setCreated($now);
        $this->assertSame($now, $this->application->getCreated());
    }

    public function testSetAndGetUpdated(): void
    {
        $now = new DateTime('2024-02-20 14:00:00');
        $this->application->setUpdated($now);
        $this->assertSame($now, $this->application->getUpdated());
    }

    // --- Configurations, Registers, Schemas (array fields) ---

    public function testSetAndGetConfigurations(): void
    {
        $configs = [1, 2, 3];
        $result = $this->application->setConfigurations($configs);
        $this->assertSame($configs, $this->application->getConfigurations());
        $this->assertSame($this->application, $result);
    }

    public function testSetConfigurationsNull(): void
    {
        $this->application->setConfigurations(null);
        $this->assertSame([], $this->application->getConfigurations());
    }

    public function testSetAndGetRegisters(): void
    {
        $registers = [10, 20];
        $result = $this->application->setRegisters($registers);
        $this->assertSame($registers, $this->application->getRegisters());
        $this->assertSame($this->application, $result);
    }

    public function testSetRegistersNull(): void
    {
        $this->application->setRegisters(null);
        $this->assertSame([], $this->application->getRegisters());
    }

    public function testSetAndGetSchemas(): void
    {
        $schemas = [5, 6, 7];
        $result = $this->application->setSchemas($schemas);
        $this->assertSame($schemas, $this->application->getSchemas());
        $this->assertSame($this->application, $result);
    }

    public function testSetSchemasNull(): void
    {
        $this->application->setSchemas(null);
        $this->assertSame([], $this->application->getSchemas());
    }

    // --- isActive / setActive ---

    public function testIsActiveDefaultTrue(): void
    {
        $this->assertTrue($this->application->isActive());
    }

    public function testSetActiveFalse(): void
    {
        $result = $this->application->setActive(false);
        $this->assertFalse($this->application->isActive());
        $this->assertSame($this->application, $result);
    }

    public function testSetActiveTrue(): void
    {
        $this->application->setActive(false);
        $this->application->setActive(true);
        $this->assertTrue($this->application->isActive());
    }

    public function testSetActiveNull(): void
    {
        $this->application->setActive(null);
        $this->assertTrue($this->application->isActive());
    }

    public function testSetActiveEmptyString(): void
    {
        $this->application->setActive('');
        $this->assertTrue($this->application->isActive());
    }

    public function testSetActiveTruthyString(): void
    {
        $this->application->setActive('1');
        $this->assertTrue($this->application->isActive());
    }

    public function testSetActiveFalsyStringZero(): void
    {
        $this->application->setActive('0');
        $this->assertFalse($this->application->isActive());
    }

    // --- Groups ---

    public function testSetAndGetGroups(): void
    {
        $groups = ['admin', 'users'];
        $result = $this->application->setGroups($groups);
        $this->assertSame($groups, $this->application->getGroups());
        $this->assertSame($this->application, $result);
    }

    public function testSetGroupsNull(): void
    {
        $this->application->setGroups(null);
        $this->assertSame([], $this->application->getGroups());
    }

    // --- Authorization ---

    public function testGetAuthorizationDefault(): void
    {
        $auth = $this->application->getAuthorization();
        $this->assertArrayHasKey('create', $auth);
        $this->assertArrayHasKey('read', $auth);
        $this->assertArrayHasKey('update', $auth);
        $this->assertArrayHasKey('delete', $auth);
        $this->assertSame([], $auth['create']);
        $this->assertSame([], $auth['read']);
        $this->assertSame([], $auth['update']);
        $this->assertSame([], $auth['delete']);
    }

    public function testSetAuthorizationArray(): void
    {
        $auth = [
            'create' => ['admin'],
            'read' => ['*'],
            'update' => ['admin'],
            'delete' => ['admin'],
        ];
        $result = $this->application->setAuthorization($auth);
        $this->assertSame($auth, $this->application->getAuthorization());
        $this->assertSame($this->application, $result);
    }

    public function testSetAuthorizationJsonString(): void
    {
        $auth = [
            'create' => ['admin'],
            'read' => ['*'],
            'update' => [],
            'delete' => [],
        ];
        $this->application->setAuthorization(json_encode($auth));
        $this->assertSame($auth, $this->application->getAuthorization());
    }

    public function testSetAuthorizationInvalidJsonString(): void
    {
        $this->application->setAuthorization('not-valid-json{');
        $auth = $this->application->getAuthorization();
        $this->assertArrayHasKey('create', $auth);
        $this->assertSame([], $auth['create']);
    }

    public function testSetAuthorizationNull(): void
    {
        $this->application->setAuthorization(null);
        $auth = $this->application->getAuthorization();
        $this->assertArrayHasKey('create', $auth);
        $this->assertSame([], $auth['create']);
    }

    // --- getJsonFields ---

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->application->getJsonFields();
        $this->assertContains('configurations', $jsonFields);
        $this->assertContains('registers', $jsonFields);
        $this->assertContains('schemas', $jsonFields);
        $this->assertContains('groups', $jsonFields);
        $this->assertContains('authorization', $jsonFields);
        $this->assertNotContains('name', $jsonFields);
        $this->assertNotContains('uuid', $jsonFields);
    }

    // --- hydrate ---

    public function testHydrateBasicFields(): void
    {
        $data = [
            'name'        => 'Hydrated App',
            'description' => 'From hydrate',
            'version'     => '2.0.0',
            'owner'       => 'testuser',
        ];
        $result = $this->application->hydrate($data);
        $this->assertSame('Hydrated App', $this->application->getName());
        $this->assertSame('From hydrate', $this->application->getDescription());
        $this->assertSame('2.0.0', $this->application->getVersion());
        $this->assertSame('testuser', $this->application->getOwner());
        $this->assertSame($this->application, $result);
    }

    public function testHydrateJsonFieldsEmptyArray(): void
    {
        $data = [
            'configurations' => [],
            'registers'      => [],
        ];
        $this->application->hydrate($data);
        $this->assertSame([], $this->application->getConfigurations());
        $this->assertSame([], $this->application->getRegisters());
    }

    public function testHydrateIgnoresInvalidProperties(): void
    {
        $data = [
            'name'            => 'Valid',
            'nonExistentProp' => 'should be ignored',
        ];
        $this->application->hydrate($data);
        $this->assertSame('Valid', $this->application->getName());
    }

    public function testHydrateWithJsonFieldsPopulated(): void
    {
        $data = [
            'configurations' => [1, 2, 3],
            'schemas'        => [10, 20],
            'groups'         => ['admins'],
        ];
        $this->application->hydrate($data);
        $this->assertSame([1, 2, 3], $this->application->getConfigurations());
        $this->assertSame([10, 20], $this->application->getSchemas());
        $this->assertSame(['admins'], $this->application->getGroups());
    }

    // --- jsonSerialize ---

    public function testJsonSerializeStructure(): void
    {
        $this->application->setUuid('test-uuid');
        $this->application->setName('Test App');
        $this->application->setDescription('Description');
        $this->application->setVersion('1.0.0');
        $this->application->setOrganisation('org-uuid');
        $this->application->setOwner('admin');
        $this->application->setActive(true);
        $this->application->setStorageQuota(1000);
        $this->application->setBandwidthQuota(2000);
        $this->application->setRequestQuota(500);

        $json = $this->application->jsonSerialize();

        $this->assertArrayHasKey('id', $json);
        $this->assertSame('test-uuid', $json['uuid']);
        $this->assertSame('Test App', $json['name']);
        $this->assertSame('Description', $json['description']);
        $this->assertSame('1.0.0', $json['version']);
        $this->assertSame('org-uuid', $json['organisation']);
        $this->assertSame([], $json['configurations']);
        $this->assertSame([], $json['registers']);
        $this->assertSame([], $json['schemas']);
        $this->assertSame('admin', $json['owner']);
        $this->assertTrue($json['active']);
        $this->assertSame([], $json['groups']);
        $this->assertArrayHasKey('quota', $json);
        $this->assertArrayHasKey('usage', $json);
        $this->assertArrayHasKey('authorization', $json);
        $this->assertArrayHasKey('created', $json);
        $this->assertArrayHasKey('updated', $json);
        $this->assertArrayHasKey('managedByConfiguration', $json);
    }

    public function testJsonSerializeQuotaStructure(): void
    {
        $this->application->setStorageQuota(1000);
        $this->application->setBandwidthQuota(2000);
        $this->application->setRequestQuota(500);

        $json = $this->application->jsonSerialize();
        $quota = $json['quota'];

        $this->assertSame(1000, $quota['storage']);
        $this->assertSame(2000, $quota['bandwidth']);
        $this->assertSame(500, $quota['requests']);
        $this->assertNull($quota['users']);
        $this->assertNull($quota['groups']);
    }

    public function testJsonSerializeUsageStructure(): void
    {
        $this->application->setGroups(['g1', 'g2', 'g3']);

        $json = $this->application->jsonSerialize();
        $usage = $json['usage'];

        $this->assertSame(0, $usage['storage']);
        $this->assertSame(0, $usage['bandwidth']);
        $this->assertSame(0, $usage['requests']);
        $this->assertSame(0, $usage['users']);
        $this->assertSame(3, $usage['groups']);
    }

    public function testJsonSerializeDatesFormatted(): void
    {
        $created = new DateTime('2024-01-15 10:30:00');
        $updated = new DateTime('2024-02-20 14:00:00');
        $this->application->setCreated($created);
        $this->application->setUpdated($updated);

        $json = $this->application->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($updated->format('c'), $json['updated']);
    }

    public function testJsonSerializeDatesNullWhenNotSet(): void
    {
        $json = $this->application->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }

    public function testJsonSerializeManagedByConfigurationNull(): void
    {
        $json = $this->application->jsonSerialize();
        $this->assertNull($json['managedByConfiguration']);
    }

    public function testJsonSerializeManagedByConfigurationSet(): void
    {
        $config = new Configuration();
        $config->setUuid('config-uuid');
        $config->setTitle('My Config');

        $this->application->setManagedByConfigurationEntity($config);

        $json = $this->application->jsonSerialize();
        $this->assertNotNull($json['managedByConfiguration']);
        $this->assertSame('config-uuid', $json['managedByConfiguration']['uuid']);
        $this->assertSame('My Config', $json['managedByConfiguration']['title']);
    }

    // --- __toString ---

    public function testToStringReturnsUuid(): void
    {
        $this->application->setUuid('my-uuid-123');
        $this->assertSame('my-uuid-123', (string) $this->application);
    }

    public function testToStringGeneratesUuidWhenNull(): void
    {
        $result = (string) $this->application;
        $this->assertNotEmpty($result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result
        );
    }

    public function testToStringGeneratesUuidWhenEmpty(): void
    {
        $this->application->setUuid('');
        $result = (string) $this->application;
        $this->assertNotEmpty($result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result
        );
    }

    // --- isValidUuid ---

    public function testIsValidUuidWithValidUuid(): void
    {
        $this->assertTrue(Application::isValidUuid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testIsValidUuidWithInvalidUuid(): void
    {
        $this->assertFalse(Application::isValidUuid('not-a-uuid'));
    }

    public function testIsValidUuidWithEmptyString(): void
    {
        $this->assertFalse(Application::isValidUuid(''));
    }

    // --- isManagedByConfiguration ---

    public function testIsManagedByConfigurationTrue(): void
    {
        $reflection = new \ReflectionProperty($this->application, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($this->application, 42);

        $config = new Configuration();
        $config->setApplications([42, 99]);

        $this->assertTrue($this->application->isManagedByConfiguration([$config]));
    }

    public function testIsManagedByConfigurationFalse(): void
    {
        $reflection = new \ReflectionProperty($this->application, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($this->application, 42);

        $config = new Configuration();
        $config->setApplications([99, 100]);

        $this->assertFalse($this->application->isManagedByConfiguration([$config]));
    }

    public function testIsManagedByConfigurationEmptyConfigurations(): void
    {
        $this->assertFalse($this->application->isManagedByConfiguration([]));
    }

    public function testIsManagedByConfigurationNullId(): void
    {
        $config = new Configuration();
        $config->setApplications([1, 2]);

        $this->assertFalse($this->application->isManagedByConfiguration([$config]));
    }

    // --- getManagedByConfiguration ---

    public function testGetManagedByConfigurationReturnsConfig(): void
    {
        $reflection = new \ReflectionProperty($this->application, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($this->application, 42);

        $config1 = new Configuration();
        $config1->setApplications([10, 20]);

        $config2 = new Configuration();
        $config2->setApplications([42, 99]);

        $result = $this->application->getManagedByConfiguration([$config1, $config2]);
        $this->assertSame($config2, $result);
    }

    public function testGetManagedByConfigurationReturnsNull(): void
    {
        $reflection = new \ReflectionProperty($this->application, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($this->application, 42);

        $config = new Configuration();
        $config->setApplications([10]);

        $this->assertNull($this->application->getManagedByConfiguration([$config]));
    }

    public function testGetManagedByConfigurationEmptyArray(): void
    {
        $this->assertNull($this->application->getManagedByConfiguration([]));
    }

    // --- ManagedByConfigurationEntity (transient property) ---

    public function testSetAndGetManagedByConfigurationEntity(): void
    {
        $config = new Configuration();
        $this->application->setManagedByConfigurationEntity($config);
        $this->assertSame($config, $this->application->getManagedByConfigurationEntity());
    }

    public function testGetManagedByConfigurationEntityDefaultNull(): void
    {
        $this->assertNull($this->application->getManagedByConfigurationEntity());
    }

    public function testSetManagedByConfigurationEntityNull(): void
    {
        $config = new Configuration();
        $this->application->setManagedByConfigurationEntity($config);
        $this->application->setManagedByConfigurationEntity(null);
        $this->assertNull($this->application->getManagedByConfigurationEntity());
    }
}
