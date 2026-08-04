<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Formats;

use OCA\OpenRegister\Formats\Iso8601DateTimeFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Iso8601DateTimeFormat
 *
 * @category Tests
 * @package  OpenRegister
 * @author   Conduction AI <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://www.conduction.nl
 */
class Iso8601DateTimeFormatTest extends TestCase
{

    /**
     * The Iso8601DateTimeFormat instance to test
     *
     * @var Iso8601DateTimeFormat
     */
    private Iso8601DateTimeFormat $format;


    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->format = new Iso8601DateTimeFormat();

    }//end setUp()


    /**
     * Test valid ISO 8601 date-times (optional seconds/timezone)
     *
     * @return void
     */
    public function testValidIso8601DateTimes(): void
    {
        $validValues = [
            '2026-06-18T10:14',
            '2026-06-18T10:14:00',
            '2026-06-18T10:14:00Z',
            '2026-06-18T10:14:00+02:00',
            '2026-06-18T10:14:00.123Z',
            '2026-06-18T10:14Z',
            '2026-06-18T10:14-05:00',
            '2024-02-29T00:00:00Z',
        ];

        foreach ($validValues as $value) {
            $isValid = $this->format->validate($value);
            $this->assertTrue(
                $isValid,
                sprintf('Value "%s" should be valid but was marked as invalid', $value)
            );
        }

    }//end testValidIso8601DateTimes()


    /**
     * Test invalid ISO 8601 date-times
     *
     * @return void
     */
    public function testInvalidIso8601DateTimes(): void
    {
        $invalidValues = [
            '',
            'not-a-date',
            'tomorrow',
            '2026-13-01T10:14',
            '2026-00-10T10:14',
            '2026-06-18',
            '2026-02-30T10:14',
            '2026-06-18 10:14',
            '2026-06-18T24:00',
            '2026-06-18T10:60',
        ];

        foreach ($invalidValues as $value) {
            $isValid = $this->format->validate($value);
            $this->assertFalse(
                $isValid,
                sprintf('Value "%s" should be invalid but passed validation', $value)
            );
        }

    }//end testInvalidIso8601DateTimes()


    /**
     * Test non-string values
     *
     * @return void
     */
    public function testNonStringValues(): void
    {
        $nonStringValues = [
            123,
            12.3,
            true,
            false,
            null,
            [],
            ['2026-06-18T10:14'],
            (object) ['date' => '2026-06-18T10:14'],
        ];

        foreach ($nonStringValues as $value) {
            $isValid = $this->format->validate($value);
            $this->assertFalse(
                $isValid,
                sprintf('Non-string value should be invalid but passed validation: %s', json_encode($value))
            );
        }

    }//end testNonStringValues()


}//end class
