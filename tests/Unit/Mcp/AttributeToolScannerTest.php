<?php

declare(strict_types=1);

/**
 * AttributeToolScanner Unit Tests (ADR-063 chain 3/3).
 *
 * Exercises attribute-default resolution (name → method name, description →
 * docblock summary), inputSchema inference (required vs optional/nullable
 * params, docblock @param descriptions), best-effort outputSchema
 * inference, and non-public-method rejection with a logged warning.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-001 — The #[McpTool] service-method attribute)
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces)
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\AttributeToolScanner;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\AttributeFixtureService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\HintScopeFixtureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/Fixtures/AttributeFixtureService.php';
require_once __DIR__.'/Fixtures/HintScopeFixtureService.php';

/**
 * Unit tests for AttributeToolScanner.
 */
class AttributeToolScannerTest extends TestCase
{

    /** @var LoggerInterface&MockObject */
    private $logger;

    private AttributeToolScanner $scanner;


    protected function setUp(): void
    {
        parent::setUp();
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->scanner = new AttributeToolScanner();

    }//end setUp()


    /**
     * @return array<string, array<string, mixed>> descriptor keyed by `name`
     */
    private function scanFixture(): array
    {
        $descriptors = $this->scanner->scanClass(
            appId: 'pipelinq',
            className: AttributeFixtureService::class,
            logger: $this->logger
        );

        $byName = [];
        foreach ($descriptors as $descriptor) {
            $byName[$descriptor['name']] = $descriptor;
        }

        return $byName;

    }//end scanFixture()


    // ── Discovery + id namespacing ───────────────────────────────────


    public function testDiscoversOnlyPublicAttributedMethods(): void
    {
        $descriptors = $this->scanFixture();

        $this->assertArrayHasKey('createLead', $descriptors);
        $this->assertArrayHasKey('logContactmoment', $descriptors);
        $this->assertArrayHasKey('computeScore', $descriptors);
        $this->assertArrayNotHasKey('internalOnly', $descriptors);
        $this->assertCount(3, $descriptors);

    }//end testDiscoversOnlyPublicAttributedMethods()


    public function testBuildsNamespacedIdFromAppIdAndName(): void
    {
        $descriptors = $this->scanFixture();

        $this->assertSame('pipelinq.createLead', $descriptors['createLead']['id']);
        $this->assertSame('pipelinq.logContactmoment', $descriptors['logContactmoment']['id']);

    }//end testBuildsNamespacedIdFromAppIdAndName()


    public function testNonPublicAttributedMethodLogsWarning(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('non-public'));

        $this->scanFixture();

    }//end testNonPublicAttributedMethodLogsWarning()


    // ── Attribute defaults (REQ-ATTR-001) ────────────────────────────


    public function testExplicitNameAndDescriptionAreUsed(): void
    {
        $descriptors = $this->scanFixture();

        $this->assertSame('createLead', $descriptors['createLead']['name']);
        $this->assertSame('Create a sales lead from a contact moment.', $descriptors['createLead']['description']);

    }//end testExplicitNameAndDescriptionAreUsed()


    public function testDefaultsNameToMethodNameAndDescriptionToDocblockSummary(): void
    {
        $descriptors = $this->scanFixture();

        $this->assertSame('logContactmoment', $descriptors['logContactmoment']['name']);
        $this->assertSame(
            'Log a contact moment against a lead.',
            $descriptors['logContactmoment']['description']
        );

    }//end testDefaultsNameToMethodNameAndDescriptionToDocblockSummary()


    // ── inputSchema inference ────────────────────────────────────────


    public function testRequiredParamHasNoDefaultAndIsMarkedRequired(): void
    {
        $descriptors = $this->scanFixture();
        $inputSchema = $descriptors['createLead']['inputSchema'];

        $this->assertSame('object', $inputSchema['type']);
        $this->assertContains('email', $inputSchema['required']);
        $this->assertSame('string', $inputSchema['properties']['email']['type']);

    }//end testRequiredParamHasNoDefaultAndIsMarkedRequired()


    public function testOptionalNullableAndDefaultedParamsAreNotRequired(): void
    {
        $descriptors = $this->scanFixture();
        $inputSchema = $descriptors['createLead']['inputSchema'];

        $this->assertNotContains('company', $inputSchema['required']);
        $this->assertNotContains('score', $inputSchema['required']);
        $this->assertSame(['string', 'null'], $inputSchema['properties']['company']['type']);
        $this->assertSame('integer', $inputSchema['properties']['score']['type']);

    }//end testOptionalNullableAndDefaultedParamsAreNotRequired()


    public function testParamDocblockDescriptionsArePropagated(): void
    {
        $descriptors = $this->scanFixture();
        $inputSchema = $descriptors['createLead']['inputSchema'];

        $this->assertSame("The contact's email address.", $inputSchema['properties']['email']['description']);
        $this->assertSame('Optional company name.', $inputSchema['properties']['company']['description']);

    }//end testParamDocblockDescriptionsArePropagated()


    public function testSingleParamMinimalMethodInfersRequiredString(): void
    {
        $descriptors = $this->scanFixture();
        $inputSchema = $descriptors['logContactmoment']['inputSchema'];

        $this->assertSame(['subject'], $inputSchema['required']);
        $this->assertSame('string', $inputSchema['properties']['subject']['type']);

    }//end testSingleParamMinimalMethodInfersRequiredString()


    // ── outputSchema inference (best-effort) ─────────────────────────


    public function testUntypedArrayReturnOmitsOutputSchema(): void
    {
        $descriptors = $this->scanFixture();

        $this->assertArrayNotHasKey('outputSchema', $descriptors['createLead']);
        $this->assertArrayNotHasKey('outputSchema', $descriptors['logContactmoment']);

    }//end testUntypedArrayReturnOmitsOutputSchema()


    public function testScalarReturnTypeInfersOutputSchema(): void
    {
        $descriptors = $this->scanFixture();

        $this->assertArrayHasKey('outputSchema', $descriptors['computeScore']);
        $this->assertSame('integer', $descriptors['computeScore']['outputSchema']['type']);

    }//end testScalarReturnTypeInfersOutputSchema()


    // ── Invocation metadata (consumed by AttributeToolProvider) ──────


    public function testDescriptorCarriesClassMethodAndParamNamesForInvocation(): void
    {
        $descriptors = $this->scanFixture();

        $this->assertSame(AttributeFixtureService::class, $descriptors['createLead']['class']);
        $this->assertSame('createLead', $descriptors['createLead']['method']);
        $this->assertSame(['email', 'company', 'score'], $descriptors['createLead']['paramNames']);

    }//end testDescriptorCarriesClassMethodAndParamNamesForInvocation()


    // ── Robustness ────────────────────────────────────────────────────


    public function testUnknownClassLogsWarningAndReturnsEmpty(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('does not exist'));

        $descriptors = $this->scanner->scanClass(
            appId: 'pipelinq',
            className: 'OCA\\Pipelinq\\Service\\DoesNotExist',
            logger: $this->logger
        );

        $this->assertSame([], $descriptors);

    }//end testUnknownClassLogsWarningAndReturnsEmpty()


    public function testScanClassesAggregatesAcrossMultipleClassesAndSkipsInvalidNames(): void
    {
        $descriptors = $this->scanner->scanClasses(
            appId: 'pipelinq',
            classNames: [AttributeFixtureService::class, '', 'Not\\A\\Real\\Class'],
            logger: $this->logger
        );

        $names = array_column($descriptors, 'name');
        $this->assertContains('createLead', $names);
        $this->assertContains('computeScore', $names);

    }//end testScanClassesAggregatesAcrossMultipleClassesAndSkipsInvalidNames()


    // ── Hint/scope forwarding (REQ-ATTR-005) ──────────────────────────


    /**
     * @return array<string, array<string, mixed>> descriptor keyed by `name`
     */
    private function scanHintScopeFixture(): array
    {
        $descriptors = $this->scanner->scanClass(
            appId: 'pipelinq',
            className: HintScopeFixtureService::class,
            logger: $this->logger
        );

        $byName = [];
        foreach ($descriptors as $descriptor) {
            $byName[$descriptor['name']] = $descriptor;
        }

        return $byName;

    }//end scanHintScopeFixture()


    public function testDescriptorForwardsDeclaredHintsAndScope(): void
    {
        $descriptors = $this->scanHintScopeFixture();
        $descriptor  = $descriptors['deleteLead'];

        $this->assertFalse($descriptor['readOnlyHint']);
        $this->assertTrue($descriptor['destructiveHint']);
        $this->assertFalse($descriptor['idempotentHint']);
        $this->assertSame('delete', $descriptor['scope']);

    }//end testDescriptorForwardsDeclaredHintsAndScope()


    public function testUnannotatedHintsAndScopeStayOmittedNeverDefaulted(): void
    {
        $descriptors = $this->scanHintScopeFixture();
        $descriptor  = $descriptors['getLead'];

        $this->assertArrayNotHasKey('readOnlyHint', $descriptor);
        $this->assertArrayNotHasKey('destructiveHint', $descriptor);
        $this->assertArrayNotHasKey('idempotentHint', $descriptor);
        $this->assertArrayNotHasKey('scope', $descriptor);

    }//end testUnannotatedHintsAndScopeStayOmittedNeverDefaulted()


    public function testUnknownScopeIsRejectedAndLogged(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('unrecognised `scope`'));

        $descriptors = $this->scanHintScopeFixture();

        $this->assertArrayNotHasKey('badScopeLead', $descriptors);

    }//end testUnknownScopeIsRejectedAndLogged()


    public function testUnknownScopeDoesNotSuppressSiblingValidTools(): void
    {
        $descriptors = $this->scanHintScopeFixture();

        $this->assertArrayHasKey('deleteLead', $descriptors);
        $this->assertArrayHasKey('getLead', $descriptors);

    }//end testUnknownScopeDoesNotSuppressSiblingValidTools()
}//end class
