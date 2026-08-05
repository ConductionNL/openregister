<?php

declare(strict_types=1);

/**
 * Attributed-tool dual-surface tests (ADR-063 chain 3/3).
 *
 * Verifies REQ-ATTR-002 end-to-end at the unit level: ONE
 * AttributeToolProvider instance feeds BOTH serving surfaces — the
 * JSON-RPC `McpToolsService` (tools/list + tools/call) and the chat/facade
 * path (`ToolRegistry` + `ToolRegistrationListener` + `McpProviderBridge`,
 * read through `ToolRegistryFacade`) — with no new serving code, exactly
 * mirroring SchemaDerivedDualSurfaceTest for the derived provider.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/changes/or-mcp-tool-attribute/specs/ai-mcp/spec.md
 * @spec openspec/changes/or-mcp-tool-attribute/specs/mcp-discovery/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Listener\ToolRegistrationListener;
use OCA\OpenRegister\Mcp\AttributeToolScanner;
use OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\AttributeFixtureService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\HintScopeFixtureService;
use OCA\OpenRegister\Tool\AgentTool;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/Fixtures/AttributeFixtureService.php';
require_once __DIR__.'/Fixtures/HintScopeFixtureService.php';

/**
 * Dual-surface tests for the attribute-derived provider.
 */
class AttributeToolDualSurfaceTest extends TestCase
{

    /** @var AuditTrailMapper&MockObject */
    private $auditTrailMapper;

    /** @var LoggerInterface&MockObject */
    private $logger;


    protected function setUp(): void
    {
        parent::setUp();
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

    }//end setUp()


    /**
     * Build the attributed provider around the fixture service.
     *
     * @return AttributeToolProvider
     */
    private function attributedProvider(): AttributeToolProvider
    {
        $instance = new AttributeFixtureService();
        $scanner  = new AttributeToolScanner();

        $entries = $scanner->scanClass(appId: 'pipelinq', className: get_class($instance), logger: $this->logger);
        foreach ($entries as &$entry) {
            $entry['instance'] = $instance;
        }

        return new AttributeToolProvider(
            appId: 'pipelinq',
            entries: $entries,
            auditTrailMapper: $this->auditTrailMapper,
            logger: $this->logger
        );

    }//end attributedProvider()


    /**
     * Build the attributed provider around the hint/scope fixture service
     * (REQ-ATTR-005 — one method declares all four optional hint/scope
     * params).
     *
     * @return AttributeToolProvider
     */
    private function hintScopeProvider(): AttributeToolProvider
    {
        $instance = new HintScopeFixtureService();
        $scanner  = new AttributeToolScanner();

        $entries = $scanner->scanClass(appId: 'pipelinq', className: get_class($instance), logger: $this->logger);
        foreach ($entries as &$entry) {
            $entry['instance'] = $instance;
        }

        return new AttributeToolProvider(
            appId: 'pipelinq',
            entries: $entries,
            auditTrailMapper: $this->auditTrailMapper,
            logger: $this->logger
        );

    }//end hintScopeProvider()


    // ── JSON-RPC surface (McpToolsService) ───────────────────────────


    public function testAttributedToolsAreListedOnJsonRpcSurface(): void
    {
        $service = new McpToolsService(
            providers: [$this->attributedProvider()],
            logger: $this->logger
        );

        $ids = array_column($service->listTools()['tools'], 'id');

        $this->assertContains('pipelinq.createLead', $ids);
        $this->assertContains('pipelinq.logContactmoment', $ids);

    }//end testAttributedToolsAreListedOnJsonRpcSurface()


    public function testToolsCallRoutesToAttributeProviderInvokeTool(): void
    {
        $service = new McpToolsService(
            providers: [$this->attributedProvider()],
            logger: $this->logger
        );

        $result = $service->invokeTool('pipelinq.createLead', ['email' => 'a@example.com']);

        $this->assertFalse($result['isError']);
        $this->assertSame('a@example.com', $result['result']['email']);

    }//end testToolsCallRoutesToAttributeProviderInvokeTool()


    public function testAttributedIdsAreDisjointFromThreePartDerivedShapedIds(): void
    {
        // Attributed ids are two-part ({appId}.{toolName}); a schema-derived
        // id would be three-part ({appId}.{schema}.{verb}) — structurally
        // disjoint by construction (REQ-ATTR-002).
        $service = new McpToolsService(
            providers: [$this->attributedProvider()],
            logger: $this->logger
        );

        foreach (array_column($service->listTools()['tools'], 'id') as $id) {
            $this->assertSame(1, substr_count($id, '.'), "Attributed id '{$id}' must have exactly one dot.");
        }

    }//end testAttributedIdsAreDisjointFromThreePartDerivedShapedIds()


    // ── Chat/facade surface (ToolRegistry + bridge + facade) ─────────


    /**
     * Wire the REAL registry/listener/bridge/facade chain around one
     * attributed provider and return the facade.
     *
     * @param AttributeToolProvider|null $provider The provider to wire; defaults to {@see attributedProvider()}.
     *
     * @return ToolRegistryFacade
     */
    private function buildFacade(?AttributeToolProvider $provider = null): ToolRegistryFacade
    {
        $mcpToolsService = new McpToolsService(
            providers: [$provider ?? $this->attributedProvider()],
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


    public function testAttributedToolIsVisibleOnFacadeSurface(): void
    {
        $facade = $this->buildFacade();

        $descriptors = $facade->listTools();
        $mcpIds      = array_column($descriptors, 'mcpId');
        $names       = array_column($descriptors, 'name');

        $this->assertContains('pipelinq.createLead', $mcpIds);
        $this->assertContains('pipelinq_createLead', $names);

    }//end testAttributedToolIsVisibleOnFacadeSurface()


    public function testFacadeInvocationRoutesThroughTheSameAttributedProviderAndIsAudited(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('createToolInvocationEntry')
            ->with(
                'pipelinq.createLead',
                $this->matchesRegularExpression('/^[a-f0-9]{64}$/'),
                $this->callback(fn($summary) => $summary['isError'] === false),
                null,
                null,
                null,
                'lead-1'
            );

        $facade = $this->buildFacade();

        $result = $facade->invokeTool('pipelinq.createLead', ['email' => 'b@example.com']);

        $this->assertFalse($result['isError']);
        $this->assertSame('b@example.com', $result['result']['email']);

    }//end testFacadeInvocationRoutesThroughTheSameAttributedProviderAndIsAudited()


    // ── Hint/scope dual-surface proof (REQ-ATTR-005) ──────────────────


    public function testDeclaredHintsAndScopeAreVisibleOnJsonRpcSurface(): void
    {
        $service = new McpToolsService(
            providers: [$this->hintScopeProvider()],
            logger: $this->logger
        );

        $tools = $service->listTools()['tools'];
        $byId  = [];
        foreach ($tools as $tool) {
            $byId[$tool['id']] = $tool;
        }

        $deleteLead = $byId['pipelinq.deleteLead'];
        $this->assertFalse($deleteLead['readOnlyHint']);
        $this->assertTrue($deleteLead['destructiveHint']);
        $this->assertFalse($deleteLead['idempotentHint']);
        $this->assertSame('delete', $deleteLead['scope']);

    }//end testDeclaredHintsAndScopeAreVisibleOnJsonRpcSurface()


    public function testDeclaredHintsAndScopeAreVisibleOnFacadeSurface(): void
    {
        $facade = $this->buildFacade($this->hintScopeProvider());

        $descriptors = $facade->listTools();
        $byMcpId     = [];
        foreach ($descriptors as $descriptor) {
            $byMcpId[$descriptor['mcpId']] = $descriptor;
        }

        $deleteLead = $byMcpId['pipelinq.deleteLead'];
        $this->assertFalse($deleteLead['readOnlyHint']);
        $this->assertTrue($deleteLead['destructiveHint']);
        $this->assertFalse($deleteLead['idempotentHint']);
        $this->assertSame('delete', $deleteLead['scope']);

    }//end testDeclaredHintsAndScopeAreVisibleOnFacadeSurface()
}//end class
