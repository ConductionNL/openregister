<?php

declare(strict_types=1);

namespace Unit\Formats;

use OCA\OpenRegister\Formats\UuidFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UuidFormatTest extends TestCase
{
    private UuidFormat $uuidFormat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uuidFormat = new UuidFormat();
    }

    public static function canonicalProvider(): array
    {
        return [
            'lowercase' => ['550e8400-e29b-41d4-a716-446655440000'],
            'uppercase' => ['550E8400-E29B-41D4-A716-446655440000'],
            'mixed case' => ['550e8400-E29B-41d4-A716-446655440000'],
        ];
    }

    #[DataProvider('canonicalProvider')]
    public function testCanonicalValidates(string $value): void
    {
        $this->assertTrue($this->uuidFormat->validate($value));
        $this->assertTrue(UuidFormat::isCanonical($value));
        $this->assertTrue(UuidFormat::isAny($value));
    }

    public static function invalidCanonicalProvider(): array
    {
        return [
            'too short' => ['550e8400-e29b-41d4-a716-44665544000'],
            'too long' => ['550e8400-e29b-41d4-a716-4466554400000'],
            'non-hex' => ['550e8400-e29b-41d4-a716-44665544000g'],
            'no hyphens' => ['550e8400e29b41d4a716446655440000'],
            'empty' => [''],
            'plain word' => ['not-a-uuid'],
        ];
    }

    #[DataProvider('invalidCanonicalProvider')]
    public function testInvalidCanonicalRejected(string $value): void
    {
        $this->assertFalse($this->uuidFormat->validate($value));
    }

    public function testNonStringRejected(): void
    {
        $this->assertFalse($this->uuidFormat->validate(12345));
        $this->assertFalse($this->uuidFormat->validate(null));
        $this->assertFalse($this->uuidFormat->validate(['x']));
    }

    public function testPrefixedShape(): void
    {
        $prefixed = 'foo-550e8400-e29b-41d4-a716-446655440000';
        $this->assertTrue(UuidFormat::isPrefixed($prefixed));
        $this->assertTrue(UuidFormat::isAny($prefixed));
        // A prefixed value is NOT canonical.
        $this->assertFalse(UuidFormat::isCanonical($prefixed));
    }

    public function testHex32Shape(): void
    {
        $hex = '550e8400e29b41d4a716446655440000';
        $this->assertTrue(UuidFormat::isHex32($hex));
        $this->assertTrue(UuidFormat::isAny($hex));
        $this->assertFalse(UuidFormat::isCanonical($hex));
    }

    public function testIsAnyRejectsGarbage(): void
    {
        $this->assertFalse(UuidFormat::isAny('definitely not a uuid'));
        $this->assertFalse(UuidFormat::isAny(''));
    }
}
