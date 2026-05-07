<?php

/**
 * Unit tests for OasETagComputer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasETagComputer;
use PHPUnit\Framework\TestCase;

class OasETagComputerTest extends TestCase
{

    private OasETagComputer $computer;


    protected function setUp(): void
    {
        parent::setUp();
        $this->computer = new OasETagComputer();

    }//end setUp()


    public function testETagIsQuotedAndDeterministic(): void
    {
        $spec = ['openapi' => '3.1.0', 'info' => ['title' => 'OR']];
        $etag = $this->computer->computeETag(oas: $spec);

        $this->assertStringStartsWith('"', $etag);
        $this->assertStringEndsWith('"', $etag);
        $this->assertSame($etag, $this->computer->computeETag(oas: $spec));

    }//end testETagIsQuotedAndDeterministic()


    public function testStructurallyEquivalentSpecsHashIdentically(): void
    {
        $a = ['openapi' => '3.1.0', 'info' => ['title' => 'OR', 'version' => '1']];
        // Same content, different key order — MUST hash identically.
        $b = ['info' => ['version' => '1', 'title' => 'OR'], 'openapi' => '3.1.0'];

        $this->assertSame(
            $this->computer->hash(spec: $a),
            $this->computer->hash(spec: $b),
            'key-order differences MUST NOT change the OAS hash'
        );

    }//end testStructurallyEquivalentSpecsHashIdentically()


    public function testListOrderIsPreserved(): void
    {
        // Lists are NOT sorted — their order is part of the contract.
        $a = ['paths' => ['/a', '/b']];
        $b = ['paths' => ['/b', '/a']];

        $this->assertNotSame(
            $this->computer->hash(spec: $a),
            $this->computer->hash(spec: $b),
            'list-order changes MUST change the hash'
        );

    }//end testListOrderIsPreserved()


    public function testIfNoneMatchWildcardMatchesAnyETag(): void
    {
        $current = '"abc123"';
        $this->assertTrue($this->computer->matches(ifNoneMatch: '*', currentETag: $current));

    }//end testIfNoneMatchWildcardMatchesAnyETag()


    public function testIfNoneMatchSingleETagMatch(): void
    {
        $current = '"abc123"';
        $this->assertTrue($this->computer->matches(ifNoneMatch: '"abc123"', currentETag: $current));
        $this->assertFalse($this->computer->matches(ifNoneMatch: '"def456"', currentETag: $current));

    }//end testIfNoneMatchSingleETagMatch()


    public function testIfNoneMatchListMatch(): void
    {
        $current = '"abc123"';
        $this->assertTrue($this->computer->matches(ifNoneMatch: '"x", "abc123", "y"', currentETag: $current));
        $this->assertFalse($this->computer->matches(ifNoneMatch: '"x", "y"', currentETag: $current));

    }//end testIfNoneMatchListMatch()


    public function testIfNoneMatchAcceptsWeakETagPrefix(): void
    {
        $current = '"abc123"';
        $this->assertTrue($this->computer->matches(ifNoneMatch: 'W/"abc123"', currentETag: $current));

    }//end testIfNoneMatchAcceptsWeakETagPrefix()


    public function testIfNoneMatchEmptyDoesNotMatch(): void
    {
        $this->assertFalse($this->computer->matches(ifNoneMatch: '', currentETag: '"abc"'));
        $this->assertFalse($this->computer->matches(ifNoneMatch: '   ', currentETag: '"abc"'));

    }//end testIfNoneMatchEmptyDoesNotMatch()


}//end class
