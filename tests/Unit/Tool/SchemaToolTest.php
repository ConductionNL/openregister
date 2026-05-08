<?php

namespace Unit\Tool;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Tool\SchemaTool;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaToolTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private SchemaMapper&MockObject $schemaMapper;
    private SchemaTool $tool;

    protected function setUp(): void
    {
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        $this->tool = new SchemaTool(
            $this->userSession,
            $this->logger,
            $this->schemaMapper
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function createSchemaEntity(
        int $id,
        string $uuid,
        string $title,
        string $description = '',
        string $version = '1.0.0',
        ?array $properties = null,
        ?array $required = null,
        ?array $allOf = null,
        ?array $oneOf = null,
        ?array $anyOf = null,
        ?string $organisation = null
    ): Schema {
        $entity = new Schema();
        $entity->setId($id);
        $entity->setUuid($uuid);
        $entity->setTitle($title);
        $entity->setDescription($description);
        $entity->setVersion($version);
        if ($properties !== null) {
            $entity->setProperties($properties);
        }
        if ($required !== null) {
            $entity->setRequired($required);
        }
        if ($allOf !== null) {
            $entity->setAllOf($allOf);
        }
        if ($oneOf !== null) {
            $entity->setOneOf($oneOf);
        }
        if ($anyOf !== null) {
            $entity->setAnyOf($anyOf);
        }
        if ($organisation !== null) {
            $entity->setOrganisation($organisation);
        }
        $entity->setCreated(new DateTime('2024-01-01 12:00:00'));
        $entity->setUpdated(new DateTime('2024-01-02 12:00:00'));
        return $entity;
    }

    // ------------------------------------------------------------------
    // getName / getDescription / getFunctions
    // ------------------------------------------------------------------

    public function testGetName(): void
    {
        $this->assertSame('schema', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('schema', strtolower($this->tool->getDescription()));
    }

    public function testGetFunctionsContainsAllCrud(): void
    {
        $functions = $this->tool->getFunctions();
        $names     = array_column($functions, 'name');
        $this->assertContains('list_schemas', $names);
        $this->assertContains('get_schema', $names);
        $this->assertContains('create_schema', $names);
        $this->assertContains('update_schema', $names);
        $this->assertContains('delete_schema', $names);
        $this->assertCount(5, $functions);
    }

    public function testGetFunctionsStructure(): void
    {
        foreach ($this->tool->getFunctions() as $fn) {
            $this->assertArrayHasKey('name', $fn);
            $this->assertArrayHasKey('description', $fn);
            $this->assertArrayHasKey('parameters', $fn);
        }
    }

    // ------------------------------------------------------------------
    // executeFunction — no user context
    // ------------------------------------------------------------------

    public function testExecuteFunctionNoUserContext(): void
    {
        $noUserSession = $this->createMock(IUserSession::class);
        $noUserSession->method('getUser')->willReturn(null);
        $tool = new SchemaTool($noUserSession, $this->logger, $this->schemaMapper);

        $result = $tool->executeFunction('list_schemas', []);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No user context', $result['error']);
    }

    public function testExecuteFunctionUnknownFunction(): void
    {
        $result = $this->tool->executeFunction('bogus_function', []);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // listSchemas
    // ------------------------------------------------------------------

    public function testListSchemasSuccess(): void
    {
        $schema = $this->createSchemaEntity(1, 'uuid-1', 'My Schema', 'Desc', '1.0.0');
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $result = $this->tool->listSchemas();
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('uuid-1', $result['data'][0]['uuid']);
        $this->assertSame('1.0.0', $result['data'][0]['version']);
    }

    public function testListSchemasEmpty(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);

        $result = $this->tool->listSchemas();
        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['data']);
    }

    public function testListSchemasWithRegisterFilter(): void
    {
        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->with(
                100,
                0,
                $this->callback(function ($filters) {
                    return $filters['register'] === '5';
                })
            )
            ->willReturn([]);

        $this->tool->listSchemas(100, 0, '5');
    }

    public function testListSchemasWithPagination(): void
    {
        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->with(10, 20, $this->anything())
            ->willReturn([]);

        $this->tool->listSchemas(10, 20);
    }

    public function testListSchemasViaExecuteFunction(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);

        $result = $this->tool->executeFunction('list_schemas', []);
        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------
    // getSchema
    // ------------------------------------------------------------------

    public function testGetSchemaSuccess(): void
    {
        $schema = $this->createSchemaEntity(
            1, 'uuid-1', 'Schema', 'Desc', '1.0',
            ['name' => ['type' => 'string']],
            ['name'],
            [['$ref' => '#/a']],
            [['$ref' => '#/b']],
            [['$ref' => '#/c']],
            'org-1'
        );
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->tool->getSchema('1');
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['data']['uuid']);
        $this->assertSame(['name' => ['type' => 'string']], $result['data']['properties']);
        $this->assertSame(['name'], $result['data']['required']);
        $this->assertSame('org-1', $result['data']['organisation']);
        $this->assertStringContainsString('retrieved', $result['message']);
    }

    public function testGetSchemaExceptionViaExecuteFunction(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->tool->executeFunction('get_schema', ['999']);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // createSchema
    // ------------------------------------------------------------------

    public function testCreateSchemaSuccess(): void
    {
        $schema = $this->createSchemaEntity(1, 'new-uuid', 'New', 'Desc', '1.0', ['x' => ['type' => 'string']]);
        $this->schemaMapper->method('createFromArray')->willReturn($schema);

        $result = $this->tool->createSchema('New', ['x' => ['type' => 'string']], 'Desc', ['x']);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('created', $result['message']);
    }

    public function testCreateSchemaWithoutRequired(): void
    {
        $schema = $this->createSchemaEntity(1, 'uuid', 'T', '', '1.0', ['a' => ['type' => 'int']]);

        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['required']);
            }))
            ->willReturn($schema);

        $this->tool->createSchema('T', ['a' => ['type' => 'int']]);
    }

    public function testCreateSchemaWithRequired(): void
    {
        $schema = $this->createSchemaEntity(1, 'uuid', 'T', '', '1.0');

        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return $data['required'] === ['name'];
            }))
            ->willReturn($schema);

        $this->tool->createSchema('T', [], '', ['name']);
    }

    public function testCreateSchemaException(): void
    {
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new \Exception('Invalid'));

        $result = $this->tool->executeFunction('create_schema', ['T', []]);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // updateSchema
    // ------------------------------------------------------------------

    public function testUpdateSchemaAllFields(): void
    {
        $schema = $this->createSchemaEntity(1, 'uuid', 'Old', 'Old desc', '1.0');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturnCallback(function ($entity) {
            return $entity;
        });

        $result = $this->tool->updateSchema('1', 'New', 'New desc', ['a' => 1], ['a']);
        $this->assertTrue($result['success']);
        $this->assertSame('New', $result['data']['title']);
        $this->assertSame('New desc', $result['data']['description']);
        $this->assertSame(['a' => 1], $result['data']['properties']);
        $this->assertStringContainsString('updated', $result['message']);
    }

    public function testUpdateSchemaNoFields(): void
    {
        $schema = $this->createSchemaEntity(1, 'uuid', 'Title', 'Desc', '1.0');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturnCallback(function ($entity) {
            return $entity;
        });

        $result = $this->tool->updateSchema('1');
        $this->assertTrue($result['success']);
        // Title should remain unchanged.
        $this->assertSame('Title', $result['data']['title']);
    }

    public function testUpdateSchemaException(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->tool->executeFunction('update_schema', ['999']);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // deleteSchema
    // ------------------------------------------------------------------

    public function testDeleteSchemaSuccess(): void
    {
        $schema = $this->createSchemaEntity(1, 'uuid', 'S');
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->expects($this->once())->method('delete');

        $result = $this->tool->deleteSchema('1');
        $this->assertTrue($result['success']);
        $this->assertSame('1', $result['data']['id']);
        $this->assertStringContainsString('deleted', $result['message']);
    }

    public function testDeleteSchemaException(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Has objects'));

        $result = $this->tool->executeFunction('delete_schema', ['1']);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // executeFunction with explicit userId
    // ------------------------------------------------------------------

    public function testExecuteFunctionWithExplicitUserId(): void
    {
        $noUserSession = $this->createMock(IUserSession::class);
        $noUserSession->method('getUser')->willReturn(null);
        $tool = new SchemaTool($noUserSession, $this->logger, $this->schemaMapper);

        $this->schemaMapper->method('findAll')->willReturn([]);

        $result = $tool->executeFunction('list_schemas', [], 'explicit-user');
        $this->assertTrue($result['success']);
    }
}
