<?php

declare(strict_types=1);

/**
 * Schema-derived tool dual-surface tests (ADR-063 chain 2/3).
 *
 * Verifies REQ-DERIVED-002/003 end-to-end at the unit level: ONE
 * SchemaDerivedToolProvider instance feeds BOTH serving surfaces — the
 * JSON-RPC `McpToolsService` (tools/list + tools/call) and the chat/facade
 * path (`ToolRegistry` + `ToolRegistrationListener` + `McpProviderBridge`,
 * read through `ToolRegistryFacade`) — and hand-written > derived precedence
 * holds on the JSON-RPC surface (first-wins ordering + self-suppression).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/changes/or-mcp-derived-tool-provider/specs/ai-mcp/spec.md
 * @spec openspec/changes/or-mcp-derived-tool-provider/specs/mcp-discovery/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Listener\ToolRegistrationListener;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\AgentTool;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Dual-surface + precedence tests for the schema-derived provider.
 */
class SchemaDerivedDualSurfaceTest extends TestCase
{

    /** @var ObjectService&MockObject */
    private $objectService;

    /** @var AuditTrailMapper&MockObject */
    private $auditTrailMapper;

    /** @var LoggerInterface&MockObject */
    private $logger;


    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

    }//end setUp()


    /**
     * A real, setter-populated `lead` schema opted into all five verbs.
     *
     * @return Schema
     */
    private function leadSchema(): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug('lead');
        $schema->setProperties(['status' => ['type' => 'string'], 'name' => ['type' => 'string']]);
        $schema->setRequired(['name']);
        $schema->setConfiguration(
            [
                'x-openregister-mcp' => [
                    'enabled' => true,
                    'tools'   => [
                        'search' => ['filters' => ['status']],
                        'get'    => [],
                        'create' => [],
                        'update' => [],
                        'delete' => [
                            // ADR-063 annotation hints + `scope` on this verb
                            // ONLY — used by
                            // testDerivedToolHintsSurviveTheFacadeBridge() to
                            // prove neither is dropped anywhere between the
                            // derived provider and the facade (OR#369), and
                            // that the OTHER verbs — which declare no hints and
                            // no scope — stay phantom-key-free.
                            'readOnlyHint'    => false,
                            'destructiveHint' => true,
                            'idempotentHint'  => true,
                            'scope'           => 'delete',
                        ],
                    ],
                ],
            ]
        );

        return $schema;

    }//end leadSchema()


    /**
     * Build the derived provider around the `lead` schema.
     *
     * @param list<string> $suppressedIds Hand-written ids to self-suppress.
     *
     * @return SchemaDerivedToolProvider
     */
    private function derivedProvider(array $suppressedIds = []): SchemaDerivedToolProvider
    {
        return new SchemaDerivedToolProvider(
            appId: 'pipelinq',
            schemaEntries: [['schema' => $this->leadSchema(), 'register' => null]],
            suppressedIds: $suppressedIds,
            objectService: $this->objectService,
            auditTrailMapper: $this->auditTrailMapper,
            logger: $this->logger
        );

    }//end derivedProvider()


    /**
     * A hand-written per-app provider claiming `pipelinq.lead.search`.
     *
     * @return IMcpToolProvider&MockObject
     */
    private function handWrittenProvider(): IMcpToolProvider
    {
        $provider = $this->createMock(IMcpToolProvider::class);
        $provider->method('getAppId')->willReturn('pipelinq');
        $provider->method('getTools')->willReturn([
            [
                'id'          => 'pipelinq.lead.search',
                'name'        => 'lead_search',
                'description' => 'Hand-written lead search.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ]);
        $provider->method('invokeTool')->willReturn(['handWritten' => true]);

        return $provider;

    }//end handWrittenProvider()


    // ── JSON-RPC surface (McpToolsService) ───────────────────────────


    public function testDerivedToolsAreListedOnJsonRpcSurface(): void
    {
        $service = new McpToolsService(
            providers: [$this->derivedProvider()],
            logger: $this->logger
        );

        $ids = array_column($service->listTools()['tools'], 'id');

        $this->assertContains('pipelinq.lead.search', $ids);
        $this->assertContains('pipelinq.lead.get', $ids);
        $this->assertContains('pipelinq.lead.create', $ids);
        $this->assertContains('pipelinq.lead.update', $ids);
        $this->assertContains('pipelinq.lead.delete', $ids);

    }//end testDerivedToolsAreListedOnJsonRpcSurface()


    public function testToolsCallRoutesToDerivedProviderInvokeTool(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('jsonSerialize')->willReturn(['id' => 'uuid-1']);
        $this->objectService->method('find')->willReturn($object);

        $service = new McpToolsService(
            providers: [$this->derivedProvider()],
            logger: $this->logger
        );

        $result = $service->invokeTool('pipelinq.lead.get', ['id' => 'uuid-1']);

        $this->assertFalse($result['isError']);
        $this->assertSame(['id' => 'uuid-1'], $result['result']);

    }//end testToolsCallRoutesToDerivedProviderInvokeTool()


    public function testHandWrittenWinsAndDerivedDuplicateIsAbsent(): void
    {
        // Registration order mirrors Application.php: hand-written first,
        // derived appended LAST with the hand-written ids self-suppressed.
        $service = new McpToolsService(
            providers: [
                $this->handWrittenProvider(),
                $this->derivedProvider(suppressedIds: ['pipelinq.lead.search']),
            ],
            logger: $this->logger
        );

        $tools = $service->listTools()['tools'];
        $ids   = array_column($tools, 'id');

        // Exactly ONE pipelinq.lead.search — the hand-written one; the
        // derived duplicate is absent, not merely shadowed.
        $this->assertCount(1, array_keys($ids, 'pipelinq.lead.search', true));
        $searchIndex = array_search('pipelinq.lead.search', $ids, true);
        $this->assertSame('Hand-written lead search.', $tools[$searchIndex]['description']);

        // Non-colliding derived verbs still emit.
        $this->assertContains('pipelinq.lead.get', $ids);
        $this->assertContains('pipelinq.lead.create', $ids);
        $this->assertContains('pipelinq.lead.update', $ids);
        $this->assertContains('pipelinq.lead.delete', $ids);

        // tools/call on the collided id routes to the hand-written provider.
        $result = $service->invokeTool('pipelinq.lead.search', []);
        $this->assertSame(['handWritten' => true], $result['result']);

    }//end testHandWrittenWinsAndDerivedDuplicateIsAbsent()


    // ── Chat/facade surface (ToolRegistry + bridge + facade) ─────────


    /**
     * Wire the REAL registry/listener/bridge/facade chain around one
     * derived provider and return the facade.
     *
     * @return ToolRegistryFacade
     */
    private function buildFacade(): ToolRegistryFacade
    {
        $mcpToolsService = new McpToolsService(
            providers: [$this->derivedProvider()],
            logger: $this->logger
        );

        $listener = new ToolRegistrationListener(
            registerTool: $this->createMock(RegisterTool::class),
            schemaTool: $this->createMock(SchemaTool::class),
            objectsTool: $this->createMock(ObjectsTool::class),
            applicationTool: $this->createMock(ApplicationTool::class),
            agentTool: $this->createMock(AgentTool::class),
            mcpToolsService: $mcpToolsService,
            logger: $this->logger
        );

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')
            ->willReturnCallback(function ($event) use ($listener) {
                if ($event instanceof ToolRegistrationEvent) {
                    $listener->handle($event);
                }
            });

        $registry = new ToolRegistry($dispatcher, $this->logger);

        return new ToolRegistryFacade(toolRegistry: $registry, logger: $this->logger);

    }//end buildFacade()


    public function testDerivedToolIsVisibleOnFacadeSurface(): void
    {
        $facade = $this->buildFacade();

        $descriptors = $facade->listTools();
        $mcpIds      = array_column($descriptors, 'mcpId');
        $names       = array_column($descriptors, 'name');

        // Dotted id and `_`-alias forms both present, resolving to one tool.
        $this->assertContains('pipelinq.lead.search', $mcpIds);
        $this->assertContains('pipelinq_lead_search', $names);
        $this->assertContains('pipelinq.lead.get', $mcpIds);

    }//end testDerivedToolIsVisibleOnFacadeSurface()


    /**
     * Regression test for OR#369 (both halves): McpProviderBridge::getFunctions()
     * used to build every LLphant descriptor from exactly four keys (`name`,
     * `mcpId`, `description`, `parameters`), silently dropping the ADR-063
     * annotation hints SchemaDerivedToolProvider sets — AND
     * SchemaDerivedToolProvider::buildDescriptor() never emitted the dialect's
     * per-verb `scope` onto the descriptor at all, so `scope` was absent on
     * BOTH surfaces. This wires the REAL registry → listener → bridge → facade
     * chain (no bridge mocking) and asserts the hints AND `scope` declared on
     * `pipelinq.lead.delete` (see {@see leadSchema()}) survive all the way to
     * `ToolRegistryFacade::listTools()`, while a verb declaring neither
     * (`pipelinq.lead.get`) gains no phantom keys and the pre-existing four
     * descriptor keys are unchanged.
     */
    public function testDerivedToolHintsSurviveTheFacadeBridge(): void
    {
        $facade = $this->buildFacade();

        $descriptors = $facade->listTools();

        $deleteIndex = array_search('pipelinq.lead.delete', array_column($descriptors, 'mcpId'), true);
        $this->assertIsInt($deleteIndex);
        $delete = $descriptors[$deleteIndex];

        $this->assertArrayHasKey('readOnlyHint', $delete);
        $this->assertFalse($delete['readOnlyHint']);
        $this->assertArrayHasKey('destructiveHint', $delete);
        $this->assertTrue($delete['destructiveHint']);
        $this->assertArrayHasKey('idempotentHint', $delete);
        $this->assertTrue($delete['idempotentHint']);

        // The dialect's per-verb `scope` reaches the facade too — emitted by
        // the derived provider, forwarded by the bridge.
        $this->assertArrayHasKey('scope', $delete);
        $this->assertSame('delete', $delete['scope']);

        // The pre-existing four keys are unchanged by the hint/scope forwarding.
        $this->assertSame('pipelinq_lead_delete', $delete['name']);
        $this->assertSame('pipelinq.lead.delete', $delete['mcpId']);
        $this->assertArrayHasKey('description', $delete);
        $this->assertArrayHasKey('parameters', $delete);

        // A verb declaring no hints and no scope gains no phantom keys.
        $getIndex = array_search('pipelinq.lead.get', array_column($descriptors, 'mcpId'), true);
        $this->assertIsInt($getIndex);
        $get = $descriptors[$getIndex];

        $this->assertArrayNotHasKey('readOnlyHint', $get);
        $this->assertArrayNotHasKey('destructiveHint', $get);
        $this->assertArrayNotHasKey('idempotentHint', $get);
        $this->assertArrayNotHasKey('scope', $get);

    }//end testDerivedToolHintsSurviveTheFacadeBridge()


    /**
     * The dialect's per-verb `scope` must also reach the JSON-RPC surface —
     * `SchemaDerivedToolProvider::buildDescriptor()` emits it, so
     * `McpToolsService::listTools()` carries it verbatim. Pairs with the
     * facade assertion above to prove REQ-DERIVED-002's "one derivation feeds
     * both surfaces" holds for `scope`, not just for the hints.
     */
    public function testDerivedToolScopeIsEmittedOnJsonRpcSurface(): void
    {
        $service = new McpToolsService(
            providers: [$this->derivedProvider()],
            logger: $this->logger
        );

        $tools = $service->listTools()['tools'];
        $ids   = array_column($tools, 'id');

        $deleteIndex = array_search('pipelinq.lead.delete', $ids, true);
        $this->assertIsInt($deleteIndex);
        $this->assertSame('delete', $tools[$deleteIndex]['scope']);
        $this->assertTrue($tools[$deleteIndex]['destructiveHint']);

        // The hint-less / scope-less verb stays clean on this surface too.
        $getIndex = array_search('pipelinq.lead.get', $ids, true);
        $this->assertIsInt($getIndex);
        $this->assertArrayNotHasKey('scope', $tools[$getIndex]);
        $this->assertArrayNotHasKey('destructiveHint', $tools[$getIndex]);

    }//end testDerivedToolScopeIsEmittedOnJsonRpcSurface()


    public function testFacadeInvocationRoutesThroughTheSameDerivedProvider(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('jsonSerialize')->willReturn(['id' => 'uuid-9']);
        $this->objectService->method('find')->willReturn($object);

        // The facade invocation MUST hit the same audited invokeTool() path
        // the JSON-RPC surface uses — exactly one audit record.
        $this->auditTrailMapper->expects($this->once())
            ->method('createToolInvocationEntry')
            ->with(
                'pipelinq.lead.get',
                $this->matchesRegularExpression('/^[a-f0-9]{64}$/'),
                $this->callback(fn($summary) => $summary['isError'] === false),
                null,
                1,
                null,
                'uuid-9'
            );

        $facade = $this->buildFacade();

        $result = $facade->invokeTool('pipelinq.lead.get', ['id' => 'uuid-9']);

        $this->assertFalse($result['isError']);
        $this->assertSame(['id' => 'uuid-9'], $result['result']);

    }//end testFacadeInvocationRoutesThroughTheSameDerivedProvider()
}//end class
