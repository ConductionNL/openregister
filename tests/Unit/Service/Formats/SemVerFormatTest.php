<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Formats;

use OCA\OpenRegister\Formats\SemVerFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SemVerFormat
 *
 * @category Tests
 * @package  OpenRegister
 * @author   Conduction AI <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://www.conduction.nl
 */
class SemVerFormatTest extends TestCase
{

    /**
     * The SemVerFormat instance to test
     *
     * @var SemVerFormat
     */
    private SemVerFormat $semVerFormat;


    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->semVerFormat = new SemVerFormat();

    }//end setUp()


    /**
     * Test valid semantic versions
     *
     * @return void
     */
    public function testValidSemVerVersions(): void
    {
        $validVersions = [
            '1.0.0',
            '0.0.1',
            '10.20.30',
            '1.1.2-prerelease+meta',
            '1.1.2+meta',
            '1.1.2+meta-valid',
            '1.0.0-alpha',
            '1.0.0-beta',
            '1.0.0-alpha.beta',
            '1.0.0-alpha.1',
            '1.0.0-alpha0.valid',
            '1.0.0-alpha.0valid',
            '1.0.0-rc.1+meta',
            '2.0.0-rc.1+meta',
            '1.2.3-beta',
            '10.2.3-DEV-SNAPSHOT',
            '1.2.3-SNAPSHOT-123',
            '1.0.0',
            '2.0.0',
            '1.1.7',
            '2.0.0+build.1',
            '2.0.0-beta+build.1',
            '1.0.0+0.build.1-rc.10000aaa-kk-0.1',
        ];

        foreach ($validVersions as $version) {
            $isValid = $this->semVerFormat->validate($version);
            $this->assertTrue(
                $isValid,
                sprintf('Version "%s" should be valid but was marked as invalid', $version)
            );
        }

    }//end testValidSemVerVersions()


    /**
     * Test invalid semantic versions
     *
     * @return void
     */
    public function testInvalidSemVerVersions(): void
    {
        $invalidVersions = [
            '',
            '1',
            '1.2',
            '1.2.3.DEV',
            '1.2-SNAPSHOT',
            '1.2.31.2.3----RC-SNAPSHOT.12.09.1--..12+788',
            '1.2-RC-SNAPSHOT',
            '1.0.0+',
            '+invalid',
            '-invalid',
            '-invalid+invalid',
            'alpha',
            'alpha.beta',
            'alpha.beta.1',
            'alpha.1',
            'alpha0.valid',
            '1.0.0-alpha_beta',
            '1.0.0-alpha..',
            '1.0.0-alpha..1',
            '1.0.0-alpha...1',
            '1.0.0-alpha....1',
            '1.0.0-alpha.....1',
            '1.0.0-alpha......1',
            '1.0.0-alpha.......1',
            '01.1.1',
            '1.01.1',
            '1.1.01',
            '1.2.3.DEV.SNAPSHOT',
            '1.2-SNAPSHOT-123',
            '1.0.0-',
            '1.2.3----RC-SNAPSHOT.12.9.1--.12+788+',
            '1.2.3----RC-SNAPSHOT.12.9.1--.12++',
            '1.2.3----RC-SNAPSHOT.12.9.1--.12+',
            '1.0.0++',
            '1.0.0-α',
        ];

        foreach ($invalidVersions as $version) {
            $isValid = $this->semVerFormat->validate($version);
            $this->assertFalse(
                $isValid,
                sprintf('Version "%s" should be invalid but passed validation', $version)
            );
        }

    }//end testInvalidSemVerVersions()


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
            ['1.0.0'],
            (object) ['version' => '1.0.0'],
        ];

        foreach ($nonStringValues as $value) {
            $isValid = $this->semVerFormat->validate($value);
            $this->assertFalse(
                $isValid,
                sprintf('Non-string value should be invalid but passed validation: %s', json_encode($value))
            );
        }

    }//end testNonStringValues()


    /**
     * Test specific edge cases for semantic versioning
     *
     * @return void
     */
    public function testSemVerEdgeCases(): void
    {
        // Test edge cases that should be invalid.
        $edgeCases = [
            '1.0.0-',        // Trailing dash
            '1.0.0+',        // Trailing plus
            '01.0.0',        // Leading zero in major
            '1.01.0',        // Leading zero in minor
            '1.0.01',        // Leading zero in patch
        ];

        foreach ($edgeCases as $version) {
            $isValid = $this->semVerFormat->validate($version);
            $this->assertFalse(
                $isValid,
                sprintf('Edge case version "%s" should be invalid but passed validation', $version)
            );
        }

        // Test edge cases that should be valid.
        $validEdgeCases = [
            '0.0.0',         // All zeros
            '999.999.999',   // Large numbers
            '1.0.0-0',       // Zero prerelease
        ];

        foreach ($validEdgeCases as $version) {
            $isValid = $this->semVerFormat->validate($version);
            $this->assertTrue(
                $isValid,
                sprintf('Valid edge case version "%s" should be valid but was marked as invalid', $version)
            );
        }

    }//end testSemVerEdgeCases()


}//end class
