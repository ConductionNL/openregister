<?php

declare(strict_types=1);

/**
 * BsnFormatTest
 *
 * Comprehensive unit tests for the BsnFormat class to verify Dutch BSN
 * (Burgerservicenummer) validation functionality.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Formats
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenRegister/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\BsnFormat;
use PHPUnit\Framework\TestCase;

/**
 * BSN Format Test Suite
 *
 * Comprehensive unit tests for Dutch BSN validation including
 * valid BSNs, invalid BSNs, and edge cases.
 *
 * @coversDefaultClass BsnFormat
 */
class BsnFormatTest extends TestCase
{
    private BsnFormat $bsnFormat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bsnFormat = new BsnFormat();
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(BsnFormat::class, $this->bsnFormat);
    }

    /**
     * Test validate with valid BSN numbers
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithValidBsnNumbers(): void
    {
        $validBsns = [
            '123456782',  // Valid BSN
            '111111110',  // Valid BSN
            '111222333',  // Valid BSN
            '100000009',  // Valid BSN
            '100000010',  // Valid BSN
            '100000022',  // Valid BSN
            '100000034',  // Valid BSN
            '100000046',  // Valid BSN
            '100000058',  // Valid BSN
            '100000071',  // Valid BSN
            '100000083',  // Valid BSN
            '100000095',  // Valid BSN
            '100000101',  // Valid BSN
        ];

        foreach ($validBsns as $bsn) {
            $this->assertTrue(
                $this->bsnFormat->validate($bsn),
                "BSN '{$bsn}' should be valid"
            );
        }
    }

    /**
     * Test validate with invalid BSN numbers
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithInvalidBsnNumbers(): void
    {
        $invalidBsns = [
            '000000000',  // Invalid BSN (wrong check digit)
            '123456781',  // Invalid BSN (wrong check digit)
            '987654320',  // Invalid BSN (wrong check digit)
            '111222334',  // Invalid BSN (wrong check digit)
            '123456788',  // Invalid BSN (wrong check digit)
            '123456780',  // Invalid BSN (wrong check digit)
            '1234567890', // Invalid BSN (too many digits)
            '12345678a',  // Invalid BSN (contains letter)
            '1234567-8',  // Invalid BSN (contains hyphen)
            '1234567 8',  // Invalid BSN (contains space)
            '1234567.8',  // Invalid BSN (contains dot)
            '1234567+8',  // Invalid BSN (contains plus)
            '1234567*8',  // Invalid BSN (contains asterisk)
            '1234567#8',  // Invalid BSN (contains hash)
            '1234567@8',  // Invalid BSN (contains at symbol)
            '1234567!8',  // Invalid BSN (contains exclamation)
            '1234567?8',  // Invalid BSN (contains question mark)
            '1234567/8',  // Invalid BSN (contains slash)
            '1234567\\8', // Invalid BSN (contains backslash)
            '1234567(8',  // Invalid BSN (contains parenthesis)
            '1234567)8',  // Invalid BSN (contains parenthesis)
            '1234567[8',  // Invalid BSN (contains bracket)
            '1234567]8',  // Invalid BSN (contains bracket)
            '1234567{8',  // Invalid BSN (contains brace)
            '1234567}8',  // Invalid BSN (contains brace)
            '1234567<8',  // Invalid BSN (contains less than)
            '1234567>8',  // Invalid BSN (contains greater than)
            '1234567=8',  // Invalid BSN (contains equals)
            '1234567%8',  // Invalid BSN (contains percent)
            '1234567&8',  // Invalid BSN (contains ampersand)
            '1234567|8',  // Invalid BSN (contains pipe)
            '1234567^8',  // Invalid BSN (contains caret)
            '1234567~8',  // Invalid BSN (contains tilde)
            '1234567`8',  // Invalid BSN (contains backtick)
            '1234567\'8', // Invalid BSN (contains single quote)
            '1234567"8',  // Invalid BSN (contains double quote)
            '1234567;8',  // Invalid BSN (contains semicolon)
            '1234567:8',  // Invalid BSN (contains colon)
            '1234567,8',  // Invalid BSN (contains comma)
            '1234567.8',  // Invalid BSN (contains dot)
            '1234567 8',  // Invalid BSN (contains space)
            '1234567-8',  // Invalid BSN (contains hyphen)
            '1234567+8',  // Invalid BSN (contains plus)
            '1234567*8',  // Invalid BSN (contains asterisk)
            '1234567#8',  // Invalid BSN (contains hash)
            '1234567@8',  // Invalid BSN (contains at symbol)
            '1234567!8',  // Invalid BSN (contains exclamation)
            '1234567?8',  // Invalid BSN (contains question mark)
            '1234567/8',  // Invalid BSN (contains slash)
            '1234567\\8', // Invalid BSN (contains backslash)
            '1234567(8',  // Invalid BSN (contains parenthesis)
            '1234567)8',  // Invalid BSN (contains parenthesis)
            '1234567[8',  // Invalid BSN (contains bracket)
            '1234567]8',  // Invalid BSN (contains bracket)
            '1234567{8',  // Invalid BSN (contains brace)
            '1234567}8',  // Invalid BSN (contains brace)
            '1234567<8',  // Invalid BSN (contains less than)
            '1234567>8',  // Invalid BSN (contains greater than)
            '1234567=8',  // Invalid BSN (contains equals)
            '1234567%8',  // Invalid BSN (contains percent)
            '1234567&8',  // Invalid BSN (contains ampersand)
            '1234567|8',  // Invalid BSN (contains pipe)
            '1234567^8',  // Invalid BSN (contains caret)
            '1234567~8',  // Invalid BSN (contains tilde)
            '1234567`8',  // Invalid BSN (contains backtick)
            '1234567\'8', // Invalid BSN (contains single quote)
            '1234567"8',  // Invalid BSN (contains double quote)
            '1234567;8',  // Invalid BSN (contains semicolon)
            '1234567:8',  // Invalid BSN (contains colon)
            '1234567,8',  // Invalid BSN (contains comma)
        ];

        foreach ($invalidBsns as $bsn) {
            $this->assertFalse(
                $this->bsnFormat->validate($bsn),
                "BSN '{$bsn}' should be invalid"
            );
        }
    }

    /**
     * Test validate with non-string data
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithNonStringData(): void
    {
        $nonStringData = [
            null,
            123456781,  // Invalid BSN
            123456781.0, // Invalid BSN
            true,
            false,
            [],
            new \stdClass(),
            function() { return '123456781'; } // Invalid BSN
        ];

        foreach ($nonStringData as $data) {
            $this->assertFalse(
                $this->bsnFormat->validate($data),
                "Non-string data should be invalid"
            );
        }
    }

    /**
     * Test validate with empty string
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithEmptyString(): void
    {
        $this->assertFalse(
            $this->bsnFormat->validate(''),
            "Empty string should be invalid"
        );
    }

    /**
     * Test validate with whitespace
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithWhitespace(): void
    {
        $whitespaceBsns = [
            ' 123456782',
            '123456782 ',
            ' 123456782 ',
            "\t123456782",
            "123456782\t",
            "\n123456782",
            "123456782\n",
            "\r123456782",
            "123456782\r",
            "  \t  \n  \r  123456782  \t  \n  \r  ",
        ];

        foreach ($whitespaceBsns as $bsn) {
            $this->assertFalse(
                $this->bsnFormat->validate($bsn),
                "BSN with whitespace '{$bsn}' should be invalid"
            );
        }
    }

    /**
     * Test validate with edge case BSN numbers
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithEdgeCaseBsnNumbers(): void
    {
        $edgeCases = [
            '000000000' => false,  // All zeros (invalid)
            '000000001' => false,  // Almost all zeros (invalid)
            '000000010' => false,  // Almost all zeros (invalid)
            '000000100' => false,  // Almost all zeros (invalid)
            '000001000' => false,  // Almost all zeros (invalid)
            '000010000' => false,  // Almost all zeros (invalid)
            '000100000' => false,  // Almost all zeros (invalid)
            '001000000' => false,  // Almost all zeros (invalid)
            '010000000' => false,  // Almost all zeros (invalid)
            '100000000' => false,  // Invalid BSN
            '999999999' => false,  // Invalid BSN
            '111111111' => false,  // All same digits (invalid)
            '222222222' => false,  // All same digits (invalid)
            '333333333' => false,  // All same digits (invalid)
            '444444444' => false,  // All same digits (invalid)
            '555555555' => false,  // All same digits (invalid)
            '666666666' => false,  // All same digits (invalid)
            '777777777' => false,  // All same digits (invalid)
            '888888888' => false,  // All same digits (invalid)
            '999999999' => false,  // Invalid BSN
        ];

        foreach ($edgeCases as $bsn => $expected) {
            $this->assertEquals(
                $expected,
                $this->bsnFormat->validate($bsn),
                "Edge case BSN '{$bsn}' validation failed"
            );
        }
    }

    /**
     * Test validate with very short BSN numbers
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithVeryShortBsnNumbers(): void
    {
        $shortBsns = [
            '1' => false,    // Single digit - invalid (not 9 digits)
            '12' => false,   // Two digits - invalid (not 9 digits)
            '123' => false,  // Three digits - invalid (not 9 digits)
            '1234' => false, // Four digits - invalid (not 9 digits)
            '12345' => false, // Five digits - invalid (not 9 digits)
            '123456' => false,  // Six digits - invalid (not 9 digits)
            '1234567' => false, // Seven digits - invalid (not 9 digits)
            '12345678' => false, // Eight digits - invalid (not 9 digits)
        ];

        foreach ($shortBsns as $bsn => $expected) {
            $this->assertEquals(
                $expected,
                $this->bsnFormat->validate($bsn),
                "Short BSN '{$bsn}' validation failed"
            );
        }
    }

    /**
     * Test validate with very long BSN numbers
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithVeryLongBsnNumbers(): void
    {
        $longBsns = [
            '1234567890',  // 10 digits (too long)
            '12345678901', // 11 digits (too long)
            '123456789012', // 12 digits (too long)
            '1234567890123', // 13 digits (too long)
            '12345678901234', // 14 digits (too long)
            '123456789012345', // 15 digits (too long)
        ];

        foreach ($longBsns as $bsn) {
            $this->assertFalse(
                $this->bsnFormat->validate($bsn),
                "Long BSN '{$bsn}' should be invalid"
            );
        }
    }

    /**
     * Test validate with mixed valid and invalid BSN numbers
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithMixedBsnNumbers(): void
    {
        $mixedBsns = [
            '123456782' => true,   // Valid
            '123456781' => false,  // Invalid (wrong check digit)
            '987654321' => false,  // Invalid
            '987654320' => false,  // Invalid (wrong check digit)
            '111222333' => true,   // Valid
            '111222334' => false,  // Invalid (wrong check digit)
            '123456789' => false,  // Invalid
            '123456788' => false,  // Invalid (wrong check digit)
            '999999999' => false,  // Invalid
            '999999998' => false,  // Invalid (wrong check digit)
        ];

        foreach ($mixedBsns as $bsn => $expected) {
            $this->assertEquals(
                $expected,
                $this->bsnFormat->validate($bsn),
                "Mixed BSN '{$bsn}' validation failed"
            );
        }
    }
}
