<?php

declare(strict_types=1);

/**
 * McpTool attribute construction tests (ADR-063 chain 3/3).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Attribute
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-001 — The #[McpTool] service-method attribute)
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces)
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp\Attribute;

use Attribute;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the McpTool attribute itself (construction only — schema
 * inference and discovery are exercised by AttributeToolScannerTest).
 */
class McpToolTest extends TestCase
{

    public function testDefaultsAreBothNull(): void
    {
        $attribute = new McpTool();

        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);

    }//end testDefaultsAreBothNull()


    public function testExplicitValuesAreRetained(): void
    {
        $attribute = new McpTool(name: 'createLead', description: 'Create a sales lead.');

        $this->assertSame('createLead', $attribute->name);
        $this->assertSame('Create a sales lead.', $attribute->description);

    }//end testExplicitValuesAreRetained()


    public function testHintAndScopeParamsDefaultToNull(): void
    {
        $attribute = new McpTool();

        $this->assertNull($attribute->readOnlyHint);
        $this->assertNull($attribute->destructiveHint);
        $this->assertNull($attribute->idempotentHint);
        $this->assertNull($attribute->scope);

    }//end testHintAndScopeParamsDefaultToNull()


    public function testHintAndScopeParamsAreIndependentlySettable(): void
    {
        $attribute = new McpTool(destructiveHint: true, scope: 'delete');

        $this->assertTrue($attribute->destructiveHint);
        $this->assertSame('delete', $attribute->scope);
        $this->assertNull($attribute->readOnlyHint);
        $this->assertNull($attribute->idempotentHint);

    }//end testHintAndScopeParamsAreIndependentlySettable()


    public function testAllFourNewParamsAreRetained(): void
    {
        $attribute = new McpTool(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            scope: 'create'
        );

        $this->assertFalse($attribute->readOnlyHint);
        $this->assertTrue($attribute->destructiveHint);
        $this->assertFalse($attribute->idempotentHint);
        $this->assertSame('create', $attribute->scope);

    }//end testAllFourNewParamsAreRetained()


    public function testAttributeTargetsMethodsOnly(): void
    {
        $reflection = new ReflectionClass(McpTool::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        $this->assertNotEmpty($attributes, 'McpTool must itself carry a native #[Attribute(...)] declaration.');

        $instance = $attributes[0]->newInstance();
        $this->assertSame(Attribute::TARGET_METHOD, $instance->flags);

    }//end testAttributeTargetsMethodsOnly()
}//end class
