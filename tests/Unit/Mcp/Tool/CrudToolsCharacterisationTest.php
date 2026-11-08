<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Tool;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Db\ApplicationMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Tool\AgentTool;
use OCA\OpenRegister\Mcp\Tool\ApplicationTool;
use OCA\OpenRegister\Mcp\Tool\ObjectsTool;
use OCA\OpenRegister\Mcp\Tool\RegisterTool;
use OCA\OpenRegister\Mcp\Tool\SchemaTool;
use OCA\OpenRegister\Service\RegisterServiceInterface;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what the agent, application, schema and register MCP tools write and return.
 *
 * @covers \OCA\OpenRegister\Mcp\Tool\AgentTool
 * @covers \OCA\OpenRegister\Mcp\Tool\ApplicationTool
 * @covers \OCA\OpenRegister\Mcp\Tool\SchemaTool
 * @covers \OCA\OpenRegister\Mcp\Tool\RegisterTool
 * @covers \OCA\OpenRegister\Mcp\Tool\ObjectsTool
 */
class CrudToolsCharacterisationTest extends TestCase
{

    /**
     * An agent tool whose mapper records what it was given.
     *
     * @param array<int, Agent> $seen Receives every agent the mapper is handed.
     *
     * @return AgentTool The tool.
     */
    private function agentTool(array &$seen, ?Exception $failure = null): AgentTool
    {
        $mapper = $this->createMock(AgentMapper::class);
        $store  = function (Agent $agent) use (&$seen, $failure): Agent {
            $seen[] = clone $agent;
            if ($failure !== null) {
                throw $failure;
            }

            $agent->setUuid('agent-uuid');
            return $agent;
        };
        $mapper->method('insert')->willReturnCallback($store);
        $mapper->method('update')->willReturnCallback($store);
        $mapper->method('findByUuid')->willReturnCallback(
            function (string $uuid): Agent {
                if ($uuid === 'missing') {
                    throw new DoesNotExistException('gone');
                }

                $agent = new Agent();
                $agent->setName('old name');
                $agent->setDescription('old description');
                $agent->setPrompt('old prompt');
                return $agent;
            }
        );

        return new AgentTool($mapper, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
    }

    public function testCreateAgentSetsOnlyNonEmptyFieldsAndTheContextOwner(): void
    {
        $seen = [];
        $tool = $this->agentTool($seen);
        $context = new Agent();
        $context->setOwner('alice');
        $tool->setAgent($context);

        $result = $tool->createAgent('Helper', 'Helps', '', 'Be kind');

        $this->assertTrue($result['success']);
        $this->assertSame("Agent 'Helper' created successfully with UUID agent-uuid.", $result['message']);
        $this->assertSame('Helper', $seen[0]->getName());
        $this->assertSame('Helps', $seen[0]->getDescription());
        $this->assertNull($seen[0]->getType());
        $this->assertSame('Be kind', $seen[0]->getPrompt());
        $this->assertSame('alice', $seen[0]->getOwner());
    }

    public function testCreateAgentWithoutContextLeavesTheOwnerAndSkipsNulls(): void
    {
        $seen = [];
        $tool = $this->agentTool($seen);

        $tool->createAgent('Helper', null, 'chat', null);

        $this->assertNull($seen[0]->getDescription());
        $this->assertSame('chat', $seen[0]->getType());
        $this->assertNull($seen[0]->getPrompt());
        $this->assertNull($seen[0]->getOwner());
    }

    public function testCreateAgentFailureIsAnError(): void
    {
        $seen = [];
        $result = $this->agentTool($seen, new Exception('db down'))->createAgent('Helper');

        $this->assertSame(['success' => false, 'error' => 'Failed to create agent: db down'], $result);
    }

    public function testUpdateAgentChangesOnlyTheGivenFields(): void
    {
        $seen = [];
        $result = $this->agentTool($seen)->updateAgent('a-1', null, '', 'New prompt');

        $this->assertTrue($result['success']);
        $this->assertSame('Agent updated successfully.', $result['message']);
        $this->assertSame('old name', $seen[0]->getName());
        $this->assertSame('', $seen[0]->getDescription());
        $this->assertSame('New prompt', $seen[0]->getPrompt());
    }

    public function testUpdateAgentNotFoundAndFailure(): void
    {
        $seen = [];
        $this->assertSame(
            ['success' => false, 'error' => "Agent with UUID 'missing' not found."],
            $this->agentTool($seen)->updateAgent('missing', 'x')
        );
        $this->assertSame(
            ['success' => false, 'error' => 'Failed to update agent: db down'],
            $this->agentTool($seen, new Exception('db down'))->updateAgent('a-1', 'x')
        );
    }

    /**
     * An application tool whose mapper records what it was given.
     *
     * @param array<int, Application> $seen Receives every application the mapper is handed.
     *
     * @return ApplicationTool The tool.
     */
    private function applicationTool(array &$seen, ?Exception $failure = null): ApplicationTool
    {
        $mapper = $this->createMock(ApplicationMapperInterface::class);
        $store  = function (Application $app) use (&$seen, $failure): Application {
            $seen[] = clone $app;
            if ($failure !== null) {
                throw $failure;
            }

            $app->setUuid('app-uuid');
            return $app;
        };
        $mapper->method('insert')->willReturnCallback($store);
        $mapper->method('update')->willReturnCallback($store);
        $mapper->method('findByUuid')->willReturnCallback(
            function (string $uuid): Application {
                if ($uuid === 'missing') {
                    throw new DoesNotExistException('gone');
                }

                $app = new Application();
                $app->setName('old name');
                $app->setDescription('old description');
                return $app;
            }
        );

        return new ApplicationTool($mapper, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
    }

    public function testCreateApplication(): void
    {
        $seen = [];
        $tool = $this->applicationTool($seen);

        $result = $tool->createApplication('Zaken', 'Cases', 'ignored');
        $tool->createApplication('Leeg', '');

        $this->assertTrue($result['success']);
        $this->assertSame("Application 'Zaken' created successfully with UUID app-uuid.", $result['message']);
        $this->assertSame('Cases', $seen[0]->getDescription());
        $this->assertNull($seen[1]->getDescription());
        $this->assertSame(
            ['success' => false, 'error' => 'Failed to create application: db down'],
            $this->applicationTool($seen, new Exception('db down'))->createApplication('Zaken')
        );
    }

    public function testUpdateApplication(): void
    {
        $seen = [];
        $result = $this->applicationTool($seen)->updateApplication('a-1', 'New', null);

        $this->assertSame('Application updated successfully.', $result['message']);
        $this->assertSame('New', $seen[0]->getName());
        $this->assertSame('old description', $seen[0]->getDescription());
        $this->assertSame(
            ['success' => false, 'error' => "Application with UUID 'missing' not found."],
            $this->applicationTool($seen)->updateApplication('missing', 'x')
        );
        $this->assertSame(
            ['success' => false, 'error' => 'Failed to update application: db down'],
            $this->applicationTool($seen, new Exception('db down'))->updateApplication('a-1', 'x')
        );
    }

    /**
     * A stored schema.
     *
     * @return Schema The schema.
     */
    private function storedSchema(): Schema
    {
        $schema = new Schema();
        $schema->setId(7);
        $schema->setUuid('schema-uuid');
        $schema->setTitle('Old');
        $schema->setDescription('Old description');
        $schema->setVersion('1.0.0');
        $schema->setProperties(['a' => ['type' => 'string']]);
        $schema->setRequired(['a']);
        return $schema;
    }

    public function testCreateSchemaPassesRequiredOnlyWhenGiven(): void
    {
        $mapper = $this->createMock(SchemaMapperInterface::class);
        $given  = [];
        $mapper->method('createFromArray')->willReturnCallback(
            function (array $object) use (&$given): Schema {
                $given[] = $object;
                return $this->storedSchema();
            }
        );
        $tool = new SchemaTool($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $mapper);

        $result = $tool->createSchema('T', ['p' => []], 'D', ['p']);
        $tool->createSchema('T', ['p' => []]);

        $this->assertSame(['title' => 'T', 'description' => 'D', 'properties' => ['p' => []], 'required' => ['p']], $given[0]);
        $this->assertSame(['title' => 'T', 'description' => '', 'properties' => ['p' => []]], $given[1]);
        $this->assertSame(
            [
                'success' => true,
                'message' => 'Schema created successfully',
                'data' => [
                    'id' => 7,
                    'uuid' => 'schema-uuid',
                    'title' => 'Old',
                    'description' => 'Old description',
                    'version' => '1.0.0',
                    'properties' => ['a' => ['type' => 'string']],
                ],
            ],
            $result
        );
    }

    public function testListSchemasFiltersByRegisterWhenGiven(): void
    {
        $mapper = $this->createMock(SchemaMapperInterface::class);
        $calls  = [];
        $mapper->method('findAll')->willReturnCallback(
            function (...$args) use (&$calls): array {
                $calls[] = array_slice($args, 0, 3);
                return [$this->storedSchema()];
            }
        );
        $tool = new SchemaTool($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $mapper);

        $result = $tool->listSchemas(5, 10, 'reg-1');
        $tool->listSchemas();

        $this->assertSame([5, 10, ['register' => 'reg-1']], $calls[0]);
        $this->assertSame([100, 0, []], $calls[1]);
        $this->assertSame('Found 1 schemas', $result['message']);
        $this->assertSame(
            [['id' => 7, 'uuid' => 'schema-uuid', 'title' => 'Old', 'description' => 'Old description', 'version' => '1.0.0']],
            $result['data']
        );
    }

    /**
     * A schema tool over one stored schema, recording the versioning calls.
     *
     * @param array<int, string> $log Receives the calls in order.
     *
     * @return SchemaTool The tool.
     */
    private function schemaTool(array &$log, bool $versioned, bool $changes): SchemaTool
    {
        $schema = $this->storedSchema();
        $mapper = $this->createMock(SchemaMapperInterface::class);
        $mapper->method('find')->willReturn($schema);
        $mapper->method('update')->willReturnCallback(
            function (Schema $entity) use (&$log): Schema {
                $log[] = 'update:' . $entity->getTitle() . '|' . $entity->getDescription() . '|' . json_encode($entity->getProperties()) . '|' . json_encode($entity->getRequired()) . '|' . $entity->getVersion();
                return $entity;
            }
        );

        $versioning = null;
        if ($versioned === true) {
            $changeSet  = new SchemaChangeSet([], 'none', 'none');
            if ($changes === true) {
                $changeSet = new SchemaChangeSet([['kind' => 'x']], 'breaking', 'major');
            }

            $versioning = $this->createMock(SchemaVersioningService::class);
            $versioning->method('classify')->willReturnCallback(
                function (Schema $existing, array $newDefinition) use (&$log, $changeSet): SchemaChangeSet {
                    $log[] = 'classify:' . $existing->getTitle() . ':' . json_encode($newDefinition);
                    return $changeSet;
                }
            );
            $versioning->method('nextVersion')->willReturn('2.0.0');
            $versioning->method('recordChangelog')->willReturnCallback(
                function (int $schemaId, ?string $version, SchemaChangeSet $set, bool $acknowledged, ?string $origin) use (&$log) {
                    $log[] = 'changelog:' . $schemaId . ':' . $version . ':' . var_export($acknowledged, true) . ':' . $origin;
                    return null;
                }
            );
        }

        return new SchemaTool($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $mapper, $versioning);
    }

    public function testUpdateSchemaWithBreakingChangeBumpsAndRecords(): void
    {
        $log    = [];
        $result = $this->schemaTool($log, true, true)->updateSchema('7', 'New', null, null, ['a', 'b']);

        $this->assertSame(
            [
                'classify:Old:{"properties":{"a":{"type":"string"}},"required":["a","b"]}',
                'update:New|Old description|{"a":{"type":"string"}}|["a","b"]|2.0.0',
                'changelog:7:2.0.0:false:agent tool',
            ],
            $log
        );
        $this->assertSame('Schema updated successfully', $result['message']);
        $this->assertSame('2.0.0', $result['data']['version']);
    }

    public function testUpdateSchemaWithoutChangesStillRecordsTheEmptyChangeSet(): void
    {
        $log = [];
        $this->schemaTool($log, true, false)->updateSchema('7', null, 'D', ['b' => []]);

        $this->assertSame(
            [
                'classify:Old:{"properties":{"b":[]},"required":["a"]}',
                'update:Old|D|{"b":[]}|["a"]|1.0.0',
                'changelog:7:1.0.0:false:agent tool',
            ],
            $log
        );
    }

    public function testUpdateSchemaWithoutDefinitionChangeOrVersioningDoesNotClassify(): void
    {
        $log = [];
        $this->schemaTool($log, true, true)->updateSchema('7', 'T', 'D');
        $this->schemaTool($log, false, true)->updateSchema('7', null, null, ['c' => []], ['c']);

        $this->assertSame(
            [
                'update:T|D|{"a":{"type":"string"}}|["a"]|1.0.0',
                'update:Old|Old description|{"c":[]}|["c"]|1.0.0',
            ],
            $log
        );
    }

    /**
     * A register tool recording what its service is given.
     *
     * @param array<int, array<int|string, mixed>> $calls Receives every service call.
     *
     * @return RegisterTool The tool.
     */
    private function registerTool(array &$calls): RegisterTool
    {
        $register = new Register();
        $register->setId(3);
        $register->setUuid('reg-uuid');
        $register->setTitle('R');
        $register->setDescription('RD');
        $register->setSlug('r');

        $service = $this->createMock(RegisterServiceInterface::class);
        $service->method('createFromArray')->willReturnCallback(
            function (array $data) use (&$calls, $register): Register {
                $calls[] = ['create', $data];
                return $register;
            }
        );
        $service->method('updateFromArray')->willReturnCallback(
            function (int $id, array $data) use (&$calls, $register): Register {
                $calls[] = ['update', $id, $data];
                return $register;
            }
        );

        return new RegisterTool($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $service);
    }

    public function testCreateAndUpdateRegister(): void
    {
        $calls = [];
        $tool  = $this->registerTool($calls);

        $created = $tool->createRegister('R', 'RD', 'r');
        $tool->createRegister('R');
        $updated = $tool->updateRegister('3', null, 'RD');
        $tool->updateRegister('3', 'R', null);

        $this->assertSame(
            [
                ['create', ['title' => 'R', 'description' => 'RD', 'slug' => 'r']],
                ['create', ['title' => 'R', 'description' => '']],
                ['update', 3, ['description' => 'RD']],
                ['update', 3, ['title' => 'R']],
            ],
            $calls
        );
        $data = ['id' => 3, 'uuid' => 'reg-uuid', 'title' => 'R', 'description' => 'RD', 'slug' => 'r'];
        $this->assertSame(['success' => true, 'message' => 'Register created successfully', 'data' => $data], $created);
        $this->assertSame(['success' => true, 'message' => 'Register updated successfully', 'data' => $data], $updated);
    }

    public function testUpdateRegisterWithNothingToChangeIsRefused(): void
    {
        $calls = [];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No update data provided');

        $this->registerTool($calls)->updateRegister('3');
    }

    /**
     * An objects tool recording what its object service is given.
     *
     * @param array<int, array<int|string, mixed>> $calls Receives every service call.
     *
     * @return ObjectsTool The tool.
     */
    private function objectsTool(array &$calls, array $found): ObjectsTool
    {
        $object = new ObjectEntity();
        $object->setId(11);
        $object->setUuid('obj-uuid');
        $object->setRegister('2');
        $object->setSchema('5');
        $object->setObject(['name' => 'x']);

        $service = $this->createMock(ObjectOperationsInterface::class);
        $service->method('findAll')->willReturnCallback(
            function (array $config) use (&$calls, $found, $object): array {
                $calls[] = ['findAll', $config];
                return $found + ['results' => [$object]];
            }
        );
        $service->method('saveObject')->willReturnCallback(
            function (...$args) use (&$calls, $object): ObjectEntity {
                $calls[] = ['saveObject', $args[0], $args[2], $args[3]];
                return $object;
            }
        );

        return new ObjectsTool($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $service);
    }

    public function testSearchObjectsBuildsFiltersFromTheGivenCriteria(): void
    {
        $calls = [];
        $tool  = $this->objectsTool($calls, ['total' => 40]);

        $result = $tool->searchObjects(5, 10, 'r', 's', 'term');
        $tool->searchObjects(20, 0, null, 's', '');
        $tool->searchObjects();

        $this->assertSame(
            [
                ['findAll', ['limit' => 5, 'offset' => 10, 'filters' => ['register' => 'r', 'schema' => 's', '_search' => 'term']]],
                ['findAll', ['limit' => 20, 'offset' => 0, 'filters' => ['schema' => 's']]],
                ['findAll', ['limit' => 20, 'offset' => 0, 'filters' => []]],
            ],
            $calls
        );
        $this->assertSame('Found 1 objects', $result['message']);
        $this->assertSame(40, $result['data']['total']);
        $this->assertSame(
            ['id' => 11, 'uuid' => 'obj-uuid', 'register' => '2', 'schema' => '5', 'data' => ['id' => 'obj-uuid', 'name' => 'x'], 'created' => null, 'updated' => null],
            $result['data']['objects'][0]
        );
    }

    public function testSearchObjectsCountsTheListWhenNoTotalIsGiven(): void
    {
        $calls  = [];
        $result = $this->objectsTool($calls, [])->searchObjects();

        $this->assertSame(1, $result['data']['total']);
    }

    public function testCreateObjectStampsTheScopeAndCastsTheIds(): void
    {
        $calls  = [];
        $result = $this->objectsTool($calls, [])->createObject('2', '5', ['name' => 'x', '@self' => ['owner' => 'bob']]);

        $this->assertSame(
            [['saveObject', ['name' => 'x', '@self' => ['register' => '2', 'schema' => '5']], 2, 5]],
            $calls
        );
        $this->assertSame(
            [
                'success' => true,
                'message' => 'Object created successfully',
                'data' => ['id' => 11, 'uuid' => 'obj-uuid', 'register' => '2', 'schema' => '5', 'data' => ['id' => 'obj-uuid', 'name' => 'x']],
            ],
            $result
        );
    }
}
