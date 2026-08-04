<?php

/**
 * SegmentMapTest
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Anonymisation;

use OCA\OpenRegister\Service\File\Anonymisation\ReplacementPlanner;
use OCA\OpenRegister\Service\File\Anonymisation\SegmentMap;
use PHPUnit\Framework\TestCase;

/**
 * Covers flatten -> plan -> scatter.
 *
 * The headline case is an entity split across two segments. That is exactly what
 * `<w:r>` run splitting does in docx, and it is unreachable today: the current
 * per-element `str_ireplace` never sees the needle contiguously, so the PII
 * stays in the output verbatim.
 *
 * @category Test
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class SegmentMapTest extends TestCase
{
    /**
     * Flatten, plan, scatter.
     *
     * @param array<int, string>    $segments      Segment values in document order.
     * @param array<string, string> $substitutions Needle => placeholder.
     * @param array<string, string> $types         Needle => entity type.
     *
     * @return array<int, string>
     */
    private function apply(array $segments, array $substitutions, array $types=[]): array
    {
        $map  = new SegmentMap(segments: $segments);
        $plan = (new ReplacementPlanner())->plan(
            text: $map->flatten(),
            substitutions: $substitutions,
            entityTypes: $types
        );

        return $map->scatter(plan: $plan);

    }//end apply()

    /**
     * The concatenation is what the planner sees.
     *
     * @return void
     */
    public function testFlattenConcatenatesInDocumentOrder(): void
    {
        $map = new SegmentMap(segments: ['Jan', ' Jansen', ' belde']);

        $this->assertSame('Jan Jansen belde', $map->flatten());

    }//end testFlattenConcatenatesInDocumentOrder()

    /**
     * An entity split across two segments IS redacted, the placeholder lands in
     * the segment holding the start, and the second segment is left empty but
     * present.
     *
     * @return void
     */
    public function testEntitySplitAcrossTwoSegmentsIsRedacted(): void
    {
        $result = $this->apply(
            segments: ['Jan', ' Jansen', ' belde'],
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertSame(['[PERSOON: 1]', '', ' belde'], $result);
        $this->assertSame('[PERSOON: 1] belde', implode('', $result));
        $this->assertCount(3, $result, 'an emptied segment is retained, never removed');

    }//end testEntitySplitAcrossTwoSegmentsIsRedacted()

    /**
     * A range spanning three segments consumes the middle one entirely.
     *
     * @return void
     */
    public function testRangeSpanningThreeSegmentsConsumesTheMiddle(): void
    {
        $result = $this->apply(
            segments: ['Ja', 'n Jan', 'sen belde'],
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertSame(['[PERSOON: 1]', '', ' belde'], $result);

    }//end testRangeSpanningThreeSegmentsConsumesTheMiddle()

    /**
     * A range wholly inside one segment leaves its neighbours untouched.
     *
     * @return void
     */
    public function testRangeInsideOneSegmentLeavesNeighboursUntouched(): void
    {
        $result = $this->apply(
            segments: ['Beste ', 'Jan Jansen', ', hierbij'],
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertSame(['Beste ', '[PERSOON: 1]', ', hierbij'], $result);

    }//end testRangeInsideOneSegmentLeavesNeighboursUntouched()

    /**
     * Two ranges in one segment are both applied and offsets do not shift.
     *
     * @return void
     */
    public function testTwoRangesInOneSegmentBothApply(): void
    {
        $result = $this->apply(
            segments: ['Jan Jansen en Piet Pieters samen'],
            substitutions: [
                'Jan Jansen'   => '[PERSOON: 1]',
                'Piet Pieters' => '[PERSOON: 2]',
            ],
            types: ['Jan Jansen' => 'PERSON', 'Piet Pieters' => 'PERSON']
        );

        $this->assertSame(['[PERSOON: 1] en [PERSOON: 2] samen'], $result);

    }//end testTwoRangesInOneSegmentBothApply()

    /**
     * A range ending exactly on a segment boundary does not bleed into the next.
     *
     * @return void
     */
    public function testRangeEndingOnSegmentBoundaryDoesNotBleed(): void
    {
        $result = $this->apply(
            segments: ['Jan Jansen', ' belde gisteren'],
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertSame(['[PERSOON: 1]', ' belde gisteren'], $result);

    }//end testRangeEndingOnSegmentBoundaryDoesNotBleed()

    /**
     * Multibyte segments keep correct offsets — codepoints, not bytes.
     *
     * @return void
     */
    public function testMultibyteSegmentsKeepCorrectOffsets(): void
    {
        $result = $this->apply(
            segments: ['Mevrouw Ötzü ', 'schreef aan Anié', 'la over het dossier'],
            substitutions: ['Aniéla' => '[PERSOON: 2]', 'Ötzü' => '[PERSOON: 1]'],
            types: ['Aniéla' => 'PERSON', 'Ötzü' => 'PERSON']
        );

        $joined = implode('', $result);

        $this->assertStringNotContainsString('Ötzü', $joined);
        $this->assertStringNotContainsString('Aniéla', $joined);
        $this->assertSame('Mevrouw [PERSOON: 1] schreef aan [PERSOON: 2] over het dossier', $joined);

    }//end testMultibyteSegmentsKeepCorrectOffsets()

    /**
     * Empty segments in the input are skipped without corrupting offsets.
     *
     * @return void
     */
    public function testEmptySegmentsDoNotCorruptOffsets(): void
    {
        $result = $this->apply(
            segments: ['Jan', '', ' Jansen', '', ' belde'],
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertSame('[PERSOON: 1] belde', implode('', $result));
        $this->assertCount(5, $result);

    }//end testEmptySegmentsDoNotCorruptOffsets()

    /**
     * A plan with no ranges returns the segments unchanged.
     *
     * @return void
     */
    public function testEmptyPlanLeavesSegmentsUnchanged(): void
    {
        $segments = ['Jan', ' Jansen'];
        $result   = $this->apply(segments: $segments, substitutions: []);

        $this->assertSame($segments, $result);

    }//end testEmptyPlanLeavesSegmentsUnchanged()

    /**
     * Text not covered by any range is preserved byte-for-byte.
     *
     * @return void
     */
    public function testUncoveredTextIsPreservedExactly(): void
    {
        $segments = ['Kenmerk 2026-0012: ', 'Jan Jansen, ', 'tel 0612345678.'];
        $result   = $this->apply(
            segments: $segments,
            substitutions: ['Jan Jansen' => '[PERSOON: 1]', '0612345678' => '[TELEFOON: 2]'],
            types: ['Jan Jansen' => 'PERSON', '0612345678' => 'PHONE']
        );

        $joined = implode('', $result);

        $this->assertStringContainsString('Kenmerk 2026-0012: ', $joined, 'the case number must survive intact');
        $this->assertStringContainsString('tel [TELEFOON: 2].', $joined);
        $this->assertSame('Kenmerk 2026-0012: [PERSOON: 1], tel [TELEFOON: 2].', $joined);

    }//end testUncoveredTextIsPreservedExactly()
}//end class
