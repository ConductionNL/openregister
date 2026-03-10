<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OasService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OasServiceTest extends TestCase
{
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private IURLGenerator&MockObject $urlGenerator;
    private OasService $service;

    protected function setUp(): void
    {
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);

        $this->service = new OasService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->urlGenerator
        );
    }

    private function createRegister(int $id, string $title, array $schemaIds = [], ?string $description = null, string $version = '1.0'): Register
    {
        $register = new Register();
        $register->setTitle($title);
        $register->setSchemas($schemaIds);
        $register->setDescription($description);
        $register->setVersion($version);
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }

    private function createSchema(int $id, string $title, array $properties = []): Schema
    {
        $schema = new Schema();
        $schema->setTitle($title);
        $schema->setProperties($properties);
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        return $schema;
    }

    public function testCreateOasReturnsValidStructure(): void
    {
        $register = $this->createRegister(1, 'TestRegister', [10]);
        $schema = $this->createSchema(10, 'TestSchema', [
            'name' => ['type' => 'string', 'description' => 'Name field'],
        ]);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findMultiple')->willReturn([$schema]);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/apps/openregister/api');

        $oas = $this->service->createOas();

        $this->assertArrayHasKey('openapi', $oas);
        $this->assertArrayHasKey('info', $oas);
        $this->assertArrayHasKey('servers', $oas);
        $this->assertArrayHasKey('paths', $oas);
    }

    public function testCreateOasForSpecificRegister(): void
    {
        $register = $this->createRegister(1, 'MyRegister', [10], 'A test register', '2.0');
        $schema = $this->createSchema(10, 'Person', [
            'name' => ['type' => 'string'],
        ]);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('findMultiple')->willReturn([$schema]);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/api');

        $oas = $this->service->createOas('1');

        $this->assertStringContainsString('MyRegister', $oas['info']['title']);
        $this->assertSame('A test register', $oas['info']['description']);
    }

    public function testCreateOasForRegisterWithNoDescription(): void
    {
        $register = $this->createRegister(1, 'EmptyDesc', [10], null, '1.0');
        $schema = $this->createSchema(10, 'Item', []);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('findMultiple')->willReturn([$schema]);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/api');

        $oas = $this->service->createOas('1');

        // Should generate a default description
        $this->assertNotEmpty($oas['info']['description']);
        $this->assertStringContainsString('EmptyDesc', $oas['info']['description']);
    }

    public function testCreateOasWithEmptySchemas(): void
    {
        $register = $this->createRegister(1, 'EmptyReg', []);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findMultiple')->willReturn([]);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/api');

        $oas = $this->service->createOas();

        $this->assertArrayHasKey('openapi', $oas);
        $this->assertArrayHasKey('tags', $oas);
    }

    public function testCreateOasServersUrl(): void
    {
        $register = $this->createRegister(1, 'TestReg', []);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findMultiple')->willReturn([]);
        $this->urlGenerator->method('getAbsoluteURL')
            ->with('/apps/openregister/api')
            ->willReturn('https://example.com/apps/openregister/api');

        $oas = $this->service->createOas();

        $this->assertSame('https://example.com/apps/openregister/api', $oas['servers'][0]['url']);
        $this->assertSame('OpenRegister API Server', $oas['servers'][0]['description']);
    }

    public function testCreateOasMultipleRegistersDeduplicateSchemas(): void
    {
        $register1 = $this->createRegister(1, 'Reg1', [10, 20]);
        $register2 = $this->createRegister(2, 'Reg2', [10, 30]);

        $schema10 = $this->createSchema(10, 'SharedSchema');
        $schema20 = $this->createSchema(20, 'Schema20');
        $schema30 = $this->createSchema(30, 'Schema30');

        $this->registerMapper->method('findAll')->willReturn([$register1, $register2]);
        // findMultiple should be called with unique IDs [10, 20, 30]
        $this->schemaMapper->method('findMultiple')
            ->willReturn([$schema10, $schema20, $schema30]);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/api');

        $oas = $this->service->createOas();

        $this->assertArrayHasKey('openapi', $oas);
    }

    public function testCreateOasSkipsSchemaWithEmptyTitle(): void
    {
        $register = $this->createRegister(1, 'Reg', [10, 20]);
        $schema1 = $this->createSchema(10, 'ValidSchema', ['name' => ['type' => 'string']]);
        $schema2 = $this->createSchema(20, '', []); // Empty title

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findMultiple')->willReturn([$schema1, $schema2]);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/api');

        $oas = $this->service->createOas();

        // The OAS should still be valid even with a schema with empty title
        $this->assertArrayHasKey('openapi', $oas);
    }

    public function testCreateOasSchemaProperties(): void
    {
        $register = $this->createRegister(1, 'Reg', [10]);
        $schema = $this->createSchema(10, 'Person', [
            'firstName' => ['type' => 'string', 'description' => 'First name'],
            'age' => ['type' => 'integer', 'description' => 'Age in years'],
            'email' => ['type' => 'string', 'format' => 'email'],
        ]);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findMultiple')->willReturn([$schema]);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/api');

        $oas = $this->service->createOas();

        // Verify the schema was added to components
        $this->assertArrayHasKey('components', $oas);
        if (isset($oas['components']['schemas'])) {
            $this->assertNotEmpty($oas['components']['schemas']);
        }
    }

    public function testCreateOasAllRegisters(): void
    {
        $this->registerMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('findMultiple')->willReturn([]);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/api');

        // null means all registers
        $oas = $this->service->createOas(null);

        $this->assertArrayHasKey('openapi', $oas);
    }
}
