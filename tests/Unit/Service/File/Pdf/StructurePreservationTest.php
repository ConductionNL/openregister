<?php

/**
 * StructurePreservationTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\Pdf\StructurePreservation}
 * — the `structurePreservation` result contract's field-set (design.md D2).
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File\Pdf;

use OCA\OpenRegister\Service\File\Pdf\StructurePreservation;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see StructurePreservation}.
 */
class StructurePreservationTest extends TestCase
{

    /**
     * `jsonSerialize()` emits exactly the five contracted keys, with the
     * documented types, and no additional or renamed field (REQ-ORTPR-003).
     *
     * @return void
     */
    public function testJsonSerializeFieldSet(): void
    {
        $preservation = new StructurePreservation(
            requested: true,
            preserved: false,
            tagCountBefore: 42,
            tagCountAfter: 42,
            lossReasons: [StructurePreservation::LOSS_REASON_MARKED_CONTENT_BROKEN]
        );

        $serialised = $preservation->jsonSerialize();

        self::assertSame(
            expected: ['requested', 'preserved', 'tagCountBefore', 'tagCountAfter', 'lossReasons'],
            actual: array_keys($serialised)
        );

        self::assertIsBool($serialised['requested']);
        self::assertIsBool($serialised['preserved']);
        self::assertIsInt($serialised['tagCountBefore']);
        self::assertIsInt($serialised['tagCountAfter']);
        self::assertIsArray($serialised['lossReasons']);

        self::assertSame(
            expected: [
                'requested'      => true,
                'preserved'      => false,
                'tagCountBefore' => 42,
                'tagCountAfter'  => 42,
                'lossReasons'    => ['marked-content-correspondence-broken'],
            ],
            actual: $serialised
        );
    }//end testJsonSerializeFieldSet()

    /**
     * `lossReasons` is empty by default (no fifth positional arg required).
     *
     * @return void
     */
    public function testLossReasonsDefaultsToEmptyArray(): void
    {
        $preservation = new StructurePreservation(
            requested: true,
            preserved: true,
            tagCountBefore: 10,
            tagCountAfter: 10
        );

        self::assertSame(expected: [], actual: $preservation->lossReasons);
        self::assertSame(expected: [], actual: $preservation->jsonSerialize()['lossReasons']);
    }//end testLossReasonsDefaultsToEmptyArray()

    /**
     * The enumerated loss-reason set (design.md D2) is stable and contains
     * exactly the five documented reason strings.
     *
     * @return void
     */
    public function testEnumeratedLossReasonsMatchDesignDocument(): void
    {
        self::assertSame(
            expected: [
                'engine-cannot-reauthor-structtree',
                'marked-content-correspondence-broken',
                'structtreeroot-dropped-on-rebuild',
                'input-not-tagged',
                'page-structure-not-preservable',
            ],
            actual: StructurePreservation::LOSS_REASONS
        );
    }//end testEnumeratedLossReasonsMatchDesignDocument()
}//end class
