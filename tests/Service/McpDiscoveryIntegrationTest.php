<?php

/**
 * Integration tests for the MCP discovery + protocol surface.
 *
 * Verifies the two-tier discovery API (catalog → capability detail)
 * and the JSON-RPC protocol services (initialize, session, tools,
 * resources) end-to-end against the real DI container. Confirms the
 * MCP spec contract surface is wired correctly to OpenRegister's
 * data layer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\McpDiscoveryService;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class McpDiscoveryIntegrationTest extends TestCase
{
    private McpDiscoveryService $discovery;
    private McpProtocolService $protocol;
    private McpToolsService $tools;
    private McpResourcesService $resources;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discovery = \OC::$server->get(McpDiscoveryService::class);
        $this->protocol  = \OC::$server->get(McpProtocolService::class);
        $this->tools     = \OC::$server->get(McpToolsService::class);
        $this->resources = \OC::$server->get(McpResourcesService::class);
    }

    public function testCatalogReturnsCanonicalCapabilities(): void
    {
        $catalog = $this->discovery->getCatalog();

        $this->assertSame('OpenRegister', $catalog['name']);
        $this->assertArrayHasKey('version', $catalog);
        $this->assertArrayHasKey('capabilities', $catalog);
        $this->assertArrayHasKey('authentication', $catalog);

        $ids = array_map(fn($c) => $c['id'], $catalog['capabilities']);
        // The 10 stable capability IDs documented in the spec — every
        // tier-1 entry MUST be present, in any order.
        foreach (['registers', 'schemas', 'objects', 'search', 'files', 'audit', 'bulk', 'webhooks', 'chat', 'views'] as $expected) {
            $this->assertContains($expected, $ids, "catalog MUST surface capability '$expected'");
        }

        // Each capability MUST carry a drill-down href for tier-2 navigation.
        foreach ($catalog['capabilities'] as $cap) {
            $this->assertArrayHasKey('href', $cap, "capability '{$cap['id']}' MUST have an href for tier-2 detail");
            $this->assertNotEmpty($cap['href']);
        }
    }

    public function testGetCapabilityIdsMatchesCatalogIds(): void
    {
        $catalog = $this->discovery->getCatalog();
        $ids     = $this->discovery->getCapabilityIds();

        $catalogIds = array_map(fn($c) => $c['id'], $catalog['capabilities']);
        sort($ids);
        sort($catalogIds);
        $this->assertSame($catalogIds, $ids, 'getCapabilityIds() MUST be in lockstep with the tier-1 catalog');
    }

    public function testCapabilityDetailReturnsTier2ForKnownId(): void
    {
        $detail = $this->discovery->getCapabilityDetail('objects');
        $this->assertIsArray($detail);
        $this->assertSame('objects', $detail['id'] ?? null);
        // Tier-2 detail MUST go beyond the bare description — at minimum
        // it carries an `endpoints` map (or equivalent operations list)
        // that drives token-efficient API exploration.
        $this->assertNotEmpty(
            $detail['endpoints'] ?? $detail['operations'] ?? null,
            'tier-2 capability detail MUST include endpoints or operations'
        );
    }

    public function testCapabilityDetailReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->discovery->getCapabilityDetail('does-not-exist'));
    }

    public function testProtocolInitializeReturnsServerInfo(): void
    {
        // initialize wraps the MCP result in `{result, sessionId}` so the
        // caller can pin a session id alongside the standard handshake.
        $envelope = $this->protocol->initialize(
            ['protocolVersion' => '2024-11-05', 'capabilities' => []],
            'admin'
        );
        $this->assertArrayHasKey('result', $envelope);
        $this->assertArrayHasKey('sessionId', $envelope);
        $this->assertNotEmpty($envelope['sessionId']);

        $result = $envelope['result'];
        $this->assertArrayHasKey('protocolVersion', $result);
        $this->assertArrayHasKey('serverInfo', $result);
        $this->assertSame('OpenRegister', $result['serverInfo']['name'] ?? null);
        $this->assertArrayHasKey('capabilities', $result);
    }

    public function testSessionLifecycleCreateValidateDestroy(): void
    {
        $sessionId = $this->protocol->createSession('admin');
        $this->assertIsString($sessionId);
        $this->assertNotEmpty($sessionId);

        $resolvedUid = $this->protocol->validateSession($sessionId);
        $this->assertSame('admin', $resolvedUid, 'validateSession MUST return the uid the session was created with');

        $this->protocol->destroySession($sessionId);

        $afterDestroy = $this->protocol->validateSession($sessionId);
        $this->assertNull($afterDestroy, 'validateSession on a destroyed session MUST return null');
    }

    public function testValidateSessionReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->protocol->validateSession('unknown-session-id'));
    }

    public function testListToolsReturnsToolDefinitions(): void
    {
        // listTools wraps in `{tools: [...]}` per MCP spec.
        $envelope = $this->tools->listTools();
        $this->assertArrayHasKey('tools', $envelope);
        $tools = $envelope['tools'];

        // Tools list MUST be non-empty — registers/schemas/objects tools
        // are unconditional baseline.
        $this->assertGreaterThan(0, count($tools), 'listTools MUST surface at least one tool');
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
        }

        // Specifically the three foundational tools MUST be present.
        $names = array_map(fn($t) => $t['name'], $tools);
        $this->assertContains('registers', $names);
        $this->assertContains('schemas',   $names);
        $this->assertContains('objects',   $names);
    }

    public function testListResourcesReturnsResourceDefinitions(): void
    {
        $envelope  = $this->resources->listResources();
        $this->assertArrayHasKey('resources', $envelope);
        $resources = $envelope['resources'];

        // Two unconditional baseline resources (`openregister://registers`,
        // `openregister://schemas`) plus one per register+schema pair.
        $this->assertGreaterThanOrEqual(2, count($resources));
        foreach ($resources as $resource) {
            $this->assertArrayHasKey('uri', $resource);
            $this->assertArrayHasKey('name', $resource);
            $this->assertArrayHasKey('mimeType', $resource);
            $this->assertStringStartsWith('openregister://', $resource['uri']);
        }

        $uris = array_map(fn($r) => $r['uri'], $resources);
        $this->assertContains('openregister://registers', $uris);
        $this->assertContains('openregister://schemas',   $uris);
    }

    public function testListTemplatesReturnsTemplateDefinitions(): void
    {
        $envelope  = $this->resources->listTemplates();
        $this->assertArrayHasKey('resourceTemplates', $envelope);
        $templates = $envelope['resourceTemplates'];

        $this->assertGreaterThan(0, count($templates));
        foreach ($templates as $template) {
            $this->assertArrayHasKey('uriTemplate', $template);
            $this->assertArrayHasKey('name', $template);
            $this->assertStringStartsWith('openregister://', $template['uriTemplate']);
        }
    }
}
