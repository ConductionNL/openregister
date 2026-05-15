<?php

declare(strict_types=1);

/**
 * McpToolsService Unit Tests
 *
 * Tests the provider-aggregator behaviour introduced by the
 * ai-chat-companion-orchestrator change: McpToolsService no longer owns
 * the registers/schemas/objects logic itself — it enumerates the
 * registered IMcpToolProvider implementations, aggregates their tool
 * descriptors, enforces the `{appId}.` namespace prefix, and routes
 * callTool()/invokeTool() to the owning provider. The relocated CRUD
 * logic is exercised by the per-provider tests under tests/Unit/Mcp/BuiltIn.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for McpToolsService (provider aggregator).
 */
class McpToolsServiceTest extends TestCase
{

    /** @var LoggerInterface&MockObject */
    private $logger;


    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);

    }//end setUp()


    /**
     * Build a stub IMcpToolProvider.
     *
     * @param string                               $appId  App id returned by getAppId()
     * @param array<int, array<string, mixed>>     $tools  Descriptors returned by getTools()
     * @param array<string, mixed>|\Throwable|null $invoke Result returned (or thrown) by invokeTool(); null = not configured
     *
     * @return IMcpToolProvider&MockObject
     */
    private function stubProvider(string $appId, array $tools = [], $invoke = null): IMcpToolProvider
    {
        $provider = $this->createMock(IMcpToolProvider::class);
        $provider->method('getAppId')->willReturn($appId);
        $provider->method('getTools')->willReturn($tools);

        if ($invoke instanceof \Throwable) {
            $provider->method('invokeTool')->willThrowException($invoke);
        } else if ($invoke !== null) {
            $provider->method('invokeTool')->willReturn($invoke);
        }

        return $provider;

    }//end stubProvider()


    /**
     * Build a single tool descriptor.
     *
     * @param string $id Tool id
     *
     * @return array<string, mixed>
     */
    private function descriptor(string $id): array
    {
        return [
            'id'          => $id,
            'name'        => $id,
            'description' => 'desc for '.$id,
            'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
        ];

    }//end descriptor()


    // ── listTools() ────────────────────────────────────────────────


    public function testListToolsAggregatesAcrossProviders(): void
    {
        $a = $this->stubProvider('foo', [$this->descriptor('foo.one'), $this->descriptor('foo.two')]);
        $b = $this->stubProvider('bar', [$this->descriptor('bar.alpha')]);

        $service = new McpToolsService([$a, $b], $this->logger);
        $result  = $service->listTools();

        $this->assertArrayHasKey('tools', $result);
        $ids = array_column($result['tools'], 'id');
        $this->assertSame(['foo.one', 'foo.two', 'bar.alpha'], $ids);

    }//end testListToolsAggregatesAcrossProviders()


    public function testListToolsEmptyWhenNoProviders(): void
    {
        $service = new McpToolsService([], $this->logger);
        $this->assertSame(['tools' => []], $service->listTools());

    }//end testListToolsEmptyWhenNoProviders()


    public function testListToolsDropsDescriptorWithNonConformingNamespacePrefix(): void
    {
        $provider = $this->stubProvider(
            'foo',
            [$this->descriptor('foo.good'), $this->descriptor('bar.bad'), $this->descriptor('')]
        );

        // Two malformed descriptors → two warnings.
        $this->logger->expects($this->exactly(2))->method('warning');

        $service = new McpToolsService([$provider], $this->logger);
        $result  = $service->listTools();

        $this->assertSame(['foo.good'], array_column($result['tools'], 'id'));

    }//end testListToolsDropsDescriptorWithNonConformingNamespacePrefix()


    public function testListToolsKeepsExactPrefixMatchesOnly(): void
    {
        // "foobar.x" must NOT be accepted as belonging to app "foo" — the
        // separator dot is required, so "foo." is the prefix, not "foo".
        $provider = $this->stubProvider('foo', [$this->descriptor('foobar.x'), $this->descriptor('foo.y')]);
        $this->logger->expects($this->once())->method('warning');

        $service = new McpToolsService([$provider], $this->logger);
        $this->assertSame(['foo.y'], array_column($service->listTools()['tools'], 'id'));

    }//end testListToolsKeepsExactPrefixMatchesOnly()


    // ── callTool() ─────────────────────────────────────────────────


    public function testCallToolRoutesToOwningProviderAndWrapsSuccess(): void
    {
        $provider = $this->createMock(IMcpToolProvider::class);
        $provider->method('getAppId')->willReturn('foo');
        $provider->method('getTools')->willReturn([$this->descriptor('foo.do')]);
        $provider->expects($this->once())
            ->method('invokeTool')
            ->with('foo.do', ['k' => 'v'])
            ->willReturn(['answer' => 42]);

        $service = new McpToolsService([$provider], $this->logger);
        $result  = $service->callTool('foo.do', ['k' => 'v']);

        $this->assertFalse($result['isError']);
        $this->assertSame('text', $result['content'][0]['type']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertSame(['answer' => 42], $decoded);

    }//end testCallToolRoutesToOwningProviderAndWrapsSuccess()


    public function testCallToolThrowsOnUnknownTool(): void
    {
        $service = new McpToolsService([$this->stubProvider('foo', [$this->descriptor('foo.x')])], $this->logger);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: bar.nope');
        $service->callTool('bar.nope', []);

    }//end testCallToolThrowsOnUnknownTool()


    public function testCallToolThrowsWhenPrefixMatchesButToolNotListed(): void
    {
        // Provider owns the "foo." namespace but does not list "foo.ghost".
        $service = new McpToolsService([$this->stubProvider('foo', [$this->descriptor('foo.real')])], $this->logger);

        $this->expectException(InvalidArgumentException::class);
        $service->callTool('foo.ghost', []);

    }//end testCallToolThrowsWhenPrefixMatchesButToolNotListed()


    public function testCallToolWrapsProviderExceptionAsErrorEnvelope(): void
    {
        $provider = $this->stubProvider(
            'foo',
            [$this->descriptor('foo.boom')],
            new \RuntimeException('kaboom')
        );
        $this->logger->expects($this->once())->method('error');

        $service = new McpToolsService([$provider], $this->logger);
        $result  = $service->callTool('foo.boom', []);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertSame(['error' => 'kaboom'], $decoded);

    }//end testCallToolWrapsProviderExceptionAsErrorEnvelope()


    public function testCallToolLogsDebugOnEveryCall(): void
    {
        $provider = $this->stubProvider('foo', [$this->descriptor('foo.x')], ['ok' => true]);
        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Tool call'), $this->arrayHasKey('tool'));

        $service = new McpToolsService([$provider], $this->logger);
        $service->callTool('foo.x', []);

    }//end testCallToolLogsDebugOnEveryCall()


    public function testCallToolFirstMatchingProviderWins(): void
    {
        $first = $this->createMock(IMcpToolProvider::class);
        $first->method('getAppId')->willReturn('foo');
        $first->method('getTools')->willReturn([$this->descriptor('foo.x')]);
        $first->expects($this->once())->method('invokeTool')->willReturn(['from' => 'first']);

        $second = $this->createMock(IMcpToolProvider::class);
        $second->method('getAppId')->willReturn('foo');
        $second->method('getTools')->willReturn([$this->descriptor('foo.x')]);
        $second->expects($this->never())->method('invokeTool');

        $service = new McpToolsService([$first, $second], $this->logger);
        $result  = $service->callTool('foo.x', []);

        $this->assertSame(['from' => 'first'], json_decode($result['content'][0]['text'], true));

    }//end testCallToolFirstMatchingProviderWins()


    // ── invokeTool() (flat envelope used by ChatStreamController) ───


    public function testInvokeToolReturnsFlatSuccessEnvelope(): void
    {
        $provider = $this->stubProvider('foo', [$this->descriptor('foo.do')], ['value' => 'x']);

        $service = new McpToolsService([$provider], $this->logger);
        $result  = $service->invokeTool('foo.do', ['a' => 1]);

        $this->assertSame(['result' => ['value' => 'x'], 'isError' => false], $result);

    }//end testInvokeToolReturnsFlatSuccessEnvelope()


    public function testInvokeToolUnknownToolReturnsErrorEnvelopeWithoutThrowing(): void
    {
        $service = new McpToolsService([$this->stubProvider('foo', [$this->descriptor('foo.x')])], $this->logger);

        $result = $service->invokeTool('bar.nope', []);

        $this->assertTrue($result['isError']);
        $this->assertSame('Unknown tool: bar.nope', $result['result']['error']);

    }//end testInvokeToolUnknownToolReturnsErrorEnvelopeWithoutThrowing()


    public function testInvokeToolWrapsProviderExceptionAsErrorEnvelope(): void
    {
        $provider = $this->stubProvider('foo', [$this->descriptor('foo.boom')], new \RuntimeException('nope'));
        $this->logger->expects($this->once())->method('error');

        $service = new McpToolsService([$provider], $this->logger);
        $result  = $service->invokeTool('foo.boom', []);

        $this->assertTrue($result['isError']);
        $this->assertSame('nope', $result['result']['error']);

    }//end testInvokeToolWrapsProviderExceptionAsErrorEnvelope()


    // ── addProvider() ──────────────────────────────────────────────


    public function testAddProviderRegistersAtRuntime(): void
    {
        $service = new McpToolsService([], $this->logger);
        $this->assertSame([], $service->listTools()['tools']);

        $service->addProvider($this->stubProvider('late', [$this->descriptor('late.tool')]));

        $this->assertSame(['late.tool'], array_column($service->listTools()['tools'], 'id'));

    }//end testAddProviderRegistersAtRuntime()


    public function testAddProviderToolBecomesCallable(): void
    {
        $service  = new McpToolsService([], $this->logger);
        $provider = $this->stubProvider('late', [$this->descriptor('late.do')], ['ran' => true]);
        $service->addProvider($provider);

        $result = $service->invokeTool('late.do', []);
        $this->assertFalse($result['isError']);
        $this->assertSame(['ran' => true], $result['result']);

    }//end testAddProviderToolBecomesCallable()
}//end class
