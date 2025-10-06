<?php

declare(strict_types=1);

/**
 * SemVerFormatTest
 *
 * Comprehensive unit tests for the SemVerFormat class to verify semantic version
 * validation functionality according to the SemVer specification.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Formats
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenRegister/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Formats;

use OCA\OpenRegister\Formats\SemVerFormat;
use PHPUnit\Framework\TestCase;

/**
 * SemVer Format Test Suite
 *
 * Comprehensive unit tests for semantic version format validation including
 * valid versions, invalid versions, and edge cases.
 *
 * @coversDefaultClass SemVerFormat
 */
class SemVerFormatTest extends TestCase
{
    private SemVerFormat $semVerFormat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semVerFormat = new SemVerFormat();
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(SemVerFormat::class, $this->semVerFormat);
    }

    /**
     * Test validate with valid basic versions
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithValidBasicVersions(): void
    {
        $validVersions = [
            '1.0.0',
            '0.1.0',
            '10.20.30',
            '999.999.999',
            '0.0.0',
            '1.2.3',
            '42.0.1'
        ];

        foreach ($validVersions as $version) {
            $this->assertTrue(
                $this->semVerFormat->validate($version),
                "Version '{$version}' should be valid"
            );
        }
    }

    /**
     * Test validate with valid versions including prerelease
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithValidPrereleaseVersions(): void
    {
        $validPrereleaseVersions = [
            '1.0.0-alpha',
            '1.0.0-alpha.1',
            '1.0.0-0.3.7',
            '1.0.0-x.7.z.92',
            '1.0.0-beta',
            '1.0.0-rc.1',
            '1.0.0-alpha.beta',
            '1.0.0-1.2.3',
            '1.0.0-1.2.3.4.5.6.7.8.9.0'
        ];

        foreach ($validPrereleaseVersions as $version) {
            $this->assertTrue(
                $this->semVerFormat->validate($version),
                "Prerelease version '{$version}' should be valid"
            );
        }
    }

    /**
     * Test validate with valid versions including build metadata
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithValidBuildVersions(): void
    {
        $validBuildVersions = [
            '1.0.0+20130313144700',
            '1.0.0+21AF26D3-117B344092BD',
            '1.0.0+exp.sha.5114f85',
            '1.0.0+001',
            '1.0.0+20130313144700.123'
        ];

        foreach ($validBuildVersions as $version) {
            $this->assertTrue(
                $this->semVerFormat->validate($version),
                "Build version '{$version}' should be valid"
            );
        }
    }

    /**
     * Test validate with valid versions including both prerelease and build
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithValidPrereleaseAndBuildVersions(): void
    {
        $validCombinedVersions = [
            '1.0.0-alpha+001',
            '1.0.0-beta+exp.sha.5114f85',
            '1.0.0-rc.1+20130313144700',
            '1.0.0-alpha.1+21AF26D3-117B344092BD'
        ];

        foreach ($validCombinedVersions as $version) {
            $this->assertTrue(
                $this->semVerFormat->validate($version),
                "Combined version '{$version}' should be valid"
            );
        }
    }

    /**
     * Test validate with invalid versions
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithInvalidVersions(): void
    {
        $invalidVersions = [
            '1.0',                    // Missing patch version
            '1',                      // Missing minor and patch
            '1.0.0.0',               // Too many version numbers
            '1.0.0.',                // Trailing dot
            '.1.0.0',                // Leading dot
            '1.0.0-',                // Trailing hyphen in prerelease
            '1.0.0+',                // Trailing plus in build
            '1.0.0-+',               // Empty prerelease and build
            '01.0.0',                // Leading zero in major
            '1.00.0',                // Leading zero in minor
            '1.0.01',                // Leading zero in patch
            '1.0.0-01',              // Leading zero in prerelease
            '1.0.0-alpha..beta',     // Double dot in prerelease
            '1.0.0-alpha.',          // Trailing dot in prerelease
            '1.0.0-.alpha',          // Leading dot in prerelease
            '1.0.0+exp..sha',        // Double dot in build
            '1.0.0+exp.',            // Trailing dot in build
            '1.0.0+.exp',            // Leading dot in build
            '1.0.0-',                // Empty prerelease
            '1.0.0+',                // Empty build
            'v1.0.0',                // Version prefix
            '1.0.0-alpha beta',      // Space in prerelease
            '1.0.0+exp sha',         // Space in build
            '',                      // Empty string
            'not-a-version',         // Not a version
            '1.0.0-',                // Trailing hyphen
            '1.0.0+',                // Trailing plus
        ];

        foreach ($invalidVersions as $version) {
            $this->assertFalse(
                $this->semVerFormat->validate($version),
                "Version '{$version}' should be invalid"
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
            123,
            1.23,
            true,
            false,
            [],
            new \stdClass(),
            function() { return '1.0.0'; }
        ];

        foreach ($nonStringData as $data) {
            $this->assertFalse(
                $this->semVerFormat->validate($data),
                "Non-string data should be invalid"
            );
        }
    }

    /**
     * Test validate with edge case versions
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithEdgeCaseVersions(): void
    {
        $edgeCases = [
            '0.0.0' => true,                    // Minimum valid version
            '999.999.999' => true,              // Large version numbers
            '1.0.0-a' => true,                  // Single character prerelease
            '1.0.0-a.b.c' => true,              // Multiple prerelease identifiers
            '1.0.0+123' => true,                // Numeric build metadata
            '1.0.0+abc-def' => true,            // Build metadata with hyphen
            '1.0.0-alpha.1.beta.2' => true,    // Complex prerelease
            '1.0.0+20130313144700' => true,     // Timestamp build
            '1.0.0-alpha+20130313144700' => true, // Prerelease with timestamp build
        ];

        foreach ($edgeCases as $version => $expected) {
            $this->assertEquals(
                $expected,
                $this->semVerFormat->validate($version),
                "Edge case version '{$version}' validation failed"
            );
        }
    }

    /**
     * Test validate with very long versions
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithLongVersions(): void
    {
        // Very long prerelease identifier
        $longPrerelease = '1.0.0-' . str_repeat('a', 100);
        $this->assertTrue(
            $this->semVerFormat->validate($longPrerelease),
            "Long prerelease version should be valid"
        );

        // Very long build metadata
        $longBuild = '1.0.0+' . str_repeat('a', 100);
        $this->assertTrue(
            $this->semVerFormat->validate($longBuild),
            "Long build version should be valid"
        );

        // Very long combined version
        $longCombined = '1.0.0-' . str_repeat('a', 50) . '+' . str_repeat('b', 50);
        $this->assertTrue(
            $this->semVerFormat->validate($longCombined),
            "Long combined version should be valid"
        );
    }

    /**
     * Test validate with special characters in prerelease
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithSpecialCharactersInPrerelease(): void
    {
        $specialCharVersions = [
            '1.0.0-alpha-1' => true,           // Hyphen in prerelease
            '1.0.0-alpha.1' => true,           // Dot in prerelease
            '1.0.0-alpha1' => true,            // Alphanumeric
            '1.0.0-alpha-1-beta-2' => true,    // Multiple hyphens
            '1.0.0-alpha.1.beta.2' => true,    // Multiple dots
        ];

        foreach ($specialCharVersions as $version => $expected) {
            $this->assertEquals(
                $expected,
                $this->semVerFormat->validate($version),
                "Special character version '{$version}' validation failed"
            );
        }
    }

    /**
     * Test validate with special characters in build metadata
     *
     * @covers ::validate
     * @return void
     */
    public function testValidateWithSpecialCharactersInBuild(): void
    {
        $specialCharVersions = [
            '1.0.0+abc-def' => true,           // Hyphen in build
            '1.0.0+abc.def' => true,           // Dot in build
            '1.0.0+abc123' => true,            // Alphanumeric
            '1.0.0+abc-def.ghi-jkl' => true,   // Multiple hyphens and dots
        ];

        foreach ($specialCharVersions as $version => $expected) {
            $this->assertEquals(
                $expected,
                $this->semVerFormat->validate($version),
                "Special character build version '{$version}' validation failed"
            );
        }
    }
}