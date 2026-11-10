<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Tool;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Mcp\Tool\ObjectsTool;
use OCA\OpenRegister\Mcp\Tool\ToolCallTrail;
use OCA\OpenRegister\Mcp\Tool\RegisterTool;
use OCA\OpenRegister\Mcp\Tool\SchemaTool;
use OCA\OpenRegister\Service\RegisterServiceInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Pins how the model's arguments reach the schema, register and object
 * create, update and list functions: by position through executeFunction(),
 * by name through the objects tool, and by name or position through a
 * snake_case call, with a (string) 'null' falling back to the default.
 */
class ToolCommandBindingTest extends TestCase
{

    /**
     * The calls the stores received, in order.
     *
     * @var array<int, array<int, mixed>>
     */
    private array $calls = [];

    public function testCreateSchemaBindsExecuteFunctionParametersByPosition(): void
    {
        $result = $this->schemaTool()->executeFunction(
            'create_schema',
            ['title' => 'Case', 'properties' => ['a' => []], 'description' => 'Cases', 'required' => ['a']]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            [['createFromArray', ['title' => 'Case', 'description' => 'Cases', 'properties' => ['a' => []], 'required' => ['a']]]],
            $this->calls
        );
    }

    public function testListSchemasBindsASnakeCaseCallByNameWithDefaults(): void
    {
        $json = $this->schemaTool()->list_schemas(register: '5');

        $this->assertTrue(json_decode($json, true)['success']);
        $this->assertSame([['findAll', 100, 0, ['register' => '5']]], $this->calls);
    }

    public function testUpdateSchemaBindsASnakeCaseCallByPosition(): void
    {
        $json = $this->schemaTool()->update_schema('12', 'null', 'New description');

        $this->assertTrue(json_decode($json, true)['success']);
        $this->assertSame(['find', '12'], $this->calls[0]);
        $this->assertSame('old title', $this->calls[1][1]->getTitle());
        $this->assertSame('New description', $this->calls[1][1]->getDescription());
    }

    public function testCreateRegisterBindsExecuteFunctionParametersByPosition(): void
    {
        $result = $this->registerTool()->executeFunction('create_register', ['title' => 'Reg', 'description' => 'D', 'slug' => 'reg']);

        $this->assertTrue($result['success']);
        $this->assertSame([['createFromArray', ['title' => 'Reg', 'description' => 'D', 'slug' => 'reg']]], $this->calls);
    }

    public function testUpdateRegisterTreatsAStringNullAsTheDefault(): void
    {
        $json = $this->registerTool()->update_register('7', 'null', 'New description');

        $this->assertTrue(json_decode($json, true)['success']);
        $this->assertSame([['updateFromArray', 7, ['description' => 'New description']]], $this->calls);
    }

    public function testCreateObjectBindsNamedParametersByName(): void
    {
        $result = $this->objectsTool()->executeFunction(
            'create_object',
            ['data' => ['name' => 'x'], 'schema' => '20', 'register' => '10', 'bogus' => 1]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            [['saveObject', ['name' => 'x', '@self' => ['register' => '10', 'schema' => '20']], 10, 20]],
            $this->calls
        );
    }

    private function session(): IUserSession
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    private function schemaTool(): SchemaTool
    {
        $mapper = $this->createMock(SchemaMapperInterface::class);
        $mapper->method('createFromArray')->willReturnCallback(
            function (array $object): Schema {
                $this->calls[] = ['createFromArray', $object];
                return new Schema();
            }
        );
        $mapper->method('findAll')->willReturnCallback(
            function (?int $limit = null, ?int $offset = null, ?array $filters = null): array {
                $this->calls[] = ['findAll', $limit, $offset, $filters];
                return [];
            }
        );
        $mapper->method('find')->willReturnCallback(
            function (string|int $id): Schema {
                $this->calls[] = ['find', $id];
                $schema = new Schema();
                $schema->setTitle('old title');
                return $schema;
            }
        );
        $mapper->method('update')->willReturnCallback(
            function (Schema $entity): Schema {
                $this->calls[] = ['update', clone $entity];
                return $entity;
            }
        );

        return new SchemaTool(new ToolCallTrail($this->session(), $this->createMock(LoggerInterface::class)), $mapper);
    }

    private function registerTool(): RegisterTool
    {
        $service = $this->createMock(RegisterServiceInterface::class);
        $service->method('createFromArray')->willReturnCallback(
            function (array $data): Register {
                $this->calls[] = ['createFromArray', $data];
                return new Register();
            }
        );
        $service->method('updateFromArray')->willReturnCallback(
            function (int $id, array $data): Register {
                $this->calls[] = ['updateFromArray', $id, $data];
                return new Register();
            }
        );

        return new RegisterTool(new ToolCallTrail($this->session(), $this->createMock(LoggerInterface::class)), $service);
    }

    private function objectsTool(): ObjectsTool
    {
        $service = $this->createMock(ObjectOperationsInterface::class);
        $service->method('saveObject')->willReturnCallback(
            function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
                $register = $options->target->register;
                $schema = $options->target->schema;
                $this->calls[] = ['saveObject', $object, $register, $schema];
                return new ObjectEntity();
            }
        );

        return new ObjectsTool(new ToolCallTrail($this->session(), $this->createMock(LoggerInterface::class)), $service);
    }
}
