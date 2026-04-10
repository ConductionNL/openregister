<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Configuration;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->configuration = new Configuration();
    }

    // --- Constructor and field type registration ---

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->configuration->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['id']);
        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['title']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('string', $fieldTypes['type']);
        $this->assertSame('string', $fieldTypes['app']);
        $this->assertSame('string', $fieldTypes['version']);
        $this->assertSame('string', $fieldTypes['sourceType']);
        $this->assertSame('string', $fieldTypes['sourceUrl']);
        $this->assertSame('string', $fieldTypes['localVersion']);
        $this->assertSame('string', $fieldTypes['remoteVersion']);
        $this->assertSame('datetime', $fieldTypes['lastChecked']);
        $this->assertSame('boolean', $fieldTypes['autoUpdate']);
        $this->assertSame('json', $fieldTypes['notificationGroups']);
        $this->assertSame('string', $fieldTypes['githubRepo']);
        $this->assertSame('string', $fieldTypes['githubBranch']);
        $this->assertSame('string', $fieldTypes['githubPath']);
        $this->assertSame('boolean', $fieldTypes['isLocal']);
        $this->assertSame('boolean', $fieldTypes['syncEnabled']);
        $this->assertSame('integer', $fieldTypes['syncInterval']);
        $this->assertSame('datetime', $fieldTypes['lastSyncDate']);
        $this->assertSame('string', $fieldTypes['syncStatus']);
        $this->assertSame('string', $fieldTypes['openregister']);
        $this->assertSame('json', $fieldTypes['registers']);
        $this->assertSame('json', $fieldTypes['schemas']);
        $this->assertSame('json', $fieldTypes['objects']);
        $this->assertSame('json', $fieldTypes['views']);
        $this->assertSame('json', $fieldTypes['agents']);
        $this->assertSame('json', $fieldTypes['sources']);
        $this->assertSame('json', $fieldTypes['applications']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('string', $fieldTypes['owner']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->configuration->getUuid());
        $this->assertNull($this->configuration->getTitle());
        $this->assertNull($this->configuration->getDescription());
        $this->assertNull($this->configuration->getType());
        $this->assertNull($this->configuration->getApp());
        $this->assertNull($this->configuration->getVersion());
        $this->assertNull($this->configuration->getSourceType());
        $this->assertNull($this->configuration->getSourceUrl());
        $this->assertNull($this->configuration->getLocalVersion());
        $this->assertNull($this->configuration->getRemoteVersion());
        $this->assertNull($this->configuration->getLastChecked());
        $this->assertNull($this->configuration->getGithubRepo());
        $this->assertNull($this->configuration->getGithubBranch());
        $this->assertNull($this->configuration->getGithubPath());
        $this->assertNull($this->configuration->getOpenregister());
        $this->assertNull($this->configuration->getOrganisation());
        $this->assertNull($this->configuration->getOwner());
        $this->assertNull($this->configuration->getCreated());
        $this->assertNull($this->configuration->getUpdated());
        $this->assertNull($this->configuration->getLastSyncDate());
        $this->assertSame('never', $this->configuration->getSyncStatus());
    }

    // --- Getters and setters ---

    public function testSetAndGetUuid(): void
    {
        $this->configuration->setUuid('config-uuid-123');
        $this->assertSame('config-uuid-123', $this->configuration->getUuid());
    }

    public function testSetAndGetTitle(): void
    {
        $this->configuration->setTitle('My Configuration');
        $this->assertSame('My Configuration', $this->configuration->getTitle());
    }

    public function testSetAndGetDescription(): void
    {
        $this->configuration->setDescription('A test configuration');
        $this->assertSame('A test configuration', $this->configuration->getDescription());
    }

    public function testSetAndGetType(): void
    {
        $this->configuration->setType('openregister');
        $this->assertSame('openregister', $this->configuration->getType());
    }

    public function testSetAndGetApp(): void
    {
        $this->configuration->setApp('opencatalogi');
        $this->assertSame('opencatalogi', $this->configuration->getApp());
    }

    public function testSetAndGetVersion(): void
    {
        $this->configuration->setVersion('1.0.0');
        $this->assertSame('1.0.0', $this->configuration->getVersion());
    }

    public function testSetAndGetSourceType(): void
    {
        $this->configuration->setSourceType('github');
        $this->assertSame('github', $this->configuration->getSourceType());
    }

    public function testSetAndGetSourceUrl(): void
    {
        $this->configuration->setSourceUrl('https://github.com/repo/config.json');
        $this->assertSame('https://github.com/repo/config.json', $this->configuration->getSourceUrl());
    }

    public function testSetAndGetLocalVersion(): void
    {
        $this->configuration->setLocalVersion('1.0.0');
        $this->assertSame('1.0.0', $this->configuration->getLocalVersion());
    }

    public function testSetAndGetRemoteVersion(): void
    {
        $this->configuration->setRemoteVersion('1.1.0');
        $this->assertSame('1.1.0', $this->configuration->getRemoteVersion());
    }

    public function testSetAndGetLastChecked(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->configuration->setLastChecked($dt);
        $this->assertSame($dt, $this->configuration->getLastChecked());
    }

    public function testSetAndGetAutoUpdate(): void
    {
        $this->configuration->setAutoUpdate(true);
        $this->assertTrue($this->configuration->getAutoUpdate());
    }

    public function testSetAndGetNotificationGroups(): void
    {
        $groups = ['admins', 'devops'];
        $this->configuration->setNotificationGroups($groups);
        $this->assertSame($groups, $this->configuration->getNotificationGroups());
    }

    public function testSetAndGetGithubRepo(): void
    {
        $this->configuration->setGithubRepo('ConductionNL/openregister');
        $this->assertSame('ConductionNL/openregister', $this->configuration->getGithubRepo());
    }

    public function testSetAndGetGithubBranch(): void
    {
        $this->configuration->setGithubBranch('main');
        $this->assertSame('main', $this->configuration->getGithubBranch());
    }

    public function testSetAndGetGithubPath(): void
    {
        $this->configuration->setGithubPath('configs/default.json');
        $this->assertSame('configs/default.json', $this->configuration->getGithubPath());
    }

    public function testSetAndGetIsLocal(): void
    {
        $this->configuration->setIsLocal(false);
        $this->assertFalse($this->configuration->getIsLocal());
    }

    public function testSetAndGetSyncEnabled(): void
    {
        $this->configuration->setSyncEnabled(true);
        $this->assertTrue($this->configuration->getSyncEnabled());
    }

    public function testSetAndGetSyncInterval(): void
    {
        $this->configuration->setSyncInterval(12);
        $this->assertSame(12, $this->configuration->getSyncInterval());
    }

    public function testSetAndGetLastSyncDate(): void
    {
        $dt = new DateTime('2024-06-01 00:00:00');
        $this->configuration->setLastSyncDate($dt);
        $this->assertSame($dt, $this->configuration->getLastSyncDate());
    }

    public function testSetAndGetSyncStatus(): void
    {
        $this->configuration->setSyncStatus('success');
        $this->assertSame('success', $this->configuration->getSyncStatus());
    }

    public function testSetAndGetOpenregister(): void
    {
        $this->configuration->setOpenregister('^v8.14.0');
        $this->assertSame('^v8.14.0', $this->configuration->getOpenregister());
    }

    public function testSetAndGetRegisters(): void
    {
        $this->configuration->setRegisters([1, 2, 3]);
        $this->assertSame([1, 2, 3], $this->configuration->getRegisters());
    }

    public function testSetAndGetSchemas(): void
    {
        $this->configuration->setSchemas([10, 20]);
        $this->assertSame([10, 20], $this->configuration->getSchemas());
    }

    public function testSetAndGetObjects(): void
    {
        $this->configuration->setObjects([100, 200]);
        $this->assertSame([100, 200], $this->configuration->getObjects());
    }

    public function testSetAndGetViews(): void
    {
        $this->configuration->setViews([5, 6]);
        $this->assertSame([5, 6], $this->configuration->getViews());
    }

    public function testSetAndGetAgents(): void
    {
        $this->configuration->setAgents([7, 8]);
        $this->assertSame([7, 8], $this->configuration->getAgents());
    }

    public function testSetAndGetSources(): void
    {
        $this->configuration->setSources([9, 10]);
        $this->assertSame([9, 10], $this->configuration->getSources());
    }

    public function testSetAndGetApplications(): void
    {
        $this->configuration->setApplications([42, 99]);
        $this->assertSame([42, 99], $this->configuration->getApplications());
    }

    public function testSetAndGetOrganisation(): void
    {
        $this->configuration->setOrganisation('org-uuid');
        $this->assertSame('org-uuid', $this->configuration->getOrganisation());
    }

    public function testSetAndGetOwner(): void
    {
        $this->configuration->setOwner('admin');
        $this->assertSame('admin', $this->configuration->getOwner());
    }

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-01-15 10:30:00');
        $this->configuration->setCreated($dt);
        $this->assertSame($dt, $this->configuration->getCreated());
    }

    public function testSetAndGetUpdated(): void
    {
        $dt = new DateTime('2024-02-20 14:00:00');
        $this->configuration->setUpdated($dt);
        $this->assertSame($dt, $this->configuration->getUpdated());
    }

    // --- isValidUuid ---

    public function testIsValidUuidWithValidUuid(): void
    {
        $this->assertTrue(Configuration::isValidUuid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testIsValidUuidWithInvalidUuid(): void
    {
        $this->assertFalse(Configuration::isValidUuid('not-a-uuid'));
    }

    public function testIsValidUuidWithEmptyString(): void
    {
        $this->assertFalse(Configuration::isValidUuid(''));
    }

    // --- getJsonFields ---

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->configuration->getJsonFields();
        $this->assertContains('notificationGroups', $jsonFields);
        $this->assertContains('registers', $jsonFields);
        $this->assertContains('schemas', $jsonFields);
        $this->assertContains('objects', $jsonFields);
        $this->assertContains('views', $jsonFields);
        $this->assertContains('agents', $jsonFields);
        $this->assertContains('sources', $jsonFields);
        $this->assertContains('applications', $jsonFields);
        $this->assertNotContains('title', $jsonFields);
        $this->assertNotContains('uuid', $jsonFields);
    }

    // --- hydrate ---

    public function testHydrateBasicFields(): void
    {
        $data = [
            'title'       => 'Hydrated Config',
            'description' => 'From hydrate',
            'type'        => 'openregister',
            'app'         => 'opencatalogi',
            'version'     => '2.0.0',
            'owner'       => 'testuser',
        ];
        $result = $this->configuration->hydrate($data);
        $this->assertSame('Hydrated Config', $this->configuration->getTitle());
        $this->assertSame('From hydrate', $this->configuration->getDescription());
        $this->assertSame('openregister', $this->configuration->getType());
        $this->assertSame('opencatalogi', $this->configuration->getApp());
        $this->assertSame('2.0.0', $this->configuration->getVersion());
        $this->assertSame('testuser', $this->configuration->getOwner());
        $this->assertSame($this->configuration, $result);
    }

    public function testHydrateApplicationMapsToApp(): void
    {
        $data = [
            'application' => 'my-app',
        ];
        $this->configuration->hydrate($data);
        $this->assertSame('my-app', $this->configuration->getApp());
    }

    public function testHydrateApplicationDoesNotOverrideApp(): void
    {
        $data = [
            'app'         => 'explicit-app',
            'application' => 'should-be-ignored',
        ];
        $this->configuration->hydrate($data);
        $this->assertSame('explicit-app', $this->configuration->getApp());
    }

    public function testHydrateJsonFieldsEmptyArray(): void
    {
        $data = [
            'registers' => [],
            'schemas'   => [],
        ];
        $this->configuration->hydrate($data);
        $this->assertNull($this->configuration->getRegisters());
        $this->assertNull($this->configuration->getSchemas());
    }

    public function testHydrateIgnoresInvalidProperties(): void
    {
        $data = [
            'title'           => 'Valid',
            'nonExistentProp' => 'should be ignored',
        ];
        $this->configuration->hydrate($data);
        $this->assertSame('Valid', $this->configuration->getTitle());
    }

    public function testHydrateWithJsonFieldsPopulated(): void
    {
        $data = [
            'registers'    => [1, 2, 3],
            'schemas'      => [10, 20],
            'applications' => [42],
        ];
        $this->configuration->hydrate($data);
        $this->assertSame([1, 2, 3], $this->configuration->getRegisters());
        $this->assertSame([10, 20], $this->configuration->getSchemas());
        $this->assertSame([42], $this->configuration->getApplications());
    }

    public function testHydrateSyncFields(): void
    {
        $data = [
            'sourceType'   => 'github',
            'sourceUrl'    => 'https://github.com/repo',
            'isLocal'      => false,
            'syncEnabled'  => true,
            'syncInterval' => 12,
            'syncStatus'   => 'success',
        ];
        $this->configuration->hydrate($data);
        $this->assertSame('github', $this->configuration->getSourceType());
        $this->assertSame('https://github.com/repo', $this->configuration->getSourceUrl());
        $this->assertFalse($this->configuration->getIsLocal());
        $this->assertTrue($this->configuration->getSyncEnabled());
        $this->assertSame(12, $this->configuration->getSyncInterval());
        $this->assertSame('success', $this->configuration->getSyncStatus());
    }

    // --- hasUpdateAvailable ---

    public function testHasUpdateAvailableTrue(): void
    {
        $this->configuration->setLocalVersion('1.0.0');
        $this->configuration->setRemoteVersion('1.1.0');
        $this->assertTrue($this->configuration->hasUpdateAvailable());
    }

    public function testHasUpdateAvailableFalseSameVersion(): void
    {
        $this->configuration->setLocalVersion('1.0.0');
        $this->configuration->setRemoteVersion('1.0.0');
        $this->assertFalse($this->configuration->hasUpdateAvailable());
    }

    public function testHasUpdateAvailableFalseLocalNewer(): void
    {
        $this->configuration->setLocalVersion('2.0.0');
        $this->configuration->setRemoteVersion('1.0.0');
        $this->assertFalse($this->configuration->hasUpdateAvailable());
    }

    public function testHasUpdateAvailableFalseRemoteNull(): void
    {
        $this->configuration->setLocalVersion('1.0.0');
        $this->assertFalse($this->configuration->hasUpdateAvailable());
    }

    public function testHasUpdateAvailableFalseLocalNull(): void
    {
        $this->configuration->setRemoteVersion('1.0.0');
        $this->assertFalse($this->configuration->hasUpdateAvailable());
    }

    public function testHasUpdateAvailableFalseBothNull(): void
    {
        $this->assertFalse($this->configuration->hasUpdateAvailable());
    }

    // --- isRemoteSource ---

    public function testIsRemoteSourceGithub(): void
    {
        $this->configuration->setSourceType('github');
        $this->assertTrue($this->configuration->isRemoteSource());
    }

    public function testIsRemoteSourceGitlab(): void
    {
        $this->configuration->setSourceType('gitlab');
        $this->assertTrue($this->configuration->isRemoteSource());
    }

    public function testIsRemoteSourceUrl(): void
    {
        $this->configuration->setSourceType('url');
        $this->assertTrue($this->configuration->isRemoteSource());
    }

    public function testIsRemoteSourceFalseForLocal(): void
    {
        $this->configuration->setSourceType('local');
        $this->assertFalse($this->configuration->isRemoteSource());
    }

    public function testIsRemoteSourceFalseForManual(): void
    {
        $this->configuration->setSourceType('manual');
        $this->assertFalse($this->configuration->isRemoteSource());
    }

    public function testIsRemoteSourceFalseForNull(): void
    {
        $this->assertFalse($this->configuration->isRemoteSource());
    }

    // --- isLocalSource ---

    public function testIsLocalSourceTrue(): void
    {
        $this->configuration->setSourceType('local');
        $this->assertTrue($this->configuration->isLocalSource());
    }

    public function testIsLocalSourceFalse(): void
    {
        $this->configuration->setSourceType('github');
        $this->assertFalse($this->configuration->isLocalSource());
    }

    public function testIsLocalSourceFalseForNull(): void
    {
        $this->assertFalse($this->configuration->isLocalSource());
    }

    // --- isManualSource ---

    public function testIsManualSourceTrue(): void
    {
        $this->configuration->setSourceType('manual');
        $this->assertTrue($this->configuration->isManualSource());
    }

    public function testIsManualSourceFalse(): void
    {
        $this->configuration->setSourceType('github');
        $this->assertFalse($this->configuration->isManualSource());
    }

    public function testIsManualSourceFalseForNull(): void
    {
        $this->assertFalse($this->configuration->isManualSource());
    }

    // --- jsonSerialize ---

    public function testJsonSerializeStructure(): void
    {
        $this->configuration->setUuid('config-uuid');
        $this->configuration->setTitle('Test Config');
        $this->configuration->setDescription('Description');
        $this->configuration->setType('openregister');
        $this->configuration->setApp('opencatalogi');
        $this->configuration->setVersion('1.0.0');
        $this->configuration->setSourceType('github');
        $this->configuration->setSourceUrl('https://github.com/repo');
        $this->configuration->setLocalVersion('1.0.0');
        $this->configuration->setRemoteVersion('1.1.0');
        $this->configuration->setAutoUpdate(true);
        $this->configuration->setGithubRepo('ConductionNL/openregister');
        $this->configuration->setGithubBranch('main');
        $this->configuration->setGithubPath('configs/');
        $this->configuration->setIsLocal(false);
        $this->configuration->setSyncEnabled(true);
        $this->configuration->setSyncInterval(12);
        $this->configuration->setSyncStatus('success');
        $this->configuration->setOpenregister('^v8.14.0');
        $this->configuration->setOrganisation('org-uuid');
        $this->configuration->setOwner('admin');

        $json = $this->configuration->jsonSerialize();

        $this->assertArrayHasKey('id', $json);
        $this->assertSame('config-uuid', $json['uuid']);
        $this->assertSame('Test Config', $json['title']);
        $this->assertSame('Description', $json['description']);
        $this->assertSame('openregister', $json['type']);
        $this->assertSame('opencatalogi', $json['app']);
        $this->assertSame('opencatalogi', $json['application']);
        $this->assertSame('1.0.0', $json['version']);
        $this->assertSame('github', $json['sourceType']);
        $this->assertSame('https://github.com/repo', $json['sourceUrl']);
        $this->assertSame('1.0.0', $json['localVersion']);
        $this->assertSame('1.1.0', $json['remoteVersion']);
        $this->assertTrue($json['autoUpdate']);
        $this->assertSame('ConductionNL/openregister', $json['githubRepo']);
        $this->assertSame('main', $json['githubBranch']);
        $this->assertSame('configs/', $json['githubPath']);
        $this->assertFalse($json['isLocal']);
        $this->assertTrue($json['syncEnabled']);
        $this->assertSame(12, $json['syncInterval']);
        $this->assertSame('success', $json['syncStatus']);
        $this->assertSame('^v8.14.0', $json['openregister']);
        $this->assertSame('org-uuid', $json['organisation']);
        $this->assertSame('admin', $json['owner']);
        $this->assertArrayHasKey('registers', $json);
        $this->assertArrayHasKey('schemas', $json);
        $this->assertArrayHasKey('objects', $json);
        $this->assertArrayHasKey('views', $json);
        $this->assertArrayHasKey('agents', $json);
        $this->assertArrayHasKey('sources', $json);
        $this->assertArrayHasKey('applications', $json);
        $this->assertArrayHasKey('created', $json);
        $this->assertArrayHasKey('updated', $json);
    }

    public function testJsonSerializeAppAndApplicationAlias(): void
    {
        $this->configuration->setApp('myapp');
        $json = $this->configuration->jsonSerialize();
        $this->assertSame('myapp', $json['app']);
        $this->assertSame('myapp', $json['application']);
    }

    public function testJsonSerializeDatesFormatted(): void
    {
        $created = new DateTime('2024-01-15 10:30:00');
        $updated = new DateTime('2024-02-20 14:00:00');
        $lastChecked = new DateTime('2024-03-01 00:00:00');
        $lastSync = new DateTime('2024-03-02 12:00:00');

        $this->configuration->setCreated($created);
        $this->configuration->setUpdated($updated);
        $this->configuration->setLastChecked($lastChecked);
        $this->configuration->setLastSyncDate($lastSync);

        $json = $this->configuration->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($updated->format('c'), $json['updated']);
        $this->assertSame($lastChecked->format('c'), $json['lastChecked']);
        $this->assertSame($lastSync->format('c'), $json['lastSyncDate']);
    }

    public function testJsonSerializeDatesNullWhenNotSet(): void
    {
        $json = $this->configuration->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
        $this->assertNull($json['lastChecked']);
        $this->assertNull($json['lastSyncDate']);
    }

    // --- __toString ---

    public function testToStringReturnsTitle(): void
    {
        $this->configuration->setTitle('My Config Title');
        $this->assertSame('My Config Title', (string) $this->configuration);
    }

    public function testToStringFallsBackToType(): void
    {
        $this->configuration->setType('openregister');
        $this->assertSame('Config: openregister', (string) $this->configuration);
    }

    public function testToStringFallsBackToId(): void
    {
        $reflection = new \ReflectionProperty($this->configuration, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($this->configuration, 42);

        $this->assertSame('Configuration #42', (string) $this->configuration);
    }

    public function testToStringFinalFallback(): void
    {
        $this->assertSame('Configuration', (string) $this->configuration);
    }

    public function testToStringTitlePrecedence(): void
    {
        $this->configuration->setTitle('Title');
        $this->configuration->setType('type');
        $reflection = new \ReflectionProperty($this->configuration, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($this->configuration, 1);

        $this->assertSame('Title', (string) $this->configuration);
    }

    public function testToStringTypePrecedenceOverId(): void
    {
        $this->configuration->setType('type');
        $reflection = new \ReflectionProperty($this->configuration, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($this->configuration, 1);

        $this->assertSame('Config: type', (string) $this->configuration);
    }

    public function testToStringEmptyTitleFallsToType(): void
    {
        $this->configuration->setTitle('');
        $this->configuration->setType('openregister');
        $this->assertSame('Config: openregister', (string) $this->configuration);
    }

    public function testToStringEmptyTypeFallsToId(): void
    {
        $this->configuration->setTitle('');
        $this->configuration->setType('');
        $reflection = new \ReflectionProperty($this->configuration, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($this->configuration, 7);

        $this->assertSame('Configuration #7', (string) $this->configuration);
    }
}
