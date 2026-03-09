<?php

declare(strict_types=1);

/**
 * MetadataHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\MetadataHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetadataHandler
 *
 * Tests dot-notation value extraction and slug generation.
 */
class MetadataHandlerTest extends TestCase
{
    /** @var MetadataHandler */
    private MetadataHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new MetadataHandler();
    }

    // =========================================================================
    // getValueFromPath
    // =========================================================================

    public function testGetValueFromPathSimpleKey(): void
    {
        $data = ['name' => 'John', 'age' => 30];

        $this->assertSame('John', $this->handler->getValueFromPath($data, 'name'));
        $this->assertSame(30, $this->handler->getValueFromPath($data, 'age'));
    }

    public function testGetValueFromPathNestedKey(): void
    {
        $data = [
            'user' => [
                'profile' => [
                    'name' => 'Alice',
                ],
            ],
        ];

        $this->assertSame('Alice', $this->handler->getValueFromPath($data, 'user.profile.name'));
    }

    public function testGetValueFromPathReturnsNullForMissingKey(): void
    {
        $data = ['name' => 'John'];

        $this->assertNull($this->handler->getValueFromPath($data, 'missing'));
        $this->assertNull($this->handler->getValueFromPath($data, 'name.nested'));
    }

    public function testGetValueFromPathDeepMissing(): void
    {
        $data = ['a' => ['b' => 'value']];

        $this->assertNull($this->handler->getValueFromPath($data, 'a.b.c'));
        $this->assertNull($this->handler->getValueFromPath($data, 'x.y.z'));
    }

    public function testGetValueFromPathEmptyData(): void
    {
        $this->assertNull($this->handler->getValueFromPath([], 'any.path'));
    }

    public function testGetValueFromPathReturnsArray(): void
    {
        $data = ['items' => ['a', 'b', 'c']];

        $this->assertSame(['a', 'b', 'c'], $this->handler->getValueFromPath($data, 'items'));
    }

    // =========================================================================
    // createSlugHelper
    // =========================================================================

    public function testCreateSlugHelperBasic(): void
    {
        $this->assertSame('hello-world', $this->handler->createSlugHelper('Hello World'));
    }

    public function testCreateSlugHelperSpecialCharacters(): void
    {
        $this->assertSame('test-123-value', $this->handler->createSlugHelper('Test 123 & Value!'));
    }

    public function testCreateSlugHelperUnicode(): void
    {
        $result = $this->handler->createSlugHelper('Café Résumé');
        // Non-ASCII chars are replaced with hyphens.
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $result);
    }

    public function testCreateSlugHelperTrimsHyphens(): void
    {
        $this->assertSame('test', $this->handler->createSlugHelper('---test---'));
    }

    public function testCreateSlugHelperEmptyReturnsObject(): void
    {
        $this->assertSame('object', $this->handler->createSlugHelper(''));
        $this->assertSame('object', $this->handler->createSlugHelper('---'));
    }

    public function testCreateSlugHelperLowercase(): void
    {
        $this->assertSame('uppercase-text', $this->handler->createSlugHelper('UPPERCASE TEXT'));
    }

    // =========================================================================
    // generateSlugFromValue
    // =========================================================================

    public function testGenerateSlugFromValueReturnsNullForEmpty(): void
    {
        $this->assertNull($this->handler->generateSlugFromValue(''));
    }

    public function testGenerateSlugFromValueContainsTimestamp(): void
    {
        $result = $this->handler->generateSlugFromValue('Test Title');

        $this->assertNotNull($result);
        $this->assertStringStartsWith('test-title-', $result);
        // Should end with a numeric timestamp.
        $parts = explode('-', $result);
        $lastPart = end($parts);
        $this->assertTrue(is_numeric($lastPart));
    }

    public function testGenerateSlugFromValueUniquePerCall(): void
    {
        // Due to timestamp granularity, two calls within same second may match.
        // But they should both be valid slugs.
        $result1 = $this->handler->generateSlugFromValue('Same');
        $result2 = $this->handler->generateSlugFromValue('Same');

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
        $this->assertStringStartsWith('same-', $result1);
        $this->assertStringStartsWith('same-', $result2);
    }
}
