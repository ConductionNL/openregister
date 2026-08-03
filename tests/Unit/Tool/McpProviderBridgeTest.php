<?php

namespace Unit\Tool;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Tool\McpProviderBridge;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for OR#369 — McpProviderBridge::getFunctions() used to
 * build every LLphant descriptor from exactly four keys (`name`, `mcpId`,
 * `description`, `parameters`), silently dropping any ADR-063 annotation
 * hint (`readOnlyHint` / `destructiveHint` / `idempotentHint`) or `scope`
 * a provider (e.g. SchemaDerivedToolProvider) had set on its descriptor.
 * These tests pin the fixed forwarding behaviour directly against the
 * bridge, independent of any real provider implementation.
 */
class McpProviderBridgeTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @param list<array<string,mixed>> $tools
     */
    private function bridgeFor(array $tools): McpProviderBridge
    {
        $provider = $this->createMock(IMcpToolProvider::class);
        $provider->method('getAppId')->willReturn('pipelinq');
        $provider->method('getTools')->willReturn($tools);

        return new McpProviderBridge(provider: $provider, logger: $this->logger);
    }

    public function testHintsAndScopeSurviveTheBridge(): void
    {
        $bridge = $this->bridgeFor([
            [
                'id'              => 'pipelinq.lead.delete',
                'name'            => 'lead_delete',
                'description'     => 'Delete a lead.',
                'inputSchema'     => ['type' => 'object', 'properties' => []],
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => true,
                'scope'           => 'delete',
            ],
        ]);

        $functions = $bridge->getFunctions();
        $this->assertCount(1, $functions);

        $function = $functions[0];
        $this->assertFalse($function['readOnlyHint']);
        $this->assertTrue($function['destructiveHint']);
        $this->assertTrue($function['idempotentHint']);
        $this->assertSame('delete', $function['scope']);
    }

    /**
     * 🔴 `reach` must survive the bridge.
     *
     * A consuming app that gates on reach and fails closed on its absence sees
     * a dropped key as "external" — so losing it here does not degrade to the
     * old behaviour, it strips the tool from every agent that had it. Hermiq
     * annotates all fourteen of its native tools this way, so the blast radius
     * of deleting the two lines this test guards is Hermiq's entire native
     * catalogue, with no error anywhere to explain it.
     */
    public function testReachSurvivesTheBridge(): void
    {
        $bridge = $this->bridgeFor([
            [
                'id'           => 'hermiq.webFetch',
                'name'         => 'web_fetch',
                'description'  => 'Fetch a URL.',
                'inputSchema'  => ['type' => 'object', 'properties' => []],
                'readOnlyHint' => true,
                'scope'        => 'read',
                'reach'        => 'external',
            ],
        ]);

        $function = $bridge->getFunctions()[0];

        // The pair that makes the point: identical `scope`, and `reach` is the
        // only thing telling the consumer this one leaves the building.
        $this->assertSame('read', $function['scope']);
        $this->assertSame('external', $function['reach']);
    }

    /**
     * The bridge forwards `reach` verbatim and classifies nothing — an
     * unrecognised value must arrive intact for the CONSUMER to reject, because
     * a bridge that silently dropped a malformed reach would hand the consumer
     * "absent" (fail-closed) when the truth is "wrong" (a bug to fix).
     */
    public function testAnUnrecognisedReachIsForwardedNotSanitised(): void
    {
        $bridge = $this->bridgeFor([
            [
                'id'          => 'pipelinq.lead.get',
                'name'        => 'lead_get',
                'description' => 'Get a lead.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'reach'       => 'galaxy',
            ],
        ]);

        $this->assertSame('galaxy', $bridge->getFunctions()[0]['reach']);
    }

    public function testDescriptorWithoutHintsGainsNoPhantomKeys(): void
    {
        $bridge = $this->bridgeFor([
            [
                'id'          => 'pipelinq.lead.get',
                'name'        => 'lead_get',
                'description' => 'Get a lead.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ]);

        $functions = $bridge->getFunctions();
        $this->assertCount(1, $functions);

        $function = $functions[0];
        $this->assertArrayNotHasKey('readOnlyHint', $function);
        $this->assertArrayNotHasKey('destructiveHint', $function);
        $this->assertArrayNotHasKey('idempotentHint', $function);
        $this->assertArrayNotHasKey('scope', $function);
        $this->assertArrayNotHasKey('reach', $function);
    }

    public function testExistingFourKeysAreUnchanged(): void
    {
        $bridge = $this->bridgeFor([
            [
                'id'              => 'pipelinq.lead.search',
                'name'            => 'lead_search',
                'description'     => 'Search leads.',
                'inputSchema'     => ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]],
                'readOnlyHint'    => true,
            ],
        ]);

        $functions = $bridge->getFunctions();
        $this->assertCount(1, $functions);

        $function = $functions[0];
        $this->assertSame('pipelinq_lead_search', $function['name']);
        $this->assertSame('pipelinq.lead.search', $function['mcpId']);
        $this->assertSame('Search leads.', $function['description']);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]],
            $function['parameters']
        );
        $this->assertTrue($function['readOnlyHint']);
    }

    public function testOnlyOneHintKeySetIsForwardedAlone(): void
    {
        $bridge = $this->bridgeFor([
            [
                'id'           => 'pipelinq.lead.create',
                'name'         => 'lead_create',
                'description'  => 'Create a lead.',
                'inputSchema'  => ['type' => 'object', 'properties' => []],
                'readOnlyHint' => false,
            ],
        ]);

        $function = $bridge->getFunctions()[0];
        $this->assertArrayHasKey('readOnlyHint', $function);
        $this->assertArrayNotHasKey('destructiveHint', $function);
        $this->assertArrayNotHasKey('idempotentHint', $function);
        $this->assertArrayNotHasKey('scope', $function);
        $this->assertArrayNotHasKey('reach', $function);
    }
}
