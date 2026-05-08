<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\WorkflowEngine;
use PHPUnit\Framework\TestCase;

class WorkflowEngineTest extends TestCase
{
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new WorkflowEngine();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->engine->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['engineType']);
        $this->assertSame('string', $fieldTypes['baseUrl']);
        $this->assertSame('string', $fieldTypes['authType']);
        $this->assertSame('string', $fieldTypes['authConfig']);
        $this->assertSame('boolean', $fieldTypes['enabled']);
        $this->assertSame('integer', $fieldTypes['defaultTimeout']);
        $this->assertSame('boolean', $fieldTypes['healthStatus']);
        $this->assertSame('datetime', $fieldTypes['lastHealthCheck']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->engine->getUuid());
        $this->assertNull($this->engine->getName());
        $this->assertNull($this->engine->getEngineType());
        $this->assertNull($this->engine->getBaseUrl());
        $this->assertSame('none', $this->engine->getAuthType());
        $this->assertNull($this->engine->getAuthConfig());
        $this->assertTrue($this->engine->getEnabled());
        $this->assertSame(30, $this->engine->getDefaultTimeout());
        $this->assertNull($this->engine->getHealthStatus());
        $this->assertNull($this->engine->getLastHealthCheck());
        $this->assertNull($this->engine->getCreated());
        $this->assertNull($this->engine->getUpdated());
    }

    // --- Getters/Setters ---

    public function testSetAndGetUuid(): void
    {
        $this->engine->setUuid('engine-uuid-123');
        $this->assertSame('engine-uuid-123', $this->engine->getUuid());
    }

    public function testSetAndGetName(): void
    {
        $this->engine->setName('n8n Production');
        $this->assertSame('n8n Production', $this->engine->getName());
    }

    public function testSetAndGetEngineType(): void
    {
        $this->engine->setEngineType('n8n');
        $this->assertSame('n8n', $this->engine->getEngineType());
    }

    public function testSetAndGetBaseUrl(): void
    {
        $this->engine->setBaseUrl('https://n8n.example.com');
        $this->assertSame('https://n8n.example.com', $this->engine->getBaseUrl());
    }

    public function testSetAndGetAuthType(): void
    {
        $this->engine->setAuthType('api_key');
        $this->assertSame('api_key', $this->engine->getAuthType());
    }

    public function testSetAndGetAuthConfig(): void
    {
        $this->engine->setAuthConfig('{"apiKey":"secret123"}');
        $this->assertSame('{"apiKey":"secret123"}', $this->engine->getAuthConfig());
    }

    public function testSetAndGetEnabled(): void
    {
        $this->engine->setEnabled(false);
        $this->assertFalse($this->engine->getEnabled());

        $this->engine->setEnabled(true);
        $this->assertTrue($this->engine->getEnabled());
    }

    public function testSetAndGetDefaultTimeout(): void
    {
        $this->engine->setDefaultTimeout(60);
        $this->assertSame(60, $this->engine->getDefaultTimeout());
    }

    public function testSetAndGetHealthStatus(): void
    {
        $this->engine->setHealthStatus(true);
        $this->assertTrue($this->engine->getHealthStatus());

        $this->engine->setHealthStatus(false);
        $this->assertFalse($this->engine->getHealthStatus());

        $this->engine->setHealthStatus(null);
        $this->assertNull($this->engine->getHealthStatus());
    }

    public function testSetAndGetLastHealthCheck(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->engine->setLastHealthCheck($dt);
        $this->assertSame($dt, $this->engine->getLastHealthCheck());
    }

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-01-01 00:00:00');
        $this->engine->setCreated($dt);
        $this->assertSame($dt, $this->engine->getCreated());
    }

    public function testSetAndGetUpdated(): void
    {
        $dt = new DateTime('2024-06-15 08:30:00');
        $this->engine->setUpdated($dt);
        $this->assertSame($dt, $this->engine->getUpdated());
    }

    // --- hydrate ---

    public function testHydrateSetsKnownFields(): void
    {
        $this->engine->hydrate([
            'uuid'       => 'hydrated-uuid',
            'name'       => 'Hydrated Engine',
            'engineType' => 'windmill',
            'baseUrl'    => 'https://windmill.example.com',
            'authType'   => 'bearer',
            'enabled'    => false,
            'defaultTimeout' => 120,
        ]);

        $this->assertSame('hydrated-uuid', $this->engine->getUuid());
        $this->assertSame('Hydrated Engine', $this->engine->getName());
        $this->assertSame('windmill', $this->engine->getEngineType());
        $this->assertSame('https://windmill.example.com', $this->engine->getBaseUrl());
        $this->assertSame('bearer', $this->engine->getAuthType());
        $this->assertFalse($this->engine->getEnabled());
        $this->assertSame(120, $this->engine->getDefaultTimeout());
    }

    public function testHydrateIgnoresUnknownFields(): void
    {
        $this->engine->hydrate(['nonExistent' => 'value', 'uuid' => 'test']);
        $this->assertSame('test', $this->engine->getUuid());
    }

    public function testHydrateReturnsThis(): void
    {
        $result = $this->engine->hydrate(['name' => 'Test']);
        $this->assertSame($this->engine, $result);
    }

    // --- jsonSerialize ---

    public function testJsonSerializeAllFieldsPresent(): void
    {
        $json = $this->engine->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'name', 'engineType', 'baseUrl', 'authType',
            'enabled', 'defaultTimeout', 'healthStatus', 'lastHealthCheck',
            'created', 'updated',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }

    public function testJsonSerializeExcludesAuthConfig(): void
    {
        $this->engine->setAuthConfig('{"secret":"value"}');
        $json = $this->engine->jsonSerialize();

        $this->assertArrayNotHasKey('authConfig', $json);
    }

    public function testJsonSerializeDefaultValues(): void
    {
        $json = $this->engine->jsonSerialize();

        $this->assertNull($json['id']);
        $this->assertNull($json['uuid']);
        $this->assertNull($json['name']);
        $this->assertNull($json['engineType']);
        $this->assertNull($json['baseUrl']);
        $this->assertSame('none', $json['authType']);
        $this->assertTrue($json['enabled']);
        $this->assertSame(30, $json['defaultTimeout']);
        $this->assertNull($json['healthStatus']);
        $this->assertNull($json['lastHealthCheck']);
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }

    public function testJsonSerializeFormatsDatetimes(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $updated = new DateTime('2024-06-15 08:30:00');
        $lastCheck = new DateTime('2024-06-15 09:00:00');

        $this->engine->setCreated($created);
        $this->engine->setUpdated($updated);
        $this->engine->setLastHealthCheck($lastCheck);

        $json = $this->engine->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($updated->format('c'), $json['updated']);
        $this->assertSame($lastCheck->format('c'), $json['lastHealthCheck']);
    }

    public function testJsonSerializeDatetimesNullWhenNotSet(): void
    {
        $json = $this->engine->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
        $this->assertNull($json['lastHealthCheck']);
    }

    public function testJsonSerializeWithFullData(): void
    {
        $this->engine->setUuid('eng-uuid');
        $this->engine->setName('Production n8n');
        $this->engine->setEngineType('n8n');
        $this->engine->setBaseUrl('https://n8n.prod.example.com');
        $this->engine->setAuthType('api_key');
        $this->engine->setEnabled(true);
        $this->engine->setDefaultTimeout(45);
        $this->engine->setHealthStatus(true);

        $json = $this->engine->jsonSerialize();

        $this->assertSame('eng-uuid', $json['uuid']);
        $this->assertSame('Production n8n', $json['name']);
        $this->assertSame('n8n', $json['engineType']);
        $this->assertSame('https://n8n.prod.example.com', $json['baseUrl']);
        $this->assertSame('api_key', $json['authType']);
        $this->assertTrue($json['enabled']);
        $this->assertSame(45, $json['defaultTimeout']);
        $this->assertTrue($json['healthStatus']);
    }
}
