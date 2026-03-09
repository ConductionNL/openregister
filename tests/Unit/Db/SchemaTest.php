<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    private Schema $schema;

    protected function setUp(): void
    {
        $this->schema = new Schema();
    }

    public function testConstructorFieldTypes(): void
    {
        $types = $this->schema->getFieldTypes();
        $this->assertSame('string', $types['uuid']);
        $this->assertSame('string', $types['uri']);
        $this->assertSame('string', $types['slug']);
        $this->assertSame('string', $types['title']);
        $this->assertSame('string', $types['description']);
        $this->assertSame('string', $types['version']);
        $this->assertSame('string', $types['summary']);
        $this->assertSame('string', $types['icon']);
        $this->assertSame('json', $types['required']);
        $this->assertSame('json', $types['properties']);
        $this->assertSame('json', $types['archive']);
        $this->assertSame('json', $types['facets']);
        $this->assertSame('json', $types['allOf']);
        $this->assertSame('json', $types['oneOf']);
        $this->assertSame('json', $types['anyOf']);
        $this->assertSame('string', $types['source']);
        $this->assertSame('boolean', $types['hardValidation']);
        $this->assertSame('boolean', $types['immutable']);
        $this->assertSame('boolean', $types['searchable']);
        $this->assertSame('datetime', $types['updated']);
        $this->assertSame('datetime', $types['created']);
        $this->assertSame('integer', $types['maxDepth']);
        $this->assertSame('string', $types['owner']);
        $this->assertSame('string', $types['application']);
        $this->assertSame('string', $types['organisation']);
        $this->assertSame('json', $types['authorization']);
        $this->assertSame('datetime', $types['deleted']);
        $this->assertSame('json', $types['configuration']);
        $this->assertSame('json', $types['groups']);
        $this->assertSame('datetime', $types['published']);
        $this->assertSame('datetime', $types['depublished']);
        $this->assertSame('json', $types['hooks']);
    }

    public function testConstructorDefaults(): void
    {
        $this->assertNull($this->schema->getUuid());
        $this->assertNull($this->schema->getUri());
        $this->assertNull($this->schema->getSlug());
        $this->assertNull($this->schema->getTitle());
        $this->assertNull($this->schema->getDescription());
        $this->assertNull($this->schema->getVersion());
        $this->assertNull($this->schema->getSummary());
        $this->assertNull($this->schema->getIcon());
        $this->assertSame([], $this->schema->getRequired());
        $this->assertSame([], $this->schema->getProperties());
        $this->assertSame([], $this->schema->getArchive());
        $this->assertNull($this->schema->getSource());
        $this->assertTrue($this->schema->isSearchable());
        $this->assertNull($this->schema->getOwner());
        $this->assertNull($this->schema->getApplication());
        $this->assertNull($this->schema->getOrganisation());
        $this->assertNull($this->schema->getPublished());
        $this->assertNull($this->schema->getDepublished());
    }

    // --- Getters/Setters ---

    public function testSetAndGetUuid(): void
    {
        $this->schema->setUuid('550e8400-e29b-41d4-a716-446655440000');
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $this->schema->getUuid());
    }

    public function testSetAndGetTitle(): void
    {
        $this->schema->setTitle('My Schema');
        $this->assertSame('My Schema', $this->schema->getTitle());
    }

    public function testSetAndGetUri(): void
    {
        $this->schema->setUri('https://example.com/schema');
        $this->assertSame('https://example.com/schema', $this->schema->getUri());
    }

    public function testSetAndGetVersion(): void
    {
        $this->schema->setVersion('1.0.0');
        $this->assertSame('1.0.0', $this->schema->getVersion());
    }

    public function testSetAndGetOwner(): void
    {
        $this->schema->setOwner('admin');
        $this->assertSame('admin', $this->schema->getOwner());
    }

    public function testSetAndGetSource(): void
    {
        $this->schema->setSource('https://example.com');
        $this->assertSame('https://example.com', $this->schema->getSource());
    }

    // --- Required ---

    public function testGetRequiredReturnsEmptyArrayOnNull(): void
    {
        $this->assertSame([], $this->schema->getRequired());
    }

    public function testGetRequiredReturnsArray(): void
    {
        $this->schema->setRequired(['name', 'email']);
        $this->assertSame(['name', 'email'], $this->schema->getRequired());
    }

    public function testSetRequiredJsonString(): void
    {
        $this->schema->setRequired('["name","email"]');
        $this->assertSame(['name', 'email'], $this->schema->getRequired());
    }

    public function testSetRequiredInvalidJson(): void
    {
        $this->schema->setRequired('not-json{');
        $this->assertSame([], $this->schema->getRequired());
    }

    public function testSetRequiredNull(): void
    {
        $this->schema->setRequired(null);
        $this->assertSame([], $this->schema->getRequired());
    }

    // --- Properties ---

    public function testGetPropertiesReturnsEmptyArrayOnNull(): void
    {
        $this->assertSame([], $this->schema->getProperties());
    }

    public function testSetAndGetProperties(): void
    {
        $props = ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']];
        $this->schema->setProperties($props);
        $this->assertSame($props, $this->schema->getProperties());
    }

    // --- Property Authorization ---

    public function testHasPropertyAuthorizationFalseWhenEmpty(): void
    {
        $this->assertFalse($this->schema->hasPropertyAuthorization());
    }

    public function testHasPropertyAuthorizationFalseWhenNoAuth(): void
    {
        $this->schema->setProperties(['name' => ['type' => 'string']]);
        $this->assertFalse($this->schema->hasPropertyAuthorization());
    }

    public function testHasPropertyAuthorizationTrue(): void
    {
        $this->schema->setProperties([
            'name' => ['type' => 'string', 'authorization' => ['read' => ['admin']]],
        ]);
        $this->assertTrue($this->schema->hasPropertyAuthorization());
    }

    public function testGetPropertyAuthorizationReturnsNull(): void
    {
        $this->assertNull($this->schema->getPropertyAuthorization('nonexistent'));
    }

    public function testGetPropertyAuthorizationReturnsRules(): void
    {
        $auth = ['read' => ['admin']];
        $this->schema->setProperties([
            'secret' => ['type' => 'string', 'authorization' => $auth],
        ]);
        $this->assertSame($auth, $this->schema->getPropertyAuthorization('secret'));
    }

    public function testGetPropertyAuthorizationReturnsNullForEmptyAuth(): void
    {
        $this->schema->setProperties([
            'name' => ['type' => 'string', 'authorization' => []],
        ]);
        $this->assertNull($this->schema->getPropertyAuthorization('name'));
    }

    public function testGetPropertiesWithAuthorization(): void
    {
        $auth = ['read' => ['admin']];
        $this->schema->setProperties([
            'name' => ['type' => 'string'],
            'secret' => ['type' => 'string', 'authorization' => $auth],
            'public' => ['type' => 'string', 'authorization' => []],
        ]);
        $result = $this->schema->getPropertiesWithAuthorization();
        $this->assertCount(1, $result);
        $this->assertSame($auth, $result['secret']);
    }

    // --- Archive ---

    public function testGetArchiveReturnsEmptyArrayOnNull(): void
    {
        $this->assertSame([], $this->schema->getArchive());
    }

    // --- JsonFields ---

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->schema->getJsonFields();
        $this->assertContains('required', $jsonFields);
        $this->assertContains('properties', $jsonFields);
        $this->assertContains('archive', $jsonFields);
        $this->assertContains('facets', $jsonFields);
        $this->assertContains('authorization', $jsonFields);
        $this->assertContains('configuration', $jsonFields);
        $this->assertContains('groups', $jsonFields);
        $this->assertContains('hooks', $jsonFields);
        $this->assertNotContains('uuid', $jsonFields);
        $this->assertNotContains('title', $jsonFields);
    }

    // --- ValidateProperties ---

    public function testValidatePropertiesEmptyReturnsTrue(): void
    {
        $validator = $this->createMock(\OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler::class);
        $validator->expects($this->never())->method('validateProperties');
        $this->assertTrue($this->schema->validateProperties($validator));
    }

    public function testValidatePropertiesDelegatesToValidator(): void
    {
        $props = ['name' => ['type' => 'string']];
        $this->schema->setProperties($props);
        $validator = $this->createMock(\OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler::class);
        $validator->expects($this->once())->method('validateProperties')->with($props)->willReturn(true);
        $this->assertTrue($this->schema->validateProperties($validator));
    }

    // --- hasPermission ---

    public function testHasPermissionAdminGroup(): void
    {
        $this->assertTrue($this->schema->hasPermission('admin', 'read'));
    }

    public function testHasPermissionAdminUserGroup(): void
    {
        $this->assertTrue($this->schema->hasPermission('users', 'read', null, 'admin'));
    }

    public function testHasPermissionOwnerMatch(): void
    {
        $this->assertTrue($this->schema->hasPermission('users', 'read', 'user1', null, 'user1'));
    }

    public function testHasPermissionEmptyAuthReturnsTrue(): void
    {
        $this->assertTrue($this->schema->hasPermission('users', 'read'));
    }

    public function testHasPermissionGroupMatch(): void
    {
        $this->schema->setAuthorization(['read' => ['editors']]);
        $this->assertTrue($this->schema->hasPermission('editors', 'read'));
    }

    public function testHasPermissionGroupNoMatch(): void
    {
        $this->schema->setAuthorization(['read' => ['editors']]);
        $this->assertFalse($this->schema->hasPermission('viewers', 'read'));
    }

    public function testHasPermissionMissingAction(): void
    {
        $this->schema->setAuthorization(['read' => ['editors']]);
        $this->assertTrue($this->schema->hasPermission('viewers', 'delete'));
    }

    public function testHasPermissionComplexEntryWithGroup(): void
    {
        $this->schema->setAuthorization(['read' => [['group' => 'editors']]]);
        $this->assertTrue($this->schema->hasPermission('editors', 'read'));
    }

    public function testHasPermissionComplexEntryWithMatchNotEvaluated(): void
    {
        $this->schema->setAuthorization(['read' => [['group' => 'editors', 'match' => ['field' => 'val']]]]);
        $this->assertFalse($this->schema->hasPermission('editors', 'read'));
    }

    // --- Hydrate ---

    public function testHydrateBasicFields(): void
    {
        $data = ['title' => 'Test', 'description' => 'Desc', 'version' => '1.0'];
        $result = $this->schema->hydrate($data);
        $this->assertSame('Test', $this->schema->getTitle());
        $this->assertSame('Desc', $this->schema->getDescription());
        $this->assertSame('1.0', $this->schema->getVersion());
        $this->assertSame($this->schema, $result);
    }

    public function testHydrateDefaultRequired(): void
    {
        $this->schema->hydrate([]);
        $this->assertSame([], $this->schema->getRequired());
    }

    public function testHydrateDefaultHardValidation(): void
    {
        $this->schema->hydrate([]);
        $this->assertTrue($this->schema->getHardValidation());
    }

    public function testHydrateExplicitFalseHardValidation(): void
    {
        $this->schema->hydrate(['hardValidation' => false]);
        $this->assertFalse($this->schema->getHardValidation());
    }

    public function testHydrateEmptyJsonArraysSetToNull(): void
    {
        $this->schema->hydrate(['properties' => [], 'archive' => []]);
        $this->assertSame([], $this->schema->getProperties());
        $this->assertSame([], $this->schema->getArchive());
    }

    public function testHydrateIgnoresInvalidProperties(): void
    {
        $this->schema->hydrate(['title' => 'Test', 'nonExistent' => 'ignored']);
        $this->assertSame('Test', $this->schema->getTitle());
    }

    public function testHydrateDateTimeStrings(): void
    {
        $this->schema->hydrate(['created' => '2024-01-15T10:30:00+00:00']);
        $this->assertInstanceOf(DateTime::class, $this->schema->getCreated());
    }

    public function testHydrateDateTimeObject(): void
    {
        $dt = new DateTime('2024-01-15');
        $this->schema->hydrate(['created' => $dt]);
        $this->assertSame($dt, $this->schema->getCreated());
    }

    public function testHydrateInvalidDateTimeString(): void
    {
        $this->schema->hydrate(['created' => 'not-a-date']);
        $this->assertNull($this->schema->getCreated());
    }

    public function testHydrateConfigurationJsonString(): void
    {
        $config = ['objectNameField' => 'name'];
        $this->schema->hydrate(['configuration' => json_encode($config)]);
        // setConfiguration goes through fallback path (no Server available)
        $result = $this->schema->getConfiguration();
        $this->assertIsArray($result);
    }

    public function testHydrateWithValidator(): void
    {
        $props = ['name' => ['type' => 'string']];
        $validator = $this->createMock(\OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler::class);
        $validator->expects($this->once())->method('validateProperties')->with($props)->willReturn(true);
        $this->schema->hydrate(['properties' => $props], $validator);
    }

    // --- jsonSerialize ---

    public function testJsonSerializeStructure(): void
    {
        $this->schema->setUuid('test-uuid');
        $this->schema->setTitle('Test');
        $json = $this->schema->jsonSerialize();

        $this->assertArrayHasKey('id', $json);
        $this->assertSame('test-uuid', $json['uuid']);
        $this->assertSame('Test', $json['title']);
        $this->assertArrayHasKey('required', $json);
        $this->assertArrayHasKey('properties', $json);
        $this->assertArrayHasKey('hardValidation', $json);
        $this->assertArrayHasKey('searchable', $json);
        $this->assertArrayHasKey('maxDepth', $json);
        $this->assertArrayHasKey('authorization', $json);
        $this->assertArrayHasKey('configuration', $json);
        $this->assertArrayHasKey('facets', $json);
        $this->assertArrayHasKey('hooks', $json);
        $this->assertArrayHasKey('allOf', $json);
        $this->assertArrayHasKey('oneOf', $json);
        $this->assertArrayHasKey('anyOf', $json);
        $this->assertArrayHasKey('published', $json);
        $this->assertArrayHasKey('depublished', $json);
    }

    public function testJsonSerializeRequiredEnrichment(): void
    {
        $this->schema->setProperties([
            'name' => ['type' => 'string', 'required' => true],
            'age' => ['type' => 'integer'],
        ]);
        $json = $this->schema->jsonSerialize();
        $this->assertContains('name', $json['required']);
        $this->assertNotContains('age', $json['required']);
    }

    public function testJsonSerializeDateFormatting(): void
    {
        $dt = new DateTime('2024-01-15 10:30:00');
        $this->schema->setCreated($dt);
        $this->schema->setUpdated($dt);
        $json = $this->schema->jsonSerialize();
        $this->assertSame($dt->format('c'), $json['created']);
        $this->assertSame($dt->format('c'), $json['updated']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $json = $this->schema->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
        $this->assertNull($json['deleted']);
        $this->assertNull($json['published']);
        $this->assertNull($json['depublished']);
    }

    public function testJsonSerializeHooksDefault(): void
    {
        $json = $this->schema->jsonSerialize();
        $this->assertSame([], $json['hooks']);
    }

    // --- Slug ---

    public function testSetSlugPreservesCase(): void
    {
        $this->schema->setSlug('mySchemaSlug');
        $this->assertSame('mySchemaSlug', $this->schema->getSlug());
    }

    public function testSetSlugNull(): void
    {
        $this->schema->setSlug(null);
        $this->assertNull($this->schema->getSlug());
    }

    // --- Icon ---

    public function testSetAndGetIcon(): void
    {
        $this->schema->setIcon('mdi-account');
        $this->assertSame('mdi-account', $this->schema->getIcon());
    }

    public function testSetIconNull(): void
    {
        $this->schema->setIcon(null);
        $this->assertNull($this->schema->getIcon());
    }

    // --- Configuration ---

    public function testGetConfigurationNull(): void
    {
        $this->assertNull($this->schema->getConfiguration());
    }

    public function testGetConfigurationArray(): void
    {
        // Use reflection to set directly (bypasses setConfiguration's Server::get)
        $ref = new \ReflectionProperty($this->schema, 'configuration');
        $ref->setAccessible(true);
        $ref->setValue($this->schema, ['objectNameField' => 'name']);
        $this->assertSame(['objectNameField' => 'name'], $this->schema->getConfiguration());
    }

    public function testGetConfigurationJsonString(): void
    {
        $ref = new \ReflectionProperty($this->schema, 'configuration');
        $ref->setAccessible(true);
        $ref->setValue($this->schema, '{"objectNameField":"name"}');
        $this->assertSame(['objectNameField' => 'name'], $this->schema->getConfiguration());
    }

    public function testSetConfigurationNull(): void
    {
        $this->schema->setConfiguration(null);
        $this->assertNull($this->schema->getConfiguration());
    }

    public function testSetConfigurationFallbackArray(): void
    {
        // Server::get will throw, fallback stores as-is
        $this->schema->setConfiguration(['allowFiles' => true]);
        $result = $this->schema->getConfiguration();
        $this->assertIsArray($result);
        $this->assertTrue($result['allowFiles']);
    }

    public function testSetConfigurationFallbackJsonString(): void
    {
        $this->schema->setConfiguration('{"allowFiles":true}');
        $result = $this->schema->getConfiguration();
        $this->assertIsArray($result);
        $this->assertTrue($result['allowFiles']);
    }

    // --- Searchable ---

    public function testIsSearchableDefaultTrue(): void
    {
        $this->assertTrue($this->schema->isSearchable());
    }

    public function testSetSearchableFalse(): void
    {
        $this->schema->setSearchable(false);
        $this->assertFalse($this->schema->isSearchable());
    }

    // --- __toString ---

    public function testToStringSlugPriority(): void
    {
        $this->schema->setSlug('mySlug');
        $this->schema->setTitle('My Title');
        $this->assertSame('mySlug', (string) $this->schema);
    }

    public function testToStringTitleFallback(): void
    {
        $this->schema->setTitle('My Title');
        $this->assertSame('My Title', (string) $this->schema);
    }

    public function testToStringIdFallback(): void
    {
        $ref = new \ReflectionProperty($this->schema, 'id');
        $ref->setAccessible(true);
        $ref->setValue($this->schema, 42);
        $this->assertSame('Schema #42', (string) $this->schema);
    }

    public function testToStringUnknownFallback(): void
    {
        $this->assertSame('Schema #unknown', (string) $this->schema);
    }

    // --- Facets ---

    public function testGetFacetsNull(): void
    {
        $this->assertNull($this->schema->getFacets());
    }

    public function testSetAndGetFacetsArray(): void
    {
        $facets = ['status' => ['type' => 'enum']];
        $this->schema->setFacets($facets);
        $this->assertSame($facets, $this->schema->getFacets());
    }

    public function testSetFacetsJsonString(): void
    {
        $this->schema->setFacets('{"status":{"type":"enum"}}');
        $this->assertSame(['status' => ['type' => 'enum']], $this->schema->getFacets());
    }

    public function testSetFacetsInvalidJson(): void
    {
        $this->schema->setFacets('invalid{');
        $this->assertNull($this->schema->getFacets());
    }

    public function testSetFacetsNull(): void
    {
        $this->schema->setFacets(['test' => 'val']);
        $this->schema->setFacets(null);
        $this->assertNull($this->schema->getFacets());
    }

    // --- AllOf, OneOf, AnyOf ---

    public function testGetAllOfDefault(): void
    {
        $this->assertNull($this->schema->getAllOf());
    }

    public function testSetAndGetAllOf(): void
    {
        $this->schema->setAllOf([1, 2]);
        $this->assertSame([1, 2], $this->schema->getAllOf());
    }

    public function testSetAllOfNull(): void
    {
        $this->schema->setAllOf([1]);
        $this->schema->setAllOf(null);
        $this->assertNull($this->schema->getAllOf());
    }

    public function testSetAndGetOneOf(): void
    {
        $this->schema->setOneOf([3, 4]);
        $this->assertSame([3, 4], $this->schema->getOneOf());
    }

    public function testSetAndGetAnyOf(): void
    {
        $this->schema->setAnyOf([5, 6]);
        $this->assertSame([5, 6], $this->schema->getAnyOf());
    }

    // --- Published / Depublished ---

    public function testSetPublishedDateTime(): void
    {
        $dt = new DateTime('2024-01-15');
        $this->schema->setPublished($dt);
        $this->assertSame($dt, $this->schema->getPublished());
    }

    public function testSetPublishedString(): void
    {
        $this->schema->setPublished('2024-01-15T10:00:00+00:00');
        $this->assertInstanceOf(DateTime::class, $this->schema->getPublished());
    }

    public function testSetPublishedNull(): void
    {
        $this->schema->setPublished(new DateTime());
        $this->schema->setPublished(null);
        $this->assertNull($this->schema->getPublished());
    }

    public function testSetDepublishedDateTime(): void
    {
        $dt = new DateTime('2024-06-01');
        $this->schema->setDepublished($dt);
        $this->assertSame($dt, $this->schema->getDepublished());
    }

    public function testSetDepublishedString(): void
    {
        $this->schema->setDepublished('2024-06-01T10:00:00+00:00');
        $this->assertInstanceOf(DateTime::class, $this->schema->getDepublished());
    }

    public function testSetDepublishedNull(): void
    {
        $this->schema->setDepublished(null);
        $this->assertNull($this->schema->getDepublished());
    }

    // --- isManagedByConfiguration ---

    public function testIsManagedByConfigurationTrue(): void
    {
        $ref = new \ReflectionProperty($this->schema, 'id');
        $ref->setAccessible(true);
        $ref->setValue($this->schema, 42);

        $config = new Configuration();
        $config->setSchemas([42, 99]);
        $this->assertTrue($this->schema->isManagedByConfiguration([$config]));
    }

    public function testIsManagedByConfigurationFalse(): void
    {
        $ref = new \ReflectionProperty($this->schema, 'id');
        $ref->setAccessible(true);
        $ref->setValue($this->schema, 42);

        $config = new Configuration();
        $config->setSchemas([99, 100]);
        $this->assertFalse($this->schema->isManagedByConfiguration([$config]));
    }

    public function testIsManagedByConfigurationEmpty(): void
    {
        $this->assertFalse($this->schema->isManagedByConfiguration([]));
    }

    public function testIsManagedByConfigurationNullId(): void
    {
        $config = new Configuration();
        $config->setSchemas([1]);
        $this->assertFalse($this->schema->isManagedByConfiguration([$config]));
    }

    public function testGetManagedByConfigurationReturnsConfig(): void
    {
        $ref = new \ReflectionProperty($this->schema, 'id');
        $ref->setAccessible(true);
        $ref->setValue($this->schema, 42);

        $config1 = new Configuration();
        $config1->setSchemas([10]);
        $config2 = new Configuration();
        $config2->setSchemas([42]);

        $this->assertSame($config2, $this->schema->getManagedByConfiguration([$config1, $config2]));
    }

    public function testGetManagedByConfigurationReturnsNull(): void
    {
        $ref = new \ReflectionProperty($this->schema, 'id');
        $ref->setAccessible(true);
        $ref->setValue($this->schema, 42);

        $config = new Configuration();
        $config->setSchemas([10]);
        $this->assertNull($this->schema->getManagedByConfiguration([$config]));
    }

    // --- Full round-trip ---

    public function testHydrateThenSerialize(): void
    {
        $this->schema->hydrate([
            'uuid' => 'test-uuid',
            'title' => 'Test Schema',
            'description' => 'A test',
            'version' => '1.0',
            'properties' => ['name' => ['type' => 'string', 'required' => true]],
        ]);
        $json = $this->schema->jsonSerialize();
        $this->assertSame('test-uuid', $json['uuid']);
        $this->assertSame('Test Schema', $json['title']);
        $this->assertContains('name', $json['required']);
    }
}
