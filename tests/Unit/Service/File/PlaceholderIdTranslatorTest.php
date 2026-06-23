<?php

/**
 * PlaceholderIdTranslatorTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\PlaceholderIdTranslator}
 * covering per-document first-appearance numbering, per-dossier deterministic
 * recomputation, ranking purity/ordering, and translation keyed on `e.id`
 * (anonymisation-placeholder-id-scope, Decisions 1, 3 & 4).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Service\File\PlaceholderIdTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PlaceholderIdTranslator}.
 */
class PlaceholderIdTranslatorTest extends TestCase
{


    /**
     * Per-document: distinct ids are numbered 1..n by order of first
     * appearance; the same id always resolves to the same number.
     *
     * @return void
     */
    public function testPerDocumentNumbersByFirstAppearance(): void
    {
        $translator = PlaceholderIdTranslator::perDocument();

        $this->assertSame(expected: 1, actual: $translator->translate(entityId: 42));
        $this->assertSame(expected: 2, actual: $translator->translate(entityId: 7));
        // Re-seeing 42 keeps its number (within-document consistency).
        $this->assertSame(expected: 1, actual: $translator->translate(entityId: 42));
        $this->assertSame(expected: 3, actual: $translator->translate(entityId: 99));
        $this->assertSame(expected: 2, actual: $translator->translate(entityId: 7));

    }//end testPerDocumentNumbersByFirstAppearance()


    /**
     * Per-document: two independent runs of the same person yield independent
     * numbers (the counter restarts per run — no cross-run linkage).
     *
     * @return void
     */
    public function testTwoSeparateRunsGetIndependentNumbers(): void
    {
        $runA = PlaceholderIdTranslator::perDocument();
        $runB = PlaceholderIdTranslator::perDocument();

        // In run A the person is seen after two others (number 3); in run B
        // first (number 1). Same e.id, different scope-local number.
        $runA->translate(entityId: 1);
        $runA->translate(entityId: 2);
        $this->assertSame(expected: 3, actual: $runA->translate(entityId: 500));
        $this->assertSame(expected: 1, actual: $runB->translate(entityId: 500));

    }//end testTwoSeparateRunsGetIndependentNumbers()


    /**
     * Ranking core imposes the total order (file_id, position_start,
     * entity_id) and ranks distinct entity_ids by first appearance.
     *
     * @return void
     */
    public function testRankByFirstAppearanceOrdering(): void
    {
        $rows = [
            ['entity_id' => 9, 'file_id' => 2, 'position_start' => 5],
            ['entity_id' => 7, 'file_id' => 1, 'position_start' => 50],
            ['entity_id' => 8, 'file_id' => 1, 'position_start' => 10],
            // entity 7 again, earlier file but later position than its first row
            ['entity_id' => 7, 'file_id' => 1, 'position_start' => 90],
        ];

        // Order: (1,10,8)->8 first, (1,50,7)->7 second, (1,90,7) dup,
        // (2,5,9)->9 third.
        $map = PlaceholderIdTranslator::rankByFirstAppearance(rows: $rows);

        $this->assertSame(
            expected: ['8' => 1, '7' => 2, '9' => 3],
            actual: $map
        );

    }//end testRankByFirstAppearanceOrdering()


    /**
     * Ranking is a pure function of the rows — independent of input order.
     *
     * @return void
     */
    public function testRankByFirstAppearanceIsPureRegardlessOfInputOrder(): void
    {
        $rows = [
            ['entity_id' => 8, 'file_id' => 1, 'position_start' => 10],
            ['entity_id' => 7, 'file_id' => 1, 'position_start' => 50],
            ['entity_id' => 9, 'file_id' => 2, 'position_start' => 5],
        ];

        $expected = PlaceholderIdTranslator::rankByFirstAppearance(rows: $rows);
        $shuffled = array_reverse($rows);

        $this->assertSame(
            expected: $expected,
            actual: PlaceholderIdTranslator::rankByFirstAppearance(rows: $shuffled)
        );

    }//end testRankByFirstAppearanceIsPureRegardlessOfInputOrder()


    /**
     * Translation is keyed on e.id: all value-variants of one entity (which
     * share an entity_id) collapse to the same number.
     *
     * @return void
     */
    public function testTranslationKeyedOnEntityIdVariantsShareNumber(): void
    {
        // Same entity_id (4) appears in three rows (e.g. "Jan", "J. Jansen",
        // "Jansen" — all one catalogue entity).
        $rows = [
            ['entity_id' => 4, 'file_id' => 1, 'position_start' => 1],
            ['entity_id' => 4, 'file_id' => 1, 'position_start' => 20],
            ['entity_id' => 4, 'file_id' => 1, 'position_start' => 40],
            ['entity_id' => 5, 'file_id' => 1, 'position_start' => 30],
        ];

        $translator = PlaceholderIdTranslator::forDossier(rows: $rows);

        $this->assertSame(expected: 1, actual: $translator->translate(entityId: 4));
        $this->assertSame(expected: 2, actual: $translator->translate(entityId: 5));

    }//end testTranslationKeyedOnEntityIdVariantsShareNumber()


    /**
     * Per-dossier: the same e.id resolves to the same number across the
     * dossier's files; idempotent re-runs reproduce identical numbers.
     *
     * @return void
     */
    public function testPerDossierConsistencyAndIdempotency(): void
    {
        $rows = [
            ['entity_id' => 11, 'file_id' => 100, 'position_start' => 4],
            ['entity_id' => 22, 'file_id' => 100, 'position_start' => 80],
            ['entity_id' => 11, 'file_id' => 101, 'position_start' => 2],
            ['entity_id' => 33, 'file_id' => 101, 'position_start' => 9],
        ];

        $first  = PlaceholderIdTranslator::forDossier(rows: $rows);
        $second = PlaceholderIdTranslator::forDossier(rows: $rows);

        // entity 11 first appears in file 100 → number 1, consistent across
        // both files and both recomputations.
        $this->assertSame(expected: 1, actual: $first->translate(entityId: 11));
        $this->assertSame(expected: 2, actual: $first->translate(entityId: 22));
        $this->assertSame(expected: 3, actual: $first->translate(entityId: 33));
        $this->assertSame(expected: 1, actual: $second->translate(entityId: 11));
        $this->assertSame(expected: 3, actual: $second->translate(entityId: 33));

    }//end testPerDossierConsistencyAndIdempotency()


    /**
     * A different dossier (different rows) restarts numbering at 1.
     *
     * @return void
     */
    public function testDifferentDossierRestartsAtOne(): void
    {
        $dossierA = PlaceholderIdTranslator::forDossier(
            rows: [['entity_id' => 11, 'file_id' => 100, 'position_start' => 4]]
        );
        $dossierB = PlaceholderIdTranslator::forDossier(
            rows: [['entity_id' => 999, 'file_id' => 200, 'position_start' => 4]]
        );

        $this->assertSame(expected: 1, actual: $dossierA->translate(entityId: 11));
        $this->assertSame(expected: 1, actual: $dossierB->translate(entityId: 999));

    }//end testDifferentDossierRestartsAtOne()


    /**
     * A seeded translator returns the seeded numbers and assigns the next
     * free number (after the seed maximum) to an id not present in the seed.
     *
     * @return void
     */
    public function testSeededMapContinuesAfterMaxForUnseenId(): void
    {
        $translator = PlaceholderIdTranslator::withSeededMap(seed: [11 => 1, 22 => 2]);

        $this->assertSame(expected: 1, actual: $translator->translate(entityId: 11));
        $this->assertSame(expected: 2, actual: $translator->translate(entityId: 22));
        // Unseen id continues after the max seeded number (2) → 3.
        $this->assertSame(expected: 3, actual: $translator->translate(entityId: 77));

    }//end testSeededMapContinuesAfterMaxForUnseenId()


    /**
     * Empty input yields an empty map / a translator that numbers from 1.
     *
     * @return void
     */
    public function testEmptyRowsProduceEmptyMap(): void
    {
        $this->assertSame(expected: [], actual: PlaceholderIdTranslator::rankByFirstAppearance(rows: []));

        $translator = PlaceholderIdTranslator::forDossier(rows: []);
        $this->assertSame(expected: 1, actual: $translator->translate(entityId: 5));

    }//end testEmptyRowsProduceEmptyMap()


}//end class
