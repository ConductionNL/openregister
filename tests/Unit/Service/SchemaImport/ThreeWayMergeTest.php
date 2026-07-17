<?php

/**
 * Unit tests for ThreeWayMerge — one assertion per merge-table row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\SchemaImport;

use OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge;
use PHPUnit\Framework\TestCase;

class ThreeWayMergeTest extends TestCase
{
    private ThreeWayMerge $merge;


    protected function setUp(): void
    {
        $this->merge = new ThreeWayMerge();
    }


    public function testSourceChangeAppliedWhenLocalUnchanged(): void
    {
        $baseline = ['email' => ['type' => 'string']];
        $current  = ['email' => ['type' => 'string']];
        $incoming = ['email' => ['type' => 'string', 'format' => 'email']];

        $result = $this->merge->compute($baseline, $current, $incoming);

        $this->assertSame(['email'], $result['changed']);
        $this->assertSame('email', $result['merged']['email']['format']);
        $this->assertTrue($result['applied']);
    }


    public function testLocalAdditionKept(): void
    {
        $baseline = ['email' => ['type' => 'string']];
        $current  = ['email' => ['type' => 'string'], 'internalNote' => ['type' => 'string']];
        $incoming = ['email' => ['type' => 'string']];

        $result = $this->merge->compute($baseline, $current, $incoming);

        $this->assertContains('internalNote', $result['keptLocal']);
        $this->assertArrayHasKey('internalNote', $result['merged']);
    }


    public function testLocalModificationKeptWhenSourceUnchanged(): void
    {
        $baseline = ['age' => ['type' => 'integer']];
        $current  = ['age' => ['type' => 'integer', 'minimum' => 0]];
        $incoming = ['age' => ['type' => 'integer']];

        $result = $this->merge->compute($baseline, $current, $incoming);

        $this->assertContains('age', $result['keptLocal']);
        $this->assertSame(0, $result['merged']['age']['minimum']);
    }


    public function testConflictReportedWhenBothChanged(): void
    {
        $baseline = ['age' => ['type' => 'integer']];
        $current  = ['age' => ['type' => 'integer', 'minimum' => 0]];
        $incoming = ['age' => ['type' => 'number']];

        $result = $this->merge->compute($baseline, $current, $incoming);

        $this->assertSame(['age'], $result['conflicts']);
        $this->assertFalse($result['applied']);
        // Not overwritten without confirmation.
        $this->assertSame('integer', $result['merged']['age']['type']);
    }


    public function testConflictAppliedWhenResolved(): void
    {
        $baseline = ['age' => ['type' => 'integer']];
        $current  = ['age' => ['type' => 'integer', 'minimum' => 0]];
        $incoming = ['age' => ['type' => 'number']];

        $result = $this->merge->compute($baseline, $current, $incoming, ['age']);

        $this->assertSame([], $result['conflicts']);
        $this->assertTrue($result['applied']);
        $this->assertSame('number', $result['merged']['age']['type']);
    }


    public function testSourceRemovalReported(): void
    {
        $baseline = ['deprecated' => ['type' => 'string']];
        $current  = ['deprecated' => ['type' => 'string']];
        $incoming = [];

        $result = $this->merge->compute($baseline, $current, $incoming);

        $this->assertSame(['deprecated'], $result['removed']);
    }


    public function testSourceAddition(): void
    {
        $baseline = [];
        $current  = [];
        $incoming = ['newProp' => ['type' => 'string']];

        $result = $this->merge->compute($baseline, $current, $incoming);

        $this->assertSame(['newProp'], $result['added']);
        $this->assertArrayHasKey('newProp', $result['merged']);
    }


    public function testOrderInsensitiveComparison(): void
    {
        $baseline = ['p' => ['type' => 'string', 'format' => 'uri']];
        $current  = ['p' => ['format' => 'uri', 'type' => 'string']];
        $incoming = ['p' => ['type' => 'string', 'format' => 'uri']];

        $result = $this->merge->compute($baseline, $current, $incoming);

        // No change detected despite key ordering differences.
        $this->assertSame([], $result['changed']);
        $this->assertContains('p', $result['keptLocal']);
    }
}
