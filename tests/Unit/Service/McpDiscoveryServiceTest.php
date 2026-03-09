<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\McpDiscoveryService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class McpDiscoveryServiceTest extends TestCase
{
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private IURLGenerator&MockObject $urlGenerator;
    private McpDiscoveryService $service;

    protected function setUp(): void
    {
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);

        $this->service = new McpDiscoveryService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->urlGenerator
        );
    }

    private function createRegister(int $id, string $title, string $uuid = 'reg-uuid', array $schemas = []): Register
    {
        $register = new Register();
        $register->setUuid($uuid);
        $register->setTitle($title);
        $register->setSchemas($schemas);
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }

    private function createSchema(int $id, string $title, string $uuid = 'schema-uuid', array $properties = []): Schema
    {
        $schema = new Schema();
        $schema->setUuid($uuid);
        $schema->setTitle($title);
        $schema->setProperties($properties);
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        return $schema;
    }

    public function testGetCatalogReturnsCorrectStructure(): void
    {
        $this->urlGenerator->method('linkToRoute')->willReturn('/apps/openregister');

        $result = $this->service->getCatalog();

        $this->assertSame('1.0', $result['version']);
        $this->assertSame('OpenRegister', $result['name']);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('authentication', $result);
        $this->assertArrayHasKey('base_url', $result);
        $this->assertArrayHasKey('capabilities', $result);
    }

    public function testGetCatalogHasAllCapabilities(): void
    {
        $this->urlGenerator->method('linkToRoute')->willReturn('/test');

        $result = $this->service->getCatalog();
        $capabilityIds = array_column($result['capabilities'], 'id');

        $expected = ['registers', 'schemas', 'objects', 'search', 'files', 'audit', 'bulk', 'webhooks', 'chat', 'views'];
        $this->assertSame($expected, $capabilityIds);
    }

    public function testGetCatalogCapabilitiesHaveHref(): void
    {
        $this->urlGenerator->method('linkToRoute')->willReturn('/test-url');

        $result = $this->service->getCatalog();

        foreach ($result['capabilities'] as $cap) {
            $this->assertArrayHasKey('href', $cap);
            $this->assertArrayHasKey('id', $cap);
            $this->assertArrayHasKey('name', $cap);
            $this->assertArrayHasKey('description', $cap);
        }
    }

    public function testGetCatalogAuthenticationInfo(): void
    {
        $this->urlGenerator->method('linkToRoute')->willReturn('/test');

        $result = $this->service->getCatalog();

        $this->assertSame('basic', $result['authentication']['type']);
        $this->assertArrayHasKey('header', $result['authentication']);
    }

    public function testGetCapabilityIds(): void
    {
        $ids = $this->service->getCapabilityIds();

        $this->assertCount(10, $ids);
        $this->assertContains('registers', $ids);
        $this->assertContains('schemas', $ids);
        $this->assertContains('objects', $ids);
        $this->assertContains('search', $ids);
        $this->assertContains('files', $ids);
        $this->assertContains('audit', $ids);
        $this->assertContains('bulk', $ids);
        $this->assertContains('webhooks', $ids);
        $this->assertContains('chat', $ids);
        $this->assertContains('views', $ids);
    }

    public function testGetCapabilityDetailRegisters(): void
    {
        $register = $this->createRegister(1, 'TestRegister', 'reg-uuid-1');
        $this->registerMapper->method('findAll')->willReturn([$register]);

        $result = $this->service->getCapabilityDetail('registers');

        $this->assertNotNull($result);
        $this->assertSame('registers', $result['id']);
        $this->assertArrayHasKey('endpoints', $result);
        $this->assertArrayHasKey('context', $result);
        $this->assertCount(1, $result['context']['registers']);
        $this->assertSame(1, $result['context']['registers'][0]['id']);
    }

    public function testGetCapabilityDetailSchemas(): void
    {
        $schema = $this->createSchema(1, 'TestSchema', 'sch-uuid-1', ['name' => ['type' => 'string']]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $result = $this->service->getCapabilityDetail('schemas');

        $this->assertNotNull($result);
        $this->assertSame('schemas', $result['id']);
        $this->assertCount(1, $result['context']['schemas']);
        $this->assertSame(1, $result['context']['schemas'][0]['property_count']);
    }

    public function testGetCapabilityDetailObjects(): void
    {
        $register = $this->createRegister(1, 'TestRegister', 'reg-uuid', [10]);
        $schema = $this->createSchema(10, 'TestSchema', 'sch-uuid');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $result = $this->service->getCapabilityDetail('objects');

        $this->assertNotNull($result);
        $this->assertSame('objects', $result['id']);
        $this->assertArrayHasKey('context', $result);
        $this->assertCount(1, $result['context']['registers']);
    }

    public function testGetCapabilityDetailSearch(): void
    {
        $result = $this->service->getCapabilityDetail('search');

        $this->assertNotNull($result);
        $this->assertSame('search', $result['id']);
        $this->assertNotEmpty($result['endpoints']);
    }

    public function testGetCapabilityDetailFiles(): void
    {
        $result = $this->service->getCapabilityDetail('files');

        $this->assertNotNull($result);
        $this->assertSame('files', $result['id']);
    }

    public function testGetCapabilityDetailAudit(): void
    {
        $result = $this->service->getCapabilityDetail('audit');

        $this->assertNotNull($result);
        $this->assertSame('audit', $result['id']);
    }

    public function testGetCapabilityDetailBulk(): void
    {
        $result = $this->service->getCapabilityDetail('bulk');

        $this->assertNotNull($result);
        $this->assertSame('bulk', $result['id']);
    }

    public function testGetCapabilityDetailWebhooks(): void
    {
        $result = $this->service->getCapabilityDetail('webhooks');

        $this->assertNotNull($result);
        $this->assertSame('webhooks', $result['id']);
    }

    public function testGetCapabilityDetailChat(): void
    {
        $result = $this->service->getCapabilityDetail('chat');

        $this->assertNotNull($result);
        $this->assertSame('chat', $result['id']);
    }

    public function testGetCapabilityDetailViews(): void
    {
        $result = $this->service->getCapabilityDetail('views');

        $this->assertNotNull($result);
        $this->assertSame('views', $result['id']);
    }

    public function testGetCapabilityDetailUnknown(): void
    {
        $result = $this->service->getCapabilityDetail('nonexistent');

        $this->assertNull($result);
    }

    public function testGetCapabilityDetailRegistersEmpty(): void
    {
        $this->registerMapper->method('findAll')->willReturn([]);

        $result = $this->service->getCapabilityDetail('registers');

        $this->assertNotNull($result);
        $this->assertEmpty($result['context']['registers']);
    }

    public function testGetCapabilityDetailSchemasEmpty(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);

        $result = $this->service->getCapabilityDetail('schemas');

        $this->assertNotNull($result);
        $this->assertEmpty($result['context']['schemas']);
    }

    public function testGetCapabilityDetailSchemasWithNullProperties(): void
    {
        $schema = $this->createSchema(1, 'TestSchema', 'sch-uuid-1', []);
        // Don't set properties so they're null
        $schema2 = new Schema();
        $schema2->setTitle('NullSchema');
        $ref = new \ReflectionClass($schema2);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema2, 2);

        $this->schemaMapper->method('findAll')->willReturn([$schema, $schema2]);

        $result = $this->service->getCapabilityDetail('schemas');

        $this->assertNotNull($result);
        $this->assertCount(2, $result['context']['schemas']);
    }

    public function testGetCapabilityDetailEndpointsHaveRequiredFields(): void
    {
        $this->registerMapper->method('findAll')->willReturn([]);

        $result = $this->service->getCapabilityDetail('registers');

        foreach ($result['endpoints'] as $endpoint) {
            $this->assertArrayHasKey('method', $endpoint);
            $this->assertArrayHasKey('path', $endpoint);
            $this->assertArrayHasKey('description', $endpoint);
        }
    }
}
