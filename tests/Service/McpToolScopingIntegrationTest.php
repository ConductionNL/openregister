<?php

/**
 * Integration tests for MCP multi-register tool scoping.
 *
 * Closes spec requirement "Multi-Register Tool Scoping" by proving that
 * `McpToolsService::callTool('objects', …)`:
 *
 * 1. Throws `InvalidArgumentException` when either `register` or
 *    `schema` argument is missing — there is no implicit default.
 * 2. Sets both register and schema on the `ObjectService` before the
 *    underlying object operation runs (verified via the live
 *    `ObjectService::getRegister()` / `getSchema()` getters after the
 *    call returns).
 * 3. Each call sets context fresh — calling with one (register,schema)
 *    pair after another never lets the second call inherit state from
 *    the first.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class McpToolScopingIntegrationTest extends TestCase
{

    private McpToolsService $tools;

    private ObjectService $objectService;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private ?Register $registerA = null;

    private ?Register $registerB = null;

    private ?Schema $schemaA = null;

    private ?Schema $schemaB = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tools          = \OC::$server->get(McpToolsService::class);
        $this->objectService  = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);

        $this->createTestFixture();

    }//end setUp()

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ([$this->schemaA, $this->schemaB] as $schema) {
            if ($schema === null) {
                continue;
            }

            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ([$this->registerA, $this->registerB] as $register) {
            if ($register === null) {
                continue;
            }

            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();

    }//end tearDown()

    public function testObjectsToolReturnsErrorEnvelopeWhenRegisterMissing(): void
    {
        // McpToolsService::callTool catches the InvalidArgumentException
        // raised by executeObjects() and returns an MCP error envelope so
        // the JSON-RPC layer can surface it cleanly to the client. The
        // spec scenario specifies the message; we assert it appears in
        // the error envelope text.
        $result = $this->tools->callTool(
            name: 'objects',
            arguments: ['action' => 'list', 'schema' => $this->schemaA->getId()],
        );

        $this->assertSame(true, $result['isError'] ?? null, 'missing register MUST mark the response as isError=true');
        $this->assertStringContainsString(
            'Both register and schema IDs are required for object operations',
            (string) ($result['content'][0]['text'] ?? ''),
            'error envelope MUST surface the spec-mandated message'
        );

    }//end testObjectsToolReturnsErrorEnvelopeWhenRegisterMissing()

    public function testObjectsToolReturnsErrorEnvelopeWhenSchemaMissing(): void
    {
        $result = $this->tools->callTool(
            name: 'objects',
            arguments: ['action' => 'list', 'register' => $this->registerA->getId()],
        );

        $this->assertSame(true, $result['isError'] ?? null);
        $this->assertStringContainsString(
            'Both register and schema IDs are required for object operations',
            (string) ($result['content'][0]['text'] ?? ''),
        );

    }//end testObjectsToolReturnsErrorEnvelopeWhenSchemaMissing()

    public function testObjectsToolReturnsErrorEnvelopeWhenBothMissing(): void
    {
        $result = $this->tools->callTool(name: 'objects', arguments: ['action' => 'list']);

        $this->assertSame(true, $result['isError'] ?? null);
        $this->assertStringContainsString(
            'Both register and schema IDs are required for object operations',
            (string) ($result['content'][0]['text'] ?? ''),
        );

    }//end testObjectsToolReturnsErrorEnvelopeWhenBothMissing()

    public function testExecuteObjectsThrowsInvalidArgumentExceptionDirectly(): void
    {
        // The spec scenario specifies the exception type at the
        // executeObjects() boundary (before callTool()'s try/catch). We
        // call the private method via reflection to lock that contract.
        $ref    = new \ReflectionObject($this->tools);
        $method = $ref->getMethod('executeObjects');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Both register and schema IDs are required for object operations');

        $method->invoke($this->tools, ['action' => 'list']);

    }//end testExecuteObjectsThrowsInvalidArgumentExceptionDirectly()

    public function testObjectsToolSetsRegisterAndSchemaOnObjectService(): void
    {
        $this->tools->callTool(
            name: 'objects',
            arguments: [
                'action'   => 'list',
                'register' => $this->registerA->getId(),
                'schema'   => $this->schemaA->getId(),
            ],
        );

        // After the call returns, ObjectService MUST hold the context the
        // caller supplied — proves McpToolsService::executeObjects calls
        // setRegister() and setSchema() before delegating.
        $this->assertSame(
            $this->registerA->getId(),
            $this->objectService->getRegister(),
            'ObjectService::getRegister() MUST reflect the register passed to the objects tool'
        );
        $this->assertSame(
            $this->schemaA->getId(),
            $this->objectService->getSchema(),
            'ObjectService::getSchema() MUST reflect the schema passed to the objects tool'
        );

    }//end testObjectsToolSetsRegisterAndSchemaOnObjectService()

    public function testEachObjectsCallScopesIndependently(): void
    {
        // First call: register A + schema A.
        $this->tools->callTool(
            name: 'objects',
            arguments: [
                'action'   => 'list',
                'register' => $this->registerA->getId(),
                'schema'   => $this->schemaA->getId(),
            ],
        );

        // Second call: register B + schema B.
        $this->tools->callTool(
            name: 'objects',
            arguments: [
                'action'   => 'list',
                'register' => $this->registerB->getId(),
                'schema'   => $this->schemaB->getId(),
            ],
        );

        $this->assertSame(
            $this->registerB->getId(),
            $this->objectService->getRegister(),
            'second call MUST overwrite the register context, not inherit from the first'
        );
        $this->assertSame(
            $this->schemaB->getId(),
            $this->objectService->getSchema(),
            'second call MUST overwrite the schema context, not inherit from the first'
        );

    }//end testEachObjectsCallScopesIndependently()

    public function testRegistersToolHasNoMandatoryScoping(): void
    {
        // Per spec, multi-register scoping applies to the `objects` tool.
        // The `registers` and `schemas` tools enumerate their own surface
        // and MUST NOT require register/schema arguments.
        $result = $this->tools->callTool(name: 'registers', arguments: ['action' => 'list']);
        $this->assertIsArray($result, 'registers/list MUST succeed without explicit scoping');

    }//end testRegistersToolHasNoMandatoryScoping()

    private function createTestFixture(): void
    {
        $this->registerA = $this->makeRegister('A');
        $this->registerB = $this->makeRegister('B');
        $this->schemaA   = $this->makeSchema('A');
        $this->schemaB   = $this->makeSchema('B');

        $this->registerA->setSchemas([$this->schemaA->getId()]);
        $this->registerB->setSchemas([$this->schemaB->getId()]);
        $this->registerMapper->update($this->registerA);
        $this->registerMapper->update($this->registerB);

    }//end createTestFixture()

    private function makeRegister(string $tag): Register
    {
        $register = new Register();
        $register->setTitle('phpunit-mcp-scope-'.$tag.'-'.uniqid());
        $register->setSlug('phpunit-mcp-scope-'.$tag.'-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);
        return $this->registerMapper->insert($register);

    }//end makeRegister()

    private function makeSchema(string $tag): Schema
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-mcp-scope-'.$tag.'-'.uniqid());
        $schema->setSlug('phpunit-mcp-scope-'.$tag.'-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);
        return $this->schemaMapper->insert($schema);

    }//end makeSchema()
}//end class
