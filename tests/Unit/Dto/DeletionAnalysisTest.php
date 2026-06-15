<?php

/**
 * DeletionAnalysis DTO Unit Tests
 *
 * Tests construction, default values, and the empty() factory method.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-legacy-quality-cleanup/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace Unit\Dto;

use OCA\OpenRegister\Dto\DeletionAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DeletionAnalysis value object.
 */
class DeletionAnalysisTest extends TestCase
{
    /**
     * Full construction sets all properties.
     *
     * @return void
     */
    public function testFullConstructionSetsAllProperties(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [['uuid' => 'a']],
            nullifyTargets: [['uuid' => 'b']],
            defaultTargets: [['uuid' => 'c']],
            blockers: [],
            chainPaths: [['a' => 'b']]
        );

        $this->assertTrue(condition: $analysis->deletable);
        $this->assertSame(expected: [['uuid' => 'a']], actual: $analysis->cascadeTargets);
        $this->assertSame(expected: [['uuid' => 'b']], actual: $analysis->nullifyTargets);
        $this->assertSame(expected: [['uuid' => 'c']], actual: $analysis->defaultTargets);
        $this->assertSame(expected: [], actual: $analysis->blockers);
        $this->assertSame(expected: [['a' => 'b']], actual: $analysis->chainPaths);

    }//end testFullConstructionSetsAllProperties()

    /**
     * Blocked object is not deletable.
     *
     * @return void
     */
    public function testBlockedObjectIsNotDeletable(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            cascadeTargets: [],
            nullifyTargets: [],
            defaultTargets: [],
            blockers: [['objectUuid' => 'xyz', 'schema' => 'foo']]
        );

        $this->assertFalse(condition: $analysis->deletable);
        $this->assertCount(expectedCount: 1, haystack: $analysis->blockers);

    }//end testBlockedObjectIsNotDeletable()

    /**
     * Empty factory returns deletable analysis with no targets.
     *
     * @return void
     */
    public function testEmptyFactoryReturnsDeletableWithNoTargets(): void
    {
        $empty = DeletionAnalysis::empty();

        $this->assertTrue(condition: $empty->deletable);
        $this->assertSame(expected: [], actual: $empty->cascadeTargets);
        $this->assertSame(expected: [], actual: $empty->nullifyTargets);
        $this->assertSame(expected: [], actual: $empty->defaultTargets);
        $this->assertSame(expected: [], actual: $empty->blockers);
        $this->assertSame(expected: [], actual: $empty->chainPaths);

    }//end testEmptyFactoryReturnsDeletableWithNoTargets()

    /**
     * Chain paths defaults to empty array when not provided.
     *
     * @return void
     */
    public function testChainPathsDefaultsToEmptyArray(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [],
            nullifyTargets: [],
            defaultTargets: [],
            blockers: []
        );

        $this->assertSame(expected: [], actual: $analysis->chainPaths);

    }//end testChainPathsDefaultsToEmptyArray()
}//end class
