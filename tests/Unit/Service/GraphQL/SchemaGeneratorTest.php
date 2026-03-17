<?php

declare(strict_types=1);

namespace Unit\Service\GraphQL;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\GraphQL\SchemaGenerator;
use GraphQL\Type\Definition\ObjectType;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class SchemaGeneratorTest extends TestCase
{
    private SchemaGenerator $generator;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;

    protected function setUp(): void
    {
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        $this->generator = new SchemaGenerator($this->registerMapper, $this->schemaMapper);
    }

    /**
     * Create a real Schema entity with properties set.
     */
    private function createSchema(int $id, string $slug, array $properties, ?string $title = null): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->setTitle($title ?? ucfirst($slug));
        $schema->setDescription('Test schema');
        $schema->setSummary('Test');
        $schema->setProperties($properties);
        $schema->setRequired([]);
        return $schema;
    }

    // ── Type Mapping ──

    public function testStringPropertyMapsToStringType(): void
    {
        $schema = $this->createSchema(1, 'items', [
            'name' => ['type' => 'string'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $this->generator->generate();
        $objectType = $this->generator->getObjectType($schema);

        $fields = $objectType->getFields();
        $this->assertArrayHasKey('name', $fields);
    }

    public function testIntegerPropertyPresent(): void
    {
        $schema = $this->createSchema(1, 'items', [
            'count' => ['type' => 'integer'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $this->generator->generate();
        $objectType = $this->generator->getObjectType($schema);

        $fields = $objectType->getFields();
        $this->assertArrayHasKey('count', $fields);
    }

    public function testDateTimeFormatMapsToDateTimeScalar(): void
    {
        $schema = $this->createSchema(1, 'items', [
            'created' => ['type' => 'string', 'format' => 'date-time'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $this->generator->generate();
        $objectType = $this->generator->getObjectType($schema);

        $fields = $objectType->getFields();
        $this->assertArrayHasKey('created', $fields);
        $this->assertSame('DateTime', $fields['created']->getType()->name);
    }

    public function testUuidFormatMapsToUuidScalar(): void
    {
        $schema = $this->createSchema(1, 'items', [
            'identifier' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $this->generator->generate();
        $objectType = $this->generator->getObjectType($schema);

        $fields = $objectType->getFields();
        $this->assertArrayHasKey('identifier', $fields);
        $this->assertSame('UUID', $fields['identifier']->getType()->name);
    }

    public function testEmailFormatMapsToEmailScalar(): void
    {
        $schema = $this->createSchema(1, 'items', [
            'email' => ['type' => 'string', 'format' => 'email'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $this->generator->generate();
        $objectType = $this->generator->getObjectType($schema);

        $fields = $objectType->getFields();
        $this->assertArrayHasKey('email', $fields);
        $this->assertSame('Email', $fields['email']->getType()->name);
    }

    public function testObjectWithoutRefMapsToJsonScalar(): void
    {
        $schema = $this->createSchema(1, 'items', [
            'metadata' => ['type' => 'object'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $this->generator->generate();
        $objectType = $this->generator->getObjectType($schema);

        $fields = $objectType->getFields();
        $this->assertArrayHasKey('metadata', $fields);
        $this->assertSame('JSON', $fields['metadata']->getType()->name);
    }

    // ── Schema Generation ──

    public function testGeneratesQueryAndMutationTypes(): void
    {
        $schema = $this->createSchema(1, 'meldingen', [
            'title' => ['type' => 'string'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $graphqlSchema = $this->generator->generate();

        $this->assertNotNull($graphqlSchema->getQueryType());
        $this->assertNotNull($graphqlSchema->getMutationType());
    }

    public function testGeneratesConnectionType(): void
    {
        $schema = $this->createSchema(1, 'meldingen', [
            'title' => ['type' => 'string'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $graphqlSchema = $this->generator->generate();
        $queryType = $graphqlSchema->getQueryType();

        $meldingen = $queryType->getField('meldingen');
        $this->assertStringContainsString('Connection', $meldingen->getType()->name);
    }

    public function testGeneratesMutationFields(): void
    {
        $schema = $this->createSchema(1, 'meldingen', [
            'title' => ['type' => 'string'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $graphqlSchema = $this->generator->generate();
        $mutationType = $graphqlSchema->getMutationType();

        $this->assertTrue($mutationType->hasField('createMelding'));
        $this->assertTrue($mutationType->hasField('updateMelding'));
        $this->assertTrue($mutationType->hasField('deleteMelding'));
    }

    // ── Metadata Fields ──

    public function testObjectTypeIncludesMetadataFields(): void
    {
        $schema = $this->createSchema(1, 'items', [
            'name' => ['type' => 'string'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $this->generator->generate();
        $objectType = $this->generator->getObjectType($schema);

        $fields = $objectType->getFields();
        $this->assertArrayHasKey('_uuid', $fields);
        $this->assertArrayHasKey('_register', $fields);
        $this->assertArrayHasKey('_schema', $fields);
        $this->assertArrayHasKey('_created', $fields);
        $this->assertArrayHasKey('_updated', $fields);
        $this->assertArrayHasKey('_owner', $fields);
        $this->assertArrayHasKey('_auditTrail', $fields);
        $this->assertArrayHasKey('_usedBy', $fields);
    }

    // ── Register query ──

    public function testGeneratesRegisterQuery(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $graphqlSchema = $this->generator->generate();
        $queryType = $graphqlSchema->getQueryType();

        $this->assertTrue($queryType->hasField('register'));
    }

    // ── Ref resolution ──

    public function testRefPropertyResolvesToObjectType(): void
    {
        $personSchema = $this->createSchema(1, 'personen', [
            'naam' => ['type' => 'string'],
        ]);

        $orderSchema = $this->createSchema(2, 'orders', [
            'klant' => ['type' => 'object', '$ref' => 'personen'],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$personSchema, $orderSchema]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $this->generator->generate();
        $orderType = $this->generator->getObjectType($orderSchema);

        $fields = $orderType->getFields();
        $this->assertArrayHasKey('klant', $fields);
        $this->assertInstanceOf(ObjectType::class, $fields['klant']->getType());
    }

    // ── Empty schema ──

    public function testEmptySchemaGeneratesValidGraphQL(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $graphqlSchema = $this->generator->generate();

        $this->assertNotNull($graphqlSchema->getQueryType());
    }
}
