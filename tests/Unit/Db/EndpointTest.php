<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Endpoint;
use PHPUnit\Framework\TestCase;

class EndpointTest extends TestCase
{
    private Endpoint $endpoint;

    protected function setUp(): void
    {
        $this->endpoint = new Endpoint();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->endpoint->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('string', $fieldTypes['reference']);
        $this->assertSame('string', $fieldTypes['version']);
        $this->assertSame('string', $fieldTypes['endpoint']);
        $this->assertSame('json', $fieldTypes['endpointArray']);
        $this->assertSame('string', $fieldTypes['endpointRegex']);
        $this->assertSame('string', $fieldTypes['method']);
        $this->assertSame('string', $fieldTypes['targetType']);
        $this->assertSame('string', $fieldTypes['targetId']);
        $this->assertSame('json', $fieldTypes['conditions']);
        $this->assertSame('string', $fieldTypes['inputMapping']);
        $this->assertSame('string', $fieldTypes['outputMapping']);
        $this->assertSame('json', $fieldTypes['rules']);
        $this->assertSame('json', $fieldTypes['configurations']);
        $this->assertSame('string', $fieldTypes['slug']);
        $this->assertSame('json', $fieldTypes['groups']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->endpoint->getUuid());
        $this->assertNull($this->endpoint->getName());
        $this->assertNull($this->endpoint->getDescription());
        $this->assertNull($this->endpoint->getReference());
        $this->assertSame('0.0.0', $this->endpoint->getVersion());
        $this->assertNull($this->endpoint->getEndpoint());
        $this->assertSame([], $this->endpoint->getEndpointArray());
        $this->assertNull($this->endpoint->getEndpointRegex());
        $this->assertNull($this->endpoint->getMethod());
        $this->assertNull($this->endpoint->getTargetType());
        $this->assertNull($this->endpoint->getTargetId());
        $this->assertSame([], $this->endpoint->getConditions());
        $this->assertNull($this->endpoint->getInputMapping());
        $this->assertNull($this->endpoint->getOutputMapping());
        $this->assertSame([], $this->endpoint->getRules());
        $this->assertSame([], $this->endpoint->getConfigurations());
        $this->assertSame([], $this->endpoint->getGroups());
        $this->assertNull($this->endpoint->getOrganisation());
        $this->assertNull($this->endpoint->getCreated());
        $this->assertNull($this->endpoint->getUpdated());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->endpoint->setUuid('ep-uuid');
        $this->endpoint->setName('Test Endpoint');
        $this->endpoint->setDescription('A test endpoint');
        $this->endpoint->setReference('ref-001');
        $this->endpoint->setVersion('1.2.3');
        $this->endpoint->setEndpoint('/api/buildings/{{id}}');
        $this->endpoint->setEndpointRegex('/api/buildings/[^/]+');
        $this->endpoint->setMethod('GET');
        $this->endpoint->setTargetType('schema');
        $this->endpoint->setTargetId('target-123');
        $this->endpoint->setInputMapping('mapping-in');
        $this->endpoint->setOutputMapping('mapping-out');
        $this->endpoint->setSlug('test-endpoint');
        $this->endpoint->setOrganisation('org-uuid');

        $this->assertSame('ep-uuid', $this->endpoint->getUuid());
        $this->assertSame('Test Endpoint', $this->endpoint->getName());
        $this->assertSame('A test endpoint', $this->endpoint->getDescription());
        $this->assertSame('ref-001', $this->endpoint->getReference());
        $this->assertSame('1.2.3', $this->endpoint->getVersion());
        $this->assertSame('/api/buildings/{{id}}', $this->endpoint->getEndpoint());
        $this->assertSame('/api/buildings/[^/]+', $this->endpoint->getEndpointRegex());
        $this->assertSame('GET', $this->endpoint->getMethod());
        $this->assertSame('schema', $this->endpoint->getTargetType());
        $this->assertSame('target-123', $this->endpoint->getTargetId());
        $this->assertSame('mapping-in', $this->endpoint->getInputMapping());
        $this->assertSame('mapping-out', $this->endpoint->getOutputMapping());
        $this->assertSame('test-endpoint', $this->endpoint->getSlug());
        $this->assertSame('org-uuid', $this->endpoint->getOrganisation());
    }

    public function testSetAndGetJsonFields(): void
    {
        $endpointArray = ['api', 'buildings', '{{id}}'];
        $conditions = [['field' => 'status', 'value' => 'active']];
        $rules = [['type' => 'validation']];
        $configurations = [1, 2, 3];
        $groups = ['read' => ['group1'], 'write' => ['group2']];

        $this->endpoint->setEndpointArray($endpointArray);
        $this->endpoint->setConditions($conditions);
        $this->endpoint->setRules($rules);
        $this->endpoint->setConfigurations($configurations);
        $this->endpoint->setGroups($groups);

        $this->assertSame($endpointArray, $this->endpoint->getEndpointArray());
        $this->assertSame($conditions, $this->endpoint->getConditions());
        $this->assertSame($rules, $this->endpoint->getRules());
        $this->assertSame($configurations, $this->endpoint->getConfigurations());
        $this->assertSame($groups, $this->endpoint->getGroups());
    }

    public function testGetSlugGeneratedFromName(): void
    {
        $this->endpoint->setName('My Test Endpoint');
        $this->assertSame('my-test-endpoint', $this->endpoint->getSlug());
    }

    public function testGetSlugReturnsSetSlug(): void
    {
        $this->endpoint->setSlug('custom-slug');
        $this->endpoint->setName('Different Name');
        $this->assertSame('custom-slug', $this->endpoint->getSlug());
    }

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->endpoint->getJsonFields();
        $this->assertContains('endpointArray', $jsonFields);
        $this->assertContains('conditions', $jsonFields);
        $this->assertContains('rules', $jsonFields);
        $this->assertContains('configurations', $jsonFields);
        $this->assertContains('groups', $jsonFields);
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');

        $this->endpoint->setCreated($created);
        $this->endpoint->setUpdated($updated);

        $this->assertSame($created, $this->endpoint->getCreated());
        $this->assertSame($updated, $this->endpoint->getUpdated());
    }

    public function testHydrate(): void
    {
        $data = [
            'uuid'       => 'hydrate-uuid',
            'name'       => 'Hydrated Endpoint',
            'method'     => 'POST',
            'targetType' => 'register',
            'conditions' => [['field' => 'x']],
        ];

        $result = $this->endpoint->hydrate($data);

        $this->assertSame($this->endpoint, $result);
        $this->assertSame('hydrate-uuid', $this->endpoint->getUuid());
        $this->assertSame('Hydrated Endpoint', $this->endpoint->getName());
        $this->assertSame('POST', $this->endpoint->getMethod());
        $this->assertSame('register', $this->endpoint->getTargetType());
    }

    public function testJsonSerialize(): void
    {
        $this->endpoint->setUuid('json-uuid');
        $this->endpoint->setName('JSON Endpoint');
        $this->endpoint->setMethod('GET');
        $this->endpoint->setSlug('json-endpoint');

        $json = $this->endpoint->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'name', 'description', 'reference', 'version',
            'endpoint', 'endpointArray', 'endpointRegex', 'method',
            'targetType', 'targetId', 'conditions', 'inputMapping',
            'outputMapping', 'rules', 'configurations', 'slug', 'groups',
            'organisation', 'created', 'updated',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame('JSON Endpoint', $json['name']);
        $this->assertSame('GET', $json['method']);
        $this->assertSame('json-endpoint', $json['slug']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $this->endpoint->setName('test-endpoint');
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $this->endpoint->setCreated($created);

        $json = $this->endpoint->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $this->endpoint->setName('test');
        $json = $this->endpoint->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }
}
