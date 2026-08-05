<?php

declare(strict_types=1);

/**
 * AttributeToolProvider Unit Tests (ADR-063 chain 3/3).
 *
 * Exercises catalog exposure (getTools()), in-process invocation (ADR-041 —
 * a direct method call on the already-resolved owning service instance, no
 * cross-app HTTP/RPC), the "no bypass" authorization contract, and one
 * immutable audit record per invocation (success + failure, digest not raw
 * params) — the same audit contract SchemaDerivedToolProviderTest exercises
 * for the derived provider.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-002 — Attributed method becomes a catalog tool on both surfaces)
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces)
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Mcp\AttributeToolScanner;
use OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\AttributeFixtureService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\HintScopeFixtureService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\ThrowingFixtureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__.'/../Fixtures/AttributeFixtureService.php';
require_once __DIR__.'/../Fixtures/HintScopeFixtureService.php';
require_once __DIR__.'/../Fixtures/ThrowingFixtureService.php';

/**
 * Unit tests for AttributeToolProvider.
 */
class AttributeToolProviderTest extends TestCase
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
     * Real descriptors from AttributeToolScanner, each augmented with a live
     * `instance` — exactly the shape Application::collectAttributeMcpProviders()
     * builds.
     *
     * @param object $instance The resolved owning service instance.
     * @param string $appId    The owning app id.
     *
     * @return list<array<string, mixed>>
     */
    private function entriesFor(object $instance, string $appId = 'pipelinq'): array
    {
        $scanner     = new AttributeToolScanner();
        $descriptors = $scanner->scanClass(appId: $appId, className: get_class($instance), logger: $this->logger);

        foreach ($descriptors as &$descriptor) {
            $descriptor['instance'] = $instance;
        }

        return $descriptors;

    }//end entriesFor()


    private function provider(array $entries, string $appId = 'pipelinq'): AttributeToolProvider
    {
        return new AttributeToolProvider(
            appId: $appId,
            entries: $entries,
            auditTrailMapper: $this->auditTrailMapper,
            logger: $this->logger
        );

    }//end provider()


    // ── getAppId / getTools ──────────────────────────────────────────


    public function testGetAppIdReturnsOwningApp(): void
    {
        $provider = $this->provider([]);
        $this->assertSame('pipelinq', $provider->getAppId());

    }//end testGetAppIdReturnsOwningApp()


    public function testGetToolsExposesCatalogFieldsOnly(): void
    {
        $entries  = $this->entriesFor(new AttributeFixtureService());
        $provider = $this->provider($entries);

        $tools = $provider->getTools();
        $ids   = array_column($tools, 'id');

        $this->assertContains('pipelinq.createLead', $ids);
        $this->assertContains('pipelinq.logContactmoment', $ids);
        $this->assertContains('pipelinq.computeScore', $ids);

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            // Invocation-only metadata must NOT leak onto the public catalog.
            $this->assertArrayNotHasKey('instance', $tool);
            $this->assertArrayNotHasKey('class', $tool);
            $this->assertArrayNotHasKey('method', $tool);
            $this->assertArrayNotHasKey('paramNames', $tool);
        }

    }//end testGetToolsExposesCatalogFieldsOnly()


    // ── getTools: hint/scope forwarding (REQ-ATTR-005) ───────────────


    public function testGetToolsForwardsDeclaredHintsAndScope(): void
    {
        $entries  = $this->entriesFor(new HintScopeFixtureService());
        $provider = $this->provider($entries);

        $tools = $provider->getTools();
        $byId  = [];
        foreach ($tools as $tool) {
            $byId[$tool['id']] = $tool;
        }

        $deleteLead = $byId['pipelinq.deleteLead'];
        $this->assertFalse($deleteLead['readOnlyHint']);
        $this->assertTrue($deleteLead['destructiveHint']);
        $this->assertFalse($deleteLead['idempotentHint']);
        $this->assertSame('delete', $deleteLead['scope']);

    }//end testGetToolsForwardsDeclaredHintsAndScope()


    public function testGetToolsOmitsHintsAndScopeWhenUndeclared(): void
    {
        $entries  = $this->entriesFor(new HintScopeFixtureService());
        $provider = $this->provider($entries);

        $tools = $provider->getTools();
        $byId  = [];
        foreach ($tools as $tool) {
            $byId[$tool['id']] = $tool;
        }

        $getLead = $byId['pipelinq.getLead'];
        $this->assertArrayNotHasKey('readOnlyHint', $getLead);
        $this->assertArrayNotHasKey('destructiveHint', $getLead);
        $this->assertArrayNotHasKey('idempotentHint', $getLead);
        $this->assertArrayNotHasKey('scope', $getLead);

    }//end testGetToolsOmitsHintsAndScopeWhenUndeclared()


    public function testGetToolsExposesNoHintScopeKeysForUnannotatedFixture(): void
    {
        // AttributeFixtureService declares none of the four new params on
        // any method — its descriptors must carry no phantom hint/scope keys.
        $entries  = $this->entriesFor(new AttributeFixtureService());
        $provider = $this->provider($entries);

        foreach ($provider->getTools() as $tool) {
            $this->assertArrayNotHasKey('readOnlyHint', $tool);
            $this->assertArrayNotHasKey('destructiveHint', $tool);
            $this->assertArrayNotHasKey('idempotentHint', $tool);
            $this->assertArrayNotHasKey('scope', $tool);
        }

    }//end testGetToolsExposesNoHintScopeKeysForUnannotatedFixture()


    // ── invokeTool: in-process dispatch (ADR-041) ────────────────────


    public function testInvokeUnknownToolIdThrows(): void
    {
        $provider = $this->provider([]);

        $this->auditTrailMapper->expects($this->never())->method('createToolInvocationEntry');

        $this->expectException(InvalidArgumentException::class);
        $provider->invokeTool('pipelinq.createLead', []);

    }//end testInvokeUnknownToolIdThrows()


    public function testInvocationCallsTheOwningInstanceInProcess(): void
    {
        $instance = new AttributeFixtureService();
        $provider = $this->provider($this->entriesFor($instance));

        $result = $provider->invokeTool('pipelinq.createLead', ['email' => 'a@example.com', 'company' => 'Acme']);

        $this->assertSame('lead-1', $result['id']);
        $this->assertSame('a@example.com', $result['email']);
        $this->assertSame('Acme', $result['company']);

    }//end testInvocationCallsTheOwningInstanceInProcess()

    public function testInvocationOmitsUndeclaredArgumentsBeforeCalling(): void
    {
        $instance = new AttributeFixtureService();
        $provider = $this->provider($this->entriesFor($instance));

        // `unexpectedExtraKey` is not a declared parameter of logContactmoment();
        // PHP would fatal on an unknown named argument if it were passed
        // through unfiltered.
        $result = $provider->invokeTool(
            'pipelinq.logContactmoment',
            ['subject' => 'Called back', 'unexpectedExtraKey' => 'x']
        );

        $this->assertSame('Called back', $result['subject']);

    }//end testInvocationOmitsUndeclaredArgumentsBeforeCalling()


    public function testScalarReturnIsCoercedIntoAnArrayResult(): void
    {
        $instance = new AttributeFixtureService();
        $provider = $this->provider($this->entriesFor($instance));

        $result = $provider->invokeTool('pipelinq.computeScore', ['leadId' => 21]);

        $this->assertSame(['result' => 42], $result);

    }//end testScalarReturnIsCoercedIntoAnArrayResult()


    // ── Authorization: no bypass (REQ-ATTR-003) ──────────────────────


    public function testOwningMethodAuthorizationFailurePropagatesWithNoBypass(): void
    {
        $instance = new ThrowingFixtureService();
        $provider = $this->provider($this->entriesFor($instance));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not authorized');

        $provider->invokeTool('pipelinq.privilegedAction', ['id' => 'x']);

    }//end testOwningMethodAuthorizationFailurePropagatesWithNoBypass()


    // ── Audit (REQ-ATTR-004) ──────────────────────────────────────────


    public function testSuccessfulInvocationWritesOneAuditRecordWithDigestNotRawParams(): void
    {
        $instance = new AttributeFixtureService();
        $provider = $this->provider($this->entriesFor($instance));

        $capturedDigest = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('createToolInvocationEntry')
            ->willReturnCallback(function (
                string $toolId,
                string $paramsDigest,
                array $resultSummary,
                ?int $register,
                ?int $schema,
                ?int $object,
                ?string $objectUuid
            ) use (&$capturedDigest) {
                $capturedDigest = $paramsDigest;
                $this->assertSame('pipelinq.createLead', $toolId);
                $this->assertFalse($resultSummary['isError']);
                $this->assertSame('lead-1', $objectUuid);
                return $this->createMock(AuditTrail::class);
            });

        $provider->invokeTool('pipelinq.createLead', ['email' => 'a@example.com']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $capturedDigest);

    }//end testSuccessfulInvocationWritesOneAuditRecordWithDigestNotRawParams()


    public function testFailedInvocationIsStillAuditedWithErrorSummary(): void
    {
        $instance = new ThrowingFixtureService();
        $provider = $this->provider($this->entriesFor($instance));

        $this->auditTrailMapper->expects($this->once())
            ->method('createToolInvocationEntry')
            ->with(
                'pipelinq.privilegedAction',
                $this->anything(),
                $this->callback(function ($summary) {
                    return $summary['isError'] === true && $summary['errorClass'] === RuntimeException::class;
                }),
                null,
                null,
                null,
                null
            )
            ->willReturn($this->createMock(AuditTrail::class));

        try {
            $provider->invokeTool('pipelinq.privilegedAction', ['id' => 'x']);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Not authorized', $e->getMessage());
        }

    }//end testFailedInvocationIsStillAuditedWithErrorSummary()


    public function testAuditFailureDoesNotMaskSuccessfulResult(): void
    {
        $instance = new AttributeFixtureService();
        $provider = $this->provider($this->entriesFor($instance));

        $this->auditTrailMapper->method('createToolInvocationEntry')->willThrowException(new RuntimeException('db down'));

        $result = $provider->invokeTool('pipelinq.logContactmoment', ['subject' => 'x']);

        $this->assertSame(['subject' => 'x'], $result);

    }//end testAuditFailureDoesNotMaskSuccessfulResult()
}//end class
