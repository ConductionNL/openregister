<?php

declare(strict_types=1);

namespace Unit\Formats;

use OCA\OpenRegister\Formats\BsnFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BsnFormatTest extends TestCase
{
    private BsnFormat $bsnFormat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bsnFormat = new BsnFormat();
    }

    public static function validBsnProvider(): array
    {
        return [
            'standard 9-digit BSN' => ['111222333'],
            'BSN with leading zeros' => ['000000012'],
            'another valid BSN' => ['123456782'],
            'empty string pads to all zeros (valid checksum)' => [''],
            'short input padded to valid' => ['12'],
        ];
    }

    #[DataProvider('validBsnProvider')]
    public function testValidBsn(string $bsn): void
    {
        $this->assertTrue(
            $this->bsnFormat->validate($bsn),
            sprintf('BSN "%s" should be valid but was marked as invalid', $bsn)
        );
    }

    public static function invalidBsnProvider(): array
    {
        return [
            'wrong checksum' => ['123456789'],
            'non-numeric' => ['abcdefghi'],
            'mixed alphanumeric' => ['12345678a'],
            'single wrong digit' => ['1'],
            'all ones (invalid checksum)' => ['111111111'],
        ];
    }

    #[DataProvider('invalidBsnProvider')]
    public function testInvalidBsn(string $bsn): void
    {
        $this->assertFalse(
            $this->bsnFormat->validate($bsn),
            sprintf('BSN "%s" should be invalid but passed validation', $bsn)
        );
    }

    public function testNumericInputCoerced(): void
    {
        // PHP coerces int to string via str_pad — matches same BSN string
        $this->assertSame(
            $this->bsnFormat->validate('123456782'),
            $this->bsnFormat->validate(123456782)
        );
    }

    public function testNullCoercedToEmptyString(): void
    {
        // null coerced to "" by str_pad, padded to "000000000" — checksum 0
        $this->assertTrue($this->bsnFormat->validate(null));
    }

    public function testFalseCoercedToEmptyString(): void
    {
        // false coerced to "" by str_pad
        $this->assertTrue($this->bsnFormat->validate(false));
    }

    public function testArrayThrowsTypeError(): void
    {
        $this->expectException(\TypeError::class);
        $this->bsnFormat->validate(['111222333']);
    }

    public function testChecksumAlgorithm(): void
    {
        // Verify the weighted modulo-11 checksum:
        // BSN "111222333": 1*9 + 1*8 + 1*7 + 2*6 + 2*5 + 2*4 + 3*3 + 3*2 + 3*(-1)
        // = 9 + 8 + 7 + 12 + 10 + 8 + 9 + 6 - 3 = 66, 66 % 11 = 0 → valid
        $this->assertTrue($this->bsnFormat->validate('111222333'));
    }
}
